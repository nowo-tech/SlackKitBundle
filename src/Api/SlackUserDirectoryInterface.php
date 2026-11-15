<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Api;

use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Id\SlackUserId;

/**
 * `users.info` through one installation (1.4, link-from-click). Implemented by the bundle's Web API client; a separate
 * interface so {@see SlackApiClientInterface} implementations of hosts keep working.
 */
interface SlackUserDirectoryInterface
{
    /**
     * The member's profile as seen by the installation's bot.
     *
     * @throws SlackApiException
     */
    public function userProfile(SlackInstallationKey $installation, SlackUserId $user): SlackUserProfile;
}
