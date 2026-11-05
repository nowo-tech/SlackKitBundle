<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Api;

use Psr\Cache\CacheItemPoolInterface;

use function hash;
use function is_int;
use function max;

/**
 * What the client remembers across requests and workers, in a PSR-6 pool: a 429 per (installation, method) until its
 * `Retry-After` passes (no retry storms), and when an unhealthy installation was last reported.
 */
final readonly class ClientMemory
{
    /** Seconds between two unhealthy reports of one installation. */
    public const UNHEALTHY_REPORT_INTERVAL = 600;

    /**
     * @param CacheItemPoolInterface $pool shared cache pool
     */
    public function __construct(
        private CacheItemPoolInterface $pool,
    ) {
    }

    /**
     * Seconds left on a remembered rate limit, or 0.
     */
    public function rateLimitedFor(string $installation, string $method, int $now): int
    {
        $item  = $this->pool->getItem($this->key('rl', $installation . '|' . $method));
        $until = $item->isHit() ? $item->get() : null;

        return is_int($until) && $until > $now ? $until - $now : 0;
    }

    /**
     * Remembers a 429 until `$now + $seconds`.
     */
    public function rememberRateLimit(string $installation, string $method, int $now, int $seconds): void
    {
        $item = $this->pool->getItem($this->key('rl', $installation . '|' . $method));
        $item->set($now + $seconds)->expiresAfter(max(1, $seconds));
        $this->pool->save($item);
    }

    /**
     * True once per {@see self::UNHEALTHY_REPORT_INTERVAL} per installation.
     */
    public function shouldReportUnhealthy(string $installation): bool
    {
        $item = $this->pool->getItem($this->key('uh', $installation));
        if ($item->isHit()) {
            return false;
        }
        $item->set(true)->expiresAfter(self::UNHEALTHY_REPORT_INTERVAL);
        $this->pool->save($item);

        return true;
    }

    private function key(string $kind, string $value): string
    {
        return 'nowo_slack_kit.' . $kind . '.' . hash('xxh128', $value);
    }
}
