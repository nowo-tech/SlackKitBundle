<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Install;

use InvalidArgumentException;
use Nowo\SlackKitBundle\Credentials\SlackOwnerScope;
use Nowo\SlackKitBundle\Interaction\ButtonValueCodec;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Psr\Clock\ClockInterface;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Lock\LockFactory;

use function array_slice;
use function count;
use function explode;
use function hash;
use function hash_equals;
use function hash_hkdf;
use function hash_hmac;
use function is_array;
use function is_int;
use function is_string;
use function random_bytes;
use function uasort;

/**
 * The `state` of "Add to Slack" (spec 237 FR-003): a random nonce MAC-signed with a key derived from `kernel.secret`,
 * whose record (app, owner scope, account hash, expiry) lives in the session, server side. It is single-use twice
 * over: the record leaves the session on first use, and a `symfony/lock` claim on the nonce refuses a second use from
 * another process. At most {@see self::MAX_PENDING} pending installs per session.
 *
 * @internal used by {@see InstallController}; not a public API
 */
final readonly class InstallStateStore
{
    /** Session attribute of the pending installs. */
    public const SESSION_KEY = 'nowo_slack_kit.install_states';

    /** Pending installs kept per session. */
    public const MAX_PENDING = 5;

    /** Largest state lifetime (10 minutes). */
    public const MAX_TTL = 600;

    /** HKDF info of the state key. */
    private const KEY_INFO = 'nowo_slack_kit.install_state.v1';

    /**
     * @param SlackSecret $key state key ({@see self::deriveKey()})
     * @param LockFactory $locks lock factory of the single-use claim (`ingress.replay_lock_factory`)
     * @param ClockInterface $clock clock
     * @param int $ttl state lifetime in seconds (at most {@see self::MAX_TTL})
     */
    public function __construct(
        private SlackSecret $key,
        private LockFactory $locks,
        private ClockInterface $clock,
        private int $ttl = self::MAX_TTL,
    ) {
    }

    /**
     * The state key derived from `kernel.secret`.
     */
    public static function deriveKey(#[SensitiveParameter] string $kernelSecret): SlackSecret
    {
        return SlackSecret::key(hash_hkdf('sha256', $kernelSecret, 32, self::KEY_INFO, ''));
    }

    /**
     * Records a pending install in the session and returns its `state`.
     */
    public function issue(SessionInterface $session, string $app, SlackOwnerScope $scope, string $accountId): string
    {
        $now     = $this->clock->now()->getTimestamp();
        $nonce   = ButtonValueCodec::base64UrlEncode(random_bytes(32));
        $pending = [];
        foreach ($this->records($session) as $id => $record) {
            if ($record['exp'] > $now) {
                $pending[$id] = $record;
            }
        }
        $pending[hash('sha256', $nonce)] = ['app' => $app, 'type' => $scope->type, 'id' => $scope->id, 'account' => hash('sha256', $accountId), 'exp' => $now + $this->ttl];
        uasort($pending, static fn (array $a, array $b): int => $a['exp'] <=> $b['exp']);
        $session->set(self::SESSION_KEY, array_slice($pending, -self::MAX_PENDING, null, true));

        return $nonce . '.' . $this->mac($nonce);
    }

    /**
     * Consumes a `state`: the app and owner scope of the pending install, or null when the state is malformed, forged,
     * unknown to this session, expired, issued to another account or already used.
     *
     * @return array{app: string, scope: SlackOwnerScope}|null
     */
    public function consume(SessionInterface $session, string $state, string $accountId): ?array
    {
        $parts = explode('.', $state);
        if (count($parts) !== 2 || ButtonValueCodec::base64UrlDecode($parts[0]) === null || !hash_equals($this->mac($parts[0]), $parts[1])) {
            return null;
        }
        $id      = hash('sha256', $parts[0]);
        $records = $this->records($session);
        $record  = $records[$id] ?? null;
        unset($records[$id]);
        $session->set(self::SESSION_KEY, $records);

        if ($record === null || $record['exp'] <= $this->clock->now()->getTimestamp() || !hash_equals($record['account'], hash('sha256', $accountId))) {
            return null;
        }
        if (!$this->locks->createLock('nowo_slack_kit.install_state.' . $id, $this->ttl, false)->acquire(false)) {
            return null;
        }
        try {
            return ['app' => $record['app'], 'scope' => new SlackOwnerScope($record['type'], $record['id'])];
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function mac(string $nonce): string
    {
        return ButtonValueCodec::base64UrlEncode(hash_hmac('sha256', $nonce, $this->key->reveal(), true));
    }

    /**
     * @return array<string, array{app: string, type: string, id: string, account: string, exp: int}>
     */
    private function records(SessionInterface $session): array
    {
        $raw     = $session->get(self::SESSION_KEY);
        $records = [];
        foreach (is_array($raw) ? $raw : [] as $id => $record) {
            if (is_string($id) && is_array($record) && is_string($record['app'] ?? null) && is_string($record['type'] ?? null) && is_string($record['id'] ?? null)
                && is_string($record['account'] ?? null) && is_int($record['exp'] ?? null)) {
                $records[$id] = ['app' => $record['app'], 'type' => $record['type'], 'id' => $record['id'], 'account' => $record['account'], 'exp' => $record['exp']];
            }
        }

        return $records;
    }
}
