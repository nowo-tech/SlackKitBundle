<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Support;

use Nowo\SlackKitBundle\Api\SlackMessage;
use Nowo\SlackKitBundle\Interaction\SlackActionHandlerInterface;
use Nowo\SlackKitBundle\Interaction\SlackInteraction;
use Nowo\SlackKitBundle\Interaction\SlackInteractionReply;
use RuntimeException;

/**
 * Handler that records the interactions it gets; throws when `$throw` is set.
 */
final class StubHandler implements SlackActionHandlerInterface
{
    /** @var list<SlackInteraction> */
    public array $seen = [];

    public bool $throw = false;

    public function handle(SlackInteraction $interaction): SlackInteractionReply
    {
        $this->seen[] = $interaction;
        if ($this->throw) {
            throw new RuntimeException('handler secret detail ' . Fake::TOKEN);
        }

        return SlackInteractionReply::done('Done')->replaceOriginal(SlackMessage::plain('Resolved'));
    }
}
