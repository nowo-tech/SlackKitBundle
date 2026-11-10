<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Linking;

use Nowo\SlackKitBundle\Interaction\SlackInteraction;
use Nowo\SlackKitBundle\Interaction\SlackInteractionReply;

/**
 * The account-linking state machine (1.2, DDD P2).
 *
 * The documented API for hosts with their own linking UI. {@see AccountLinker} is the default implementation (public
 * alias, registered when `linking.enabled`). The bundle's controller, Twig extension and *Confirm* handler use the
 * alias, so a decorator or a replacement registered under this id applies everywhere.
 */
interface AccountLinkerInterface
{
    /**
     * The account's current link, or null.
     */
    public function linkOf(string $accountId): ?SlackLink;

    /**
     * Whether "Find me by e-mail" is offered (configuration and installation both allow it).
     */
    public function canFindByEmail(): bool;

    /**
     * Claims a typed Slack member id for the account.
     */
    public function claim(string $accountId, string $slackUserId): LinkResult;

    /**
     * Finds the account's member id with `users.lookupByEmail` (a proven claim).
     */
    public function findByEmail(string $accountId): LinkResult;

    /**
     * Sends the confirmation DM with a signed *Confirm* button to the claimed member id.
     */
    public function sendConfirmation(string $accountId): LinkResult;

    /**
     * Handles the signed *Confirm* click.
     */
    public function confirm(SlackInteraction $interaction): SlackInteractionReply;

    /**
     * Removes the account's link.
     */
    public function unlink(string $accountId): LinkResult;
}
