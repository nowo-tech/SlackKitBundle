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
     */
    public function __construct(
        public readonly SlackInstallationKey $installation,
        public readonly string $eventId,
        public readonly int $eventTime,
        public readonly string $type,
        public readonly array $event,
    ) {
    }
}
