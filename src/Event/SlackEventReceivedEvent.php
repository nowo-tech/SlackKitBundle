<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Event;

use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;

/**
 * A verified, de-duplicated Events API callback (contract §5.4). Listen to it to react to Slack events.
 */
final class SlackEventReceivedEvent
{
    /**
     * @param SlackInstallationKey $installation installation the callback belongs to
     * @param string $eventId `event_id`
     * @param int $eventTime `event_time` (Unix seconds)
     * @param string $type `event.type`
     * @param array<string, mixed> $event decoded `event` object
     * @param bool $verifiedByOwnApp the callback was signed with an installation's own-app secret, held by whoever
     *                               pasted it: its `event.user` (and every other field) may be chosen by that holder,
     *                               so never treat it as a verified actor (1.3, audit PZ-L7)
     */
    public function __construct(
        public readonly SlackInstallationKey $installation,
        public readonly string $eventId,
        public readonly int $eventTime,
        public readonly string $type,
        public readonly array $event,
        public readonly bool $verifiedByOwnApp = false,
    ) {
    }
}
