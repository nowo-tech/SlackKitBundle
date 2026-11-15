<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Linking;

use Nowo\SlackKitBundle\Api\SlackMessage;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Interaction\SlackInteraction;

/**
 * Several identities per account and linking from a click (1.4, spec 237 T000b). Public alias of the bundle's
 * {@see ClickLinker} for hosts with their own pages.
 */
interface ClickLinkerInterface
{
    /**
     * The ephemeral "Link your account" answer for an unlinked clicker, or null when the click may not offer one (not
     * signed by Slack through a configured app, a legacy value, a click from another workspace than the installation's,
     * or a member already linked).
     */
    public function offer(SlackInteraction $interaction): ?SlackMessage;

    /**
     * What the confirmation page shows for the signed-in account, or why the token cannot be used. Does not consume it.
     */
    public function preview(string $accountId, string $token): ClickLinkPreview|LinkResult;

    /**
     * Consumes the token and links the identity: {@see LinkResult::Linked} or the refusal.
     */
    public function confirm(string $accountId, string $token): LinkResult;

    /**
     * Every identity of the account, one per workspace.
     *
     * @return list<SlackLink>
     */
    public function linksOf(string $accountId): array;

    /**
     * Unlinks the account's identity in that workspace.
     */
    public function unlinkIn(string $accountId, SlackTeamId $team): LinkResult;
}
