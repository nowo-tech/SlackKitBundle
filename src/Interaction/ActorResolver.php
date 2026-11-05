<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Interaction;

use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Linking\SlackIdentityStoreInterface;
use Nowo\SlackKitBundle\Linking\SlackLink;

/**
 * Resolves the clicker to an account only when the link exists for that workspace and member, is verified, and the
 * account is active (enabled, not anonymised) — OTHER REQ-SEC-001, contract §6.3.
 */
final readonly class ActorResolver
{
    /**
     * @param SlackIdentityStoreInterface|null $identities host identity store (null: no actor ever)
     */
    public function __construct(
        private ?SlackIdentityStoreInterface $identities = null,
    ) {
    }

    /**
     * The actor, or null.
     */
    public function resolve(?SlackTeamId $team, ?SlackUserId $user): ?SlackActor
    {
        if (!$this->identities instanceof SlackIdentityStoreInterface || !$team instanceof SlackTeamId || !$user instanceof SlackUserId) {
            return null;
        }
        $link = $this->identities->findLinkBySlackUser($team, $user);
        if (!$link instanceof SlackLink || !$link->isVerified() || !$link->team->equals($team) || !$link->slackUserId->equals($user)
            || !$this->identities->isAccountActive($link->accountId)) {
            return null;
        }

        return new SlackActor($link->accountId, $this->identities->accountLocale($link->accountId));
    }
}
