<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Interaction;

use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;

/**
 * A verified, active link about to become the actor of a click (spec 237, audit PZ-M1): what
 * {@see SlackActorFilterInterface} decides on. The host can limit actors to the accounts of the scopes bound to the
 * installation, which matters most for clicks signed by a workspace's own app (`verifiedByOwnApp`).
 */
final readonly class SlackActorCandidate
{
    /**
     * @param string $accountId linked host account (opaque)
     * @param SlackTeamId $team workspace of the click
     * @param SlackUserId $user member who clicked
     * @param SlackInstallationKey|null $installation installation the click was verified for
     * @param bool $verifiedByOwnApp the click was signed by the installation's own-app secret, not by a configured app
     */
    public function __construct(
        public string $accountId,
        public SlackTeamId $team,
        public SlackUserId $user,
        public ?SlackInstallationKey $installation,
        public bool $verifiedByOwnApp = false,
    ) {
    }
}
