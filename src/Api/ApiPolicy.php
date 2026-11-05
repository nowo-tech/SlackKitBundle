<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Api;

/**
 * Transport limits of every Slack call (`nowo_slack_kit.api.*`, `rate_limit.max_retry_after`).
 */
final readonly class ApiPolicy
{
    /**
     * @param float $timeout idle timeout in seconds
     * @param float $maxDuration total duration cap in seconds
     * @param int $maxResponseBytes larger answers are aborted
     * @param int $maxRetryAfter largest `Retry-After` honoured, in seconds
     */
    public function __construct(
        public float $timeout = 5.0,
        public float $maxDuration = 10.0,
        public int $maxResponseBytes = 1_048_576,
        public int $maxRetryAfter = 300,
    ) {
    }
}
