<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Event;

/**
 * An account claimed a Slack id (typed, or found by e-mail); not verified yet.
 */
final class SlackAccountClaimedEvent
{
    /**
     * @param string $accountId account id
     * @param string $teamId workspace
     * @param string $slackUserId member id
     * @param string $source `typed` or `email_lookup`
     */
    public function __construct(
        public readonly string $accountId,
        public readonly string $teamId,
        public readonly string $slackUserId,
        public readonly string $source,
    ) {
    }
}
