<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Credentials;

use DateTimeImmutable;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Security\SlackSecret;

/**
 * An app installed in one workspace: its bot token and what the bundle needs to know about it.
 */
final readonly class SlackInstallation
{
    /**
     * @param SlackInstallationKey $key which installation
     * @param SlackSecret $botToken bot token (`xoxb-`)
     * @param SlackTeamId|null $teamId workspace (required for signed ingress and linking)
     * @param bool $allowEmailLookup `users.lookupByEmail` allowed (scope `users:read.email`)
     * @param string|null $botUserId bot user id, when known
     * @param DateTimeImmutable|null $revokedAt uninstall or revocation time
     */
    public function __construct(
        public SlackInstallationKey $key,
        public SlackSecret $botToken,
        public ?SlackTeamId $teamId = null,
        public bool $allowEmailLookup = false,
        public ?string $botUserId = null,
        public ?DateTimeImmutable $revokedAt = null,
    ) {
    }

    /**
     * Whether the installation was uninstalled or its token revoked.
     */
    public function isRevoked(): bool
    {
        return $this->revokedAt instanceof DateTimeImmutable;
    }
}
