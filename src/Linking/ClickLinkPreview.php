<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Linking;

/**
 * What the link-from-click confirmation page shows (spec 237 FR-011): the workspace and the Slack member, by name.
 */
final readonly class ClickLinkPreview
{
    /**
     * @param string $teamId workspace id
     * @param string $teamName workspace name (the id when the installation records no name)
     * @param string $slackUserId member id
     * @param string $displayName member's display name in Slack
     */
    public function __construct(
        public string $teamId,
        public string $teamName,
        public string $slackUserId,
        public string $displayName,
    ) {
    }
}
