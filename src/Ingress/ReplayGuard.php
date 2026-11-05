<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Ingress;

use LogicException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Lock\Store\NullStore;
use Symfony\Component\Lock\Store\SemaphoreStore;

use function hash;

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

    /** Lock stores that only protect one process or one host. */
    public const LOCAL_STORES = [InMemoryStore::class, FlockStore::class, SemaphoreStore::class, NullStore::class];

    /**
     * @param LockFactory $locks lock factory (`ingress.replay_lock_factory`; Redis or DBAL store in production)
     * @param int $maxSkewSeconds `ingress.max_skew_seconds`
     * @param PersistingStoreInterface|null $store the factory's store, when known (refused when local and
     *                                             `$requireSharedStore`)
     * @param bool $requireSharedStore true outside debug and test: a local store fails closed
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
     * @throws LogicException when the store is local and a shared one is required
     */
    public function claimSignature(string $signature): bool
    {
        return $this->claim('sig', $signature, 2 * $this->maxSkewSeconds) instanceof LockInterface;
    }

    /**
     * The claim of this `event_id`, or null when it was already claimed. Release it when handling fails.
     *
     * @throws LogicException when the store is local and a shared one is required
     */
    public function claimEvent(string $eventId): ?LockInterface
    {
        return $this->claim('evt', $eventId, self::EVENT_TTL);
    }

    /**
     * Whether the store only protects one process or one host.
     */
    public static function isLocalStore(?object $store): bool
    {
        foreach (self::LOCAL_STORES as $class) {
            if ($store instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function claim(string $kind, string $value, int $ttl): ?LockInterface
    {
        if ($this->requireSharedStore && self::isLocalStore($this->store)) {
            throw new LogicException('NowoSlackKitBundle: the replay lock store is local to one process or host; configure framework.lock (or ingress.replay_lock_factory) with a Redis or DBAL store.');
        }
        $lock = $this->locks->createLock('nowo_slack_kit.replay.' . $kind . '.' . hash('sha256', $value), $ttl, false);

        return $lock->acquire(false) ? $lock : null;
    }
}
