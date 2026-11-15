<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Api;

use Nowo\SlackKitBundle\Id\SlackUserId;

/**
 * What `users.info` says about a member (spec 237 FR-011 / FR-012): the name to show, the e-mail (only with
 * `users:read.email`), and whether it is a bot or deactivated. The e-mail is personal data: never log it.
 */
final readonly class SlackUserProfile
{
    /**
     * @param SlackUserId $user member id
     * @param string $displayName display name, else real name, else the member id
     * @param string|null $email profile e-mail, when the installation may read it
     * @param bool $bot the member is a bot
     * @param bool $deleted the member is deactivated
     */
    public function __construct(
        public SlackUserId $user,
        public string $displayName,
        public ?string $email = null,
        public bool $bot = false,
        public bool $deleted = false,
    ) {
    }
}
