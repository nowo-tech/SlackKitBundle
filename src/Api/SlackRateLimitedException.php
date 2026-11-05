<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Api;

use function max;

/**
 * HTTP 429 or `ratelimited`: try again after {@see self::$retryAfterSeconds}. Never slept on (contract §4.4).
 */
final class SlackRateLimitedException extends SlackApiException implements RecoverableSlackException
{
    /** Seconds Slack asked to wait (clamped to 1…`rate_limit.max_retry_after`). */
    public readonly int $retryAfterSeconds;

    /**
     * @param int $retryAfterSeconds seconds to wait (at least 1)
     */
    public function __construct(int $retryAfterSeconds)
    {
        $this->retryAfterSeconds = max(1, $retryAfterSeconds);
        parent::__construct('ratelimited');
    }

    /**
     * {@see self::$retryAfterSeconds}.
     */
    public function retryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
