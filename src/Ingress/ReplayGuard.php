<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Ingress;

use Doctrine\DBAL\Connection;
use LogicException;
use ReflectionException;
use ReflectionProperty;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\CombinedStore;
use Symfony\Component\Lock\Store\DoctrineDbalStore;
use Symfony\Component\Lock\Store\MemcachedStore;
use Symfony\Component\Lock\Store\MongoDbStore;
use Symfony\Component\Lock\Store\PdoStore;
use Symfony\Component\Lock\Store\RedisStore;

use function hash;
use function is_array;
use function property_exists;
use function str_contains;
use function str_starts_with;

/**
 * Replay protection (contract §5.3, audit FS-L1): a verified signature is claimed **atomically** with a
 * `symfony/lock` key that is never released and expires after twice the time window, so a second use is refused
 * even when two copies arrive at once (Redis `SET NX PX`, DBAL / PDO primary-key insert). Events API deliveries are
 * claimed by `event_id` for an hour; the claim is released when handling fails, so Slack's retries go through.
 *
 * The key is the signature alone (not the app), so apps that share a signing secret cannot replay a request once per
 * `{app}` route (audit FS-I2).
 */
final readonly class ReplayGuard
{
    /** How long an `event_id` is remembered. */
    public const EVENT_TTL = 3600;

    /**
     * Allowlist of shared stores whose locks expire by TTL (audit FSD-L1). Session-scoped stores (PostgreSQL advisory
     * locks, ZooKeeper ephemeral nodes) and local ones (flock, semaphore, in-memory, null) are refused.
     */
    public const EXPIRING_STORES = [
        RedisStore::class,
        MemcachedStore::class,
        PdoStore::class,
        DoctrineDbalStore::class,
        MongoDbStore::class,
        'Symfony\\Component\\Lock\\Bridge\\DynamoDb\\DynamoDbStore',
    ];

    /** `StoreFactory` DSN prefixes that build an expiring shared store (checked after the refused markers). */
    private const EXPIRING_DSN_PREFIXES = [
        'redis:', 'rediss:', 'valkey:', 'valkeys:', 'memcached:', 'mongodb', 'dynamodb://',
        'mssql://', 'mysql://', 'mysql2://', 'oci8://', 'pdo_oci://', 'pgsql://', 'postgres://', 'postgresql://', 'sqlite://', 'sqlite3://',
        'mysql:', 'oci:', 'pgsql:', 'sqlsrv:', 'sqlite:',
    ];

    /**
     * @param LockFactory $locks lock factory (`ingress.replay_lock_factory`; Redis or DBAL store in production)
     * @param int $maxSkewSeconds `ingress.max_skew_seconds`
     * @param PersistingStoreInterface|null $store the factory's store, when known (refused unless
     *                                             {@see self::isSharedStore()} when `$requireSharedStore`)
     * @param bool $requireSharedStore true outside debug and test: a store off the allowlist fails closed
     */
    public function __construct(
        private LockFactory $locks,
        private int $maxSkewSeconds = SignatureVerifier::MAX_SKEW_SECONDS,
        private ?PersistingStoreInterface $store = null,
        private bool $requireSharedStore = false,
    ) {
    }

    /**
     * True the first time this signature is seen.
     *
     * @throws LogicException when the store is off the allowlist and a shared one is required
     * @throws LockAcquiringException when the lock store cannot be reached (callers fail closed)
     */
    public function claimSignature(string $signature): bool
    {
        // +1 (audit UR-3): the window check compares whole seconds, so the last accepted second is still covered.
        return $this->claim('sig', $signature, 2 * $this->maxSkewSeconds + 1) instanceof LockInterface;
    }

    /**
     * The claim of this `event_id`, or null when it was already claimed. Release it when handling fails.
     *
     * @throws LogicException when the store is off the allowlist and a shared one is required
     * @throws LockAcquiringException when the lock store cannot be reached (callers fail closed)
     */
    public function claimEvent(string $eventId): ?LockInterface
    {
        return $this->claim('evt', $eventId, self::EVENT_TTL);
    }

    /**
     * Whether the store is an allowed expiring shared store; a {@see CombinedStore} only when every member is.
     */
    public static function isSharedStore(?object $store): bool
    {
        if ($store instanceof CombinedStore) {
            $members = (new ReflectionProperty(CombinedStore::class, 'stores'))->getValue($store);
            if (!is_array($members) || $members === []) {
                return false;
            }
            foreach ($members as $member) {
                if (!self::isSharedStore($member)) {
                    return false;
                }
            }

            return true;
        }
        foreach (self::EXPIRING_STORES as $class) {
            if ($store instanceof $class) {
                return !self::isInMemoryDatabase($store);
            }
        }

        return false;
    }

    /**
     * Whether a PDO / Doctrine DBAL store points at an in-memory SQLite database (audit UR-2), as far as it can be
     * told without a query: the PDO store's DSN, or the DBAL connection's parameters.
     */
    private static function isInMemoryDatabase(object $store): bool
    {
        if ($store instanceof PdoStore) {
            return str_contains((string) self::readStoreProperty(PdoStore::class, 'dsn', $store), ':memory:');
        }
        $connection = $store instanceof DoctrineDbalStore ? self::readStoreProperty(DoctrineDbalStore::class, 'conn', $store) : null;
        $params     = $connection instanceof Connection ? $connection->getParams() : [];

        return ($params['memory'] ?? false) === true || ($params['path'] ?? null) === ':memory:' || str_contains((string) ($params['url'] ?? ''), ':memory:');
    }

    /**
     * Reads a private property of a Symfony Lock store. Returns null ("cannot tell", so the store is trusted as
     * configured; the build-time DSN check still refuses `:memory:` DSNs) when the property is missing, renamed or
     * not initialised, so a Symfony refactor can never break a Slack request.
     *
     * @param class-string $class
     */
    private static function readStoreProperty(string $class, string $property, object $store): mixed
    {
        if (!property_exists($class, $property)) {
            return null;
        }
        try {
            $reflection = new ReflectionProperty($class, $property);

            return $reflection->isInitialized($store) ? $reflection->getValue($store) : null;
        } catch (ReflectionException) {
            return null;
        }
    }

    /**
     * Whether a lock DSN builds an allowed expiring shared store (`+advisory` and `zookeeper` DSNs never do).
     */
    public static function isSharedDsn(string $dsn): bool
    {
        // Audit UR-2: an in-memory SQLite database lives in one process, so it is a local store whatever its driver.
        if (str_contains($dsn, '+advisory') || str_starts_with($dsn, 'zookeeper') || str_contains($dsn, ':memory:')) {
            return false;
        }
        foreach (self::EXPIRING_DSN_PREFIXES as $prefix) {
            if (str_starts_with($dsn, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function claim(string $kind, string $value, int $ttl): ?LockInterface
    {
        if ($this->requireSharedStore && $this->store instanceof PersistingStoreInterface && !self::isSharedStore($this->store)) {
            throw new LogicException('NowoSlackKitBundle: the replay lock store is not an expiring shared store (Redis, Memcached, PDO, Doctrine DBAL, MongoDB, DynamoDB); point nowo_slack_kit.ingress.replay_lock_factory at one.');
        }
        $lock = $this->locks->createLock('nowo_slack_kit.replay.' . $kind . '.' . hash('sha256', $value), $ttl, false);

        return $lock->acquire(false) ? $lock : null;
    }
}
