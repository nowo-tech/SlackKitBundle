<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Event;

/**
 * Slack paused Events API deliveries (`app_rate_limited`).
 */
final class SlackEventsRateLimitedEvent
{
    /**
     * @param string $teamId workspace
     * @param int $minuteRateLimited `minute_rate_limited` (Unix seconds)
     */
    public function __construct(
        public readonly string $teamId,
        public readonly int $minuteRateLimited,
    ) {
    }
}
