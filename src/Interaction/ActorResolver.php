<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Interaction;

use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Linking\SlackIdentityStoreInterface;
use Nowo\SlackKitBundle\Linking\SlackLink;

/**
 * Resolves the clicker to an account only when the link exists for that workspace and member, is verified, and the
 * account is active (enabled, not anonymised) — OTHER REQ-SEC-001, contract §6.3. Since 1.3 an optional host filter
 * ({@see SlackActorFilterInterface}) sees the installation and how the click was verified, and may refuse.
 */
final readonly class ActorResolver
{
    /**
     * @param SlackIdentityStoreInterface|null $identities host identity store (null: no actor ever)
     * @param SlackActorFilterInterface|null $filter host filter (`installations.actor_filter`)
     */
    public function __construct(
        private ?SlackIdentityStoreInterface $identities = null,
        private ?SlackActorFilterInterface $filter = null,
    ) {
    }

    /**
     * The actor, or null.
     *
     * @param SlackInstallationKey|null $installation installation the click was verified for (1.3)
     * @param bool $verifiedByOwnApp the click was signed by an own-app secret (1.3)
     */
    public function resolve(?SlackTeamId $team, ?SlackUserId $user, ?SlackInstallationKey $installation = null, bool $verifiedByOwnApp = false): ?SlackActor
    {
        if (!$this->identities instanceof SlackIdentityStoreInterface || !$team instanceof SlackTeamId || !$user instanceof SlackUserId) {
            return null;
        }
        $link = $this->identities->findLinkBySlackUser($team, $user);
        if (!$link instanceof SlackLink || !$link->isVerified() || !$link->team->equals($team) || !$link->slackUserId->equals($user)
            || !$this->identities->isAccountActive($link->accountId)) {
            return null;
        }
        if ($this->filter instanceof SlackActorFilterInterface && !$this->filter->mayAct(new SlackActorCandidate($link->accountId, $team, $user, $installation, $verifiedByOwnApp))) {
            return null;
        }

        return new SlackActor($link->accountId, $this->identities->accountLocale($link->accountId));
    }
}
