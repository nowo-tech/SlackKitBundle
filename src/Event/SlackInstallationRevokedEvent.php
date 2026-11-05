<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Event;

use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;

/**
 * An installation was uninstalled or its bot token revoked (`app_uninstalled`, `tokens_revoked`).
 */
final class SlackInstallationRevokedEvent
{
    /**
     * @param SlackInstallationKey $installation installation
     * @param string $reason Slack event type
     */
    public function __construct(
        public readonly SlackInstallationKey $installation,
        public readonly string $reason,
    ) {
    }
}
