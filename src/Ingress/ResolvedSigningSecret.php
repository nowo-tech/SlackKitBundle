<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Ingress;

use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Security\SlackSecret;

/**
 * A host-resolved signing secret bound to the Slack workspace of the app that owns it. Only a bound secret can
 * produce a verified actor, and only with `ingress.resolver_actors: true` and a click whose `team.id` equals the
 * bound workspace (audit FS-M1).
 */
final readonly class ResolvedSigningSecret
{
    /**
     * @param SlackSecret $secret the signing secret (`SlackSecret::signingSecret()`)
     * @param SlackTeamId|null $team the workspace the secret's app is installed in, when the host knows it
     */
    public function __construct(
        public SlackSecret $secret,
        public ?SlackTeamId $team = null,
    ) {
    }
}
