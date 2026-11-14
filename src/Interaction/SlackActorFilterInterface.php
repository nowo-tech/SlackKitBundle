<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Interaction;

/**
 * Optional host hook (`installations.actor_filter`, spec 237 / audit PZ-M1): the last word on whether a verified,
 * active link may act for a click on this installation, for example only accounts of the owner scopes bound to it.
 * Without it every verified, active link of the click's workspace may act (1.2 behaviour).
 */
interface SlackActorFilterInterface
{
    /**
     * Whether the candidate may be the click's actor.
     */
    public function mayAct(SlackActorCandidate $candidate): bool;
}
