<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Event;

/**
 * An account confirmed its Slack id from Slack (the link is now verified).
 */
final class SlackAccountVerifiedEvent
{
    /**
     * @param string $accountId account id
     * @param string $teamId workspace
     * @param string $slackUserId member id
     */
    public function __construct(
        public readonly string $accountId,
        public readonly string $teamId,
        public readonly string $slackUserId,
    ) {
    }
}
