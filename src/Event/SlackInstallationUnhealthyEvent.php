<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Event;

use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;

/**
 * Slack refused an installation's token (`invalid_auth`, `token_revoked`…); dispatched at most once per 10 minutes per installation.
 */
final class SlackInstallationUnhealthyEvent
{
    /**
     * @param SlackInstallationKey $installation installation
     * @param string $errorCode Slack error code
     */
    public function __construct(
        public readonly SlackInstallationKey $installation,
        public readonly string $errorCode,
    ) {
    }
}
