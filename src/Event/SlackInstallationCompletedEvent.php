<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Event;

use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Credentials\SlackOwnerScope;

/**
 * An "Add to Slack" install finished: the installation was saved (a re-install updated its token) and bound to the
 * owner scope (spec 237 FR-003). Dispatched after the stores returned.
 */
final class SlackInstallationCompletedEvent
{
    /**
     * @param SlackInstallationKey $installation installation (app, enterprise, workspace)
     * @param SlackOwnerScope $scope owner scope it was bound to
     * @param string $accountId host account that installed it
     * @param bool $reinstalled the workspace was already installed for this app
     */
    public function __construct(
        public readonly SlackInstallationKey $installation,
        public readonly SlackOwnerScope $scope,
        public readonly string $accountId,
        public readonly bool $reinstalled,
    ) {
    }
}
