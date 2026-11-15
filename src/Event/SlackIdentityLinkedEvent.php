<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Event;

use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;

/**
 * A Slack identity was linked to an account from a click (1.4, spec 237 FR-011): dispatched after the identity store
 * saved it. Carries ids only (no e-mail).
 */
final class SlackIdentityLinkedEvent
{
    /**
     * @param string $accountId host account
     * @param string $teamId workspace
     * @param string $slackUserId member id
     * @param SlackInstallationKey $installation installation of the click
     * @param bool $emailMatched the member's e-mail matched a verified e-mail of the account (false only with the
     *                           installation's `allowUnverifiedLinkConfirm`)
     */
    public function __construct(
        public readonly string $accountId,
        public readonly string $teamId,
        public readonly string $slackUserId,
        public readonly SlackInstallationKey $installation,
        public readonly bool $emailMatched,
    ) {
    }
}
