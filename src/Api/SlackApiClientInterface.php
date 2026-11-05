<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Api;

use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Id\SlackChannelId;
use Nowo\SlackKitBundle\Id\SlackUserId;

/**
 * The Slack Web API methods the bundle supports (contract §4.2). Every call names its installation; the token is
 * read from the store at call time and only sent in the `Authorization` header.
 */
interface SlackApiClientInterface
{
    /**
     * `auth.test`: the workspace and bot user of the installation's token.
     *
     * @throws SlackApiException
     */
    public function authTest(SlackInstallationKey $installation): AuthTestResult;

    /**
     * `conversations.open`: the direct-message conversation with a member.
     *
     * @throws SlackApiException
     */
    public function openDirectMessage(SlackInstallationKey $installation, SlackUserId $user): SlackChannelId;

    /**
     * `chat.postMessage`.
     *
     * @throws SlackApiException
     */
    public function postMessage(SlackInstallationKey $installation, SlackChannelId $channel, SlackMessage $message): PostedMessage;

    /**
     * `chat.update`.
     *
     * @throws SlackApiException
     */
    public function updateMessage(SlackInstallationKey $installation, PostedMessage $target, SlackMessage $message): void;

    /**
     * `chat.delete`.
     *
     * @throws SlackApiException
     */
    public function deleteMessage(SlackInstallationKey $installation, PostedMessage $target): void;

    /**
     * `chat.postEphemeral`: a message only this member sees in the conversation.
     *
     * @throws SlackApiException
     */
    public function postEphemeral(SlackInstallationKey $installation, SlackChannelId $channel, SlackUserId $user, SlackMessage $message): void;

    /**
     * `users.lookupByEmail`: the member with this e-mail, or null (`users_not_found`).
     *
     * @throws EmailLookupDisabledException when the installation does not allow the lookup
     * @throws SlackApiException
     */
    public function lookupUserByEmail(SlackInstallationKey $installation, string $email): ?SlackUserId;
}
