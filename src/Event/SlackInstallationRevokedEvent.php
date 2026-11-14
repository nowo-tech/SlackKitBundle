<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Event;

use Nowo\SlackKitBundle\Credentials\SlackInstallationBinding;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;

/**
 * An installation was uninstalled or its bot token revoked (`app_uninstalled`, `tokens_revoked`). Dispatched once per
 * installation: a second revocation event for an installation already revoked is not dispatched again. Since 1.3 it
 * lists the bindings of the installation (spec 237 FR-006), so the host can mark their destinations broken and notify
 * their admins.
 */
final class SlackInstallationRevokedEvent
{
    /**
     * @param SlackInstallationKey $installation installation
     * @param string $reason Slack event type
     * @param list<SlackInstallationBinding> $bindings bindings of the installation (empty without
     *                                                 `installations.binding_store`)
     */
    public function __construct(
        public readonly SlackInstallationKey $installation,
        public readonly string $reason,
        public readonly array $bindings = [],
    ) {
    }
}
