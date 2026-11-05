<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Ingress;

use Nowo\SlackKitBundle\Security\SlackSecret;

/**
 * Host hook for requests signed by secrets that are not apps (for example one secret per notification destination,
 * each a customer's own Slack app). The bundle tries at most `ingress.max_secret_candidates` of them; a request
 * verified with one of them gets no team check and no Slack API answers (contract §5.2, Q-B2).
 *
 * Every holder of such a secret can sign any `team.id` / `user.id`, so a resolver-verified click gets **no actor**
 * (`requiresActor` handlers refuse it) and legacy unsigned values are refused on it. With
 * `ingress.resolver_actors: true`, a candidate returned as a {@see ResolvedSigningSecret} bound to a workspace yields
 * an actor when the click's `team.id` equals that workspace (audit FS-M1).
 */
interface SigningSecretResolverInterface
{
    /**
     * Candidate secrets for this request, read from the bounded unverified view.
     *
     * @return iterable<ResolvedSigningSecret|SlackSecret>
     */
    public function candidates(UnverifiedPayloadPeek $peek): iterable;
}
