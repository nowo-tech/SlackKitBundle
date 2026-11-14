<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Event;

use Nowo\SlackKitBundle\Interaction\SlackInteraction;
use Nowo\SlackKitBundle\Security\SlackSecret;

/**
 * A verified interaction, before routing; also dispatched for interaction types the router does not handle in 1.0 (`view_submission`, `shortcut`…).
 *
 * `$payload` is the raw decoded payload: it still holds `response_url` (and `response_urls`) as plain strings, which
 * let anyone answer the click for 30 minutes. Never log the payload as is (audit PZ-I5); handlers get the URL as a
 * redacted {@see SlackSecret} on {@see SlackInteraction::$responseUrl}.
 */
final class SlackInteractionReceivedEvent
{
    /**
     * @param string $type interaction `type`
     * @param array<string, mixed> $payload decoded payload
     * @param SlackInteraction|null $interaction the parsed `block_actions` interaction, or null for other types
     * @param bool $verifiedByOwnApp the request was verified with an installation's own-app secret (1.3)
     */
    public function __construct(
        public readonly string $type,
        public readonly array $payload,
        public readonly ?SlackInteraction $interaction,
        public readonly bool $verifiedByOwnApp = false,
    ) {
    }
}
