<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Linking;

use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;

/**
 * A verified link-from-click token (spec 237 FR-011): which member of which workspace, through which installation,
 * until when, and its single-use nonce.
 */
final readonly class ClickLinkToken
{
    /**
     * @param SlackInstallationKey $installation installation of the click (app and workspace)
     * @param SlackTeamId $team workspace
     * @param SlackUserId $user member who clicked
     * @param int $expiresAt Unix seconds
     * @param string $nonce single-use nonce
     */
    public function __construct(
        public SlackInstallationKey $installation,
        public SlackTeamId $team,
        public SlackUserId $user,
        public int $expiresAt,
        public string $nonce,
    ) {
    }
}
