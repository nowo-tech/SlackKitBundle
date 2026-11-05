<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Ingress;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function hash;

/**
 * Replay protection (contract §5.3): a verified signature is remembered for twice the time window and refused the
 * second time; Events API deliveries are also de-duplicated by `event_id` for an hour (Slack retries with a new
 * signature). Check-then-set on the PSR-6 pool: atomic enough on shared pools, best effort on others (documented).
 */
final readonly class ReplayGuard
{
    /** How long an `event_id` is remembered. */
    public const EVENT_TTL = 3600;

    /**
     * @param CacheItemPoolInterface $pool shared cache pool (`ingress.replay_cache_pool`)
     * @param int $maxSkewSeconds `ingress.max_skew_seconds`
     * @param string $environment kernel environment (a per-process array pool in `prod` is logged as a warning)
     * @param LoggerInterface $logger PSR-3 logger
     */
    public function __construct(
        private CacheItemPoolInterface $pool,
        private int $maxSkewSeconds = SignatureVerifier::MAX_SKEW_SECONDS,
        private string $environment = 'prod',
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * True the first time this signature is seen for this verifier label.
     */
    public function claimSignature(string $label, string $signature): bool
    {
        return $this->claim('sig', $label . '|' . $signature, 2 * $this->maxSkewSeconds);
    }

    /**
     * True the first time this `event_id` is seen.
     */
    public function claimEvent(string $eventId): bool
    {
        return $this->claim('evt', $eventId, self::EVENT_TTL);
    }

    private function claim(string $kind, string $value, int $ttl): bool
    {
        if ($this->environment === 'prod' && $this->pool instanceof ArrayAdapter) {
            $this->logger->warning('Slack replay memory uses an in-process array pool: set nowo_slack_kit.ingress.replay_cache_pool to a shared pool.', ['reason' => 'array_pool']);
        }
        $item = $this->pool->getItem('nowo_slack_kit.replay.' . $kind . '.' . hash('sha256', $value));
        if ($item->isHit()) {
            return false;
        }
        $item->set(1)->expiresAfter($ttl);
        $this->pool->save($item);

        return true;
    }
}
