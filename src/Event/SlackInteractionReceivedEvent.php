<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Event;

use Nowo\SlackKitBundle\Interaction\SlackInteraction;

/**
 * A verified interaction, before routing; also dispatched for interaction types the router does not handle in 1.0 (`view_submission`, `shortcut`…).
 */
final class SlackInteractionReceivedEvent
{
    /**
     * @param string $type interaction `type`
     * @param array<string, mixed> $payload decoded payload
     * @param SlackInteraction|null $interaction the parsed `block_actions` interaction, or null for other types
     */
    public function __construct(
        public readonly string $type,
        public readonly array $payload,
        public readonly ?SlackInteraction $interaction,
    ) {
    }
}
