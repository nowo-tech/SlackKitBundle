<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Credentials;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\SlackKitBundle\Id\SlackTeamId;

/**
 * Gives an owner scope the use of an installation (spec 237 FR-001): two organizations in the same workspace share one
 * installation (one token, one revocation) through two bindings.
 */
final readonly class SlackInstallationBinding
{
    /**
     * @param SlackInstallationKey $installation installation (with its workspace)
     * @param SlackOwnerScope $scope owner scope that may use it
     * @param DateTimeImmutable|null $boundAt when the binding was made
     * @param string|null $boundByAccountId host account that made it (opaque)
     *
     * @throws InvalidArgumentException when the installation key names no workspace
     */
    public function __construct(
        public SlackInstallationKey $installation,
        public SlackOwnerScope $scope,
        public ?DateTimeImmutable $boundAt = null,
        public ?string $boundByAccountId = null,
    ) {
        if (!$installation->team instanceof SlackTeamId) {
            throw new InvalidArgumentException('A Slack installation binding needs the installation workspace.');
        }
    }

    /**
     * Same installation workspace and scope.
     */
    public function sameAs(self $other): bool
    {
        return $this->installation->sameWorkspace($other->installation) && $this->scope->equals($other->scope);
    }
}
