<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Interaction;

use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Id\SlackChannelId;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;

use function is_int;
use function is_string;

/**
 * A verified button click handed to a handler (contract §6.2). It carries no raw body, header or token.
 */
final readonly class SlackInteraction
{
    /**
     * @param SlackInstallationKey $installation installation the request was verified for
     * @param string $actionId routed `action_id` (for a legacy value, the mapped one)
     * @param array<string, mixed> $data verified button data (`d`), or the decoded legacy value
     * @param SlackTeamId|null $team workspace of the click
     * @param SlackUserId|null $user member who clicked
     * @param SlackActor|null $actor resolved account, or null
     * @param SlackChannelId|null $channel conversation of the message
     * @param string|null $messageTs timestamp of the clicked message
     * @param string $interactionId stable id of the click (idempotency key)
     * @param bool $legacy the value was a legacy unsigned value
     * @param bool $verifiedByResolver the request was verified with a host-resolved secret (no Slack API answers)
     */
    public function __construct(
        public SlackInstallationKey $installation,
        public string $actionId,
        public array $data,
        public ?SlackTeamId $team,
        public ?SlackUserId $user,
        public ?SlackActor $actor,
        public ?SlackChannelId $channel,
        public ?string $messageTs,
        public string $interactionId,
        public bool $legacy = false,
        public bool $verifiedByResolver = false,
    ) {
    }

    /**
     * A string from the button data, or null.
     */
    public function string(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * An integer from the button data, or null.
     */
    public function int(string $key): ?int
    {
        $value = $this->data[$key] ?? null;

        return is_int($value) ? $value : null;
    }
}
