<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Support;

use Nowo\SlackKitBundle\Interaction\AsSlackActionHandler;
use Nowo\SlackKitBundle\Interaction\SlackActionHandlerInterface;
use Nowo\SlackKitBundle\Interaction\SlackInteraction;
use Nowo\SlackKitBundle\Interaction\SlackInteractionReply;

/**
 * A host handler that marks an item done and rebuilds the message without its buttons ("Done by <account>").
 */
#[AsSlackActionHandler('test.item.done')]
final class UpdatingHandler implements SlackActionHandlerInterface
{
    /** @var list<array{team: string|null, account: string|null, installation: string}> */
    public array $seen = [];

    public function handle(SlackInteraction $interaction): SlackInteractionReply
    {
        $this->seen[] = ['team' => $interaction->team?->value, 'account' => $interaction->actor?->accountId, 'installation' => $interaction->installation->key()];

        return SlackInteractionReply::done('Done')->withSpentButtons($interaction, [], 'Done by ' . $interaction->actor?->accountId);
    }

    /**
     * Forgets what it saw (the worker test checks the handler holds no state of its own between requests).
     */
    public function clear(): void
    {
        $this->seen = [];
    }
}
