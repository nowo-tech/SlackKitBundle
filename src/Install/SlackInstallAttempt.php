<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Install;

use Nowo\SlackKitBundle\Credentials\SlackOwnerScope;

/**
 * Someone wants to install an app for an owner scope ("Add to Slack", spec 237 FR-003): what
 * {@see SlackInstallAuthorizerInterface} decides on.
 */
final readonly class SlackInstallAttempt
{
    /**
     * @param string $app app name (`nowo_slack_kit.apps` key)
     * @param SlackOwnerScope $scope owner scope the installation would be bound to
     * @param string $accountId signed-in host account (opaque)
     */
    public function __construct(
        public string $app,
        public SlackOwnerScope $scope,
        public string $accountId,
    ) {
    }
}
