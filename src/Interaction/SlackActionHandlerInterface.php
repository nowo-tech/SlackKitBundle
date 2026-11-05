<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Interaction;

/**
 * Handles one button `action_id`. Register it with {@see AsSlackActionHandler}. Handlers must be idempotent and must
 * check the actor's own access to the resource.
 */
interface SlackActionHandlerInterface
{
    /**
     * Acts on the click and answers.
     */
    public function handle(SlackInteraction $interaction): SlackInteractionReply;
}
