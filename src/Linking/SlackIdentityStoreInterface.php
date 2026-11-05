<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Linking;

use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;

/**
 * The host's accounts as seen by Slack linking (contract §8.1). The host keeps one Slack id per account and one
 * account per (workspace, Slack id), and MUST clear `verifiedAt` whenever the Slack id of an account changes.
 */
interface SlackIdentityStoreInterface
{
    /**
     * The account's link, or null.
     */
    public function findLinkByAccount(string $accountId): ?SlackLink;

    /**
     * The link that holds this member id in this workspace, or null.
     */
    public function findLinkBySlackUser(SlackTeamId $team, SlackUserId $user): ?SlackLink;

    /**
     * Whether the account may act: enabled and not anonymised.
     */
    public function isAccountActive(string $accountId): bool;

    /**
     * The account's confirmed e-mail (for "Find me by e-mail"), or null.
     */
    public function accountEmail(string $accountId): ?string;

    /**
     * The account's preferred locale, or null.
     */
    public function accountLocale(string $accountId): ?string;

    /**
     * Creates or replaces the account's link.
     */
    public function saveLink(SlackLink $link): void;

    /**
     * Removes the account's link (unlink, release).
     */
    public function removeLink(string $accountId): void;
}
