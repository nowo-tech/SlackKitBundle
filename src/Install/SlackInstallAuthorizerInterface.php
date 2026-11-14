<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Install;

/**
 * Host hook that decides who may install an app for an owner scope (`installations.oauth.authorizer`, spec 237
 * FR-003). Without it the install flow is off. It is asked when the flow starts and again when Slack redirects back.
 */
interface SlackInstallAuthorizerInterface
{
    /**
     * Whether the account may install the app for the scope.
     */
    public function mayInstall(SlackInstallAttempt $attempt): bool;
}
