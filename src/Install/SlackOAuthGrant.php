<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Install;

use Nowo\SlackKitBundle\Id\SlackEnterpriseId;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Security\SlackSecret;

/**
 * What `oauth.v2.access` granted, validated: the bot token (null when Slack returned something else, such as a
 * rotating token), the workspace, the app and the scopes. A user token, if Slack sends one, is never kept.
 */
final readonly class SlackOAuthGrant
{
    /**
     * @param string|null $appId Slack app id (`A…`)
     * @param SlackTeamId|null $team workspace
     * @param string $teamName workspace name
     * @param SlackEnterpriseId|null $enterprise Enterprise Grid organisation
     * @param bool $enterpriseInstall org-wide install (`is_enterprise_install`)
     * @param SlackSecret|null $botToken bot token (`xoxb-`), null when Slack returned no plain bot token
     * @param string|null $botUserId bot user id
     * @param list<string> $scopes granted bot scopes
     * @param SlackUserId|null $installer member who approved the install (`authed_user.id`)
     * @param bool $rotating Slack returned a refresh token or an expiry (token rotation is on)
     */
    public function __construct(
        public ?string $appId,
        public ?SlackTeamId $team,
        public string $teamName,
        public ?SlackEnterpriseId $enterprise,
        public bool $enterpriseInstall,
        public ?SlackSecret $botToken,
        public ?string $botUserId,
        public array $scopes,
        public ?SlackUserId $installer,
        public bool $rotating,
    ) {
    }
}
