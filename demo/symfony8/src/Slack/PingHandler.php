<?php

declare(strict_types=1);

namespace App\Slack;

use Nowo\SlackKitBundle\Api\SlackMessage;
use Nowo\SlackKitBundle\Interaction\AsSlackActionHandler;
use Nowo\SlackKitBundle\Interaction\SlackActionHandlerInterface;
use Nowo\SlackKitBundle\Interaction\SlackInteraction;
use Nowo\SlackKitBundle\Interaction\SlackInteractionReply;

/**
 * A host handler: the router calls it only for a verified, active, same-workspace actor (requiresActor), after the
 * `nsk1` value has been verified.
 */
#[AsSlackActionHandler(action: 'demo.ping')]
final readonly class PingHandler implements SlackActionHandlerInterface
{
    public function handle(SlackInteraction $interaction): SlackInteractionReply
    {
        $who = $interaction->actor !== null ? $interaction->actor->accountId : 'nobody';

        return SlackInteractionReply::done('Pong for ' . $who)->withEphemeral(SlackMessage::plain('Pong, ' . $who . '.'));
    }
}
