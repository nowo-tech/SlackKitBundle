<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Api;

/**
 * The answer of `auth.test`: the workspace and the bot user of the token.
 */
final readonly class AuthTestResult
{
    /**
     * @param string $team workspace name
     * @param string $teamId workspace id
     * @param string $user bot user name
     * @param string $userId bot user id
     * @param string $botId bot id (empty for non-bot tokens)
     */
    public function __construct(
        public string $team,
        public string $teamId,
        public string $user,
        public string $userId,
        public string $botId,
    ) {
    }
}
