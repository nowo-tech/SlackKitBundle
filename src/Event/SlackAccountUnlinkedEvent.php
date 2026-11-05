<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Event;

/**
 * An account removed its Slack link. Hosts purge what their Slack features kept about the account.
 */
final class SlackAccountUnlinkedEvent
{
    /**
     * @param string $accountId account id
     * @param string|null $slackUserId the member id it had, or null
     */
    public function __construct(
        public readonly string $accountId,
        public readonly ?string $slackUserId,
    ) {
    }
}
