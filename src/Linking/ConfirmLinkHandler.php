<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Linking;

use Nowo\SlackKitBundle\Interaction\SlackActionHandlerInterface;
use Nowo\SlackKitBundle\Interaction\SlackInteraction;
use Nowo\SlackKitBundle\Interaction\SlackInteractionReply;

/**
 * The built-in handler of the confirmation button (`nowo_slack_kit.link.confirm`, no actor required: the click is
 * what verifies the link).
 */
final readonly class ConfirmLinkHandler implements SlackActionHandlerInterface
{
    /**
     * @param AccountLinkerInterface $linker linking state machine
     */
    public function __construct(
        private AccountLinkerInterface $linker,
    ) {
    }

    /**
     * {@see AccountLinker::confirm()}.
     */
    public function handle(SlackInteraction $interaction): SlackInteractionReply
    {
        return $this->linker->confirm($interaction);
    }
}
