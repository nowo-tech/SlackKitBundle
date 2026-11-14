<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Interaction;

use Nowo\SlackKitBundle\BlockKit\MessageUpdate;

use function array_values;
use function is_array;
use function is_string;

/**
 * The clicked message as Slack sent it in the verified payload (`message.text`, `message.blocks`): what
 * {@see MessageUpdate} rebuilds without its spent buttons (spec 237 T000c).
 */
final readonly class SlackOriginalMessage
{
    /** @var list<array<string, mixed>> */
    public array $blocks;

    /**
     * @param string $text fallback text of the message (may be empty)
     * @param array<array<string, mixed>> $blocks Block Kit blocks of the message
     */
    public function __construct(
        public string $text,
        array $blocks = [],
    ) {
        $this->blocks = array_values($blocks);
    }

    /**
     * The message of a verified `block_actions` payload, or null when it has none (an ephemeral or modal click).
     *
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): ?self
    {
        $message = $payload['message'] ?? null;
        if (!is_array($message)) {
            return null;
        }
        $blocks = [];
        foreach (is_array($message['blocks'] ?? null) ? $message['blocks'] : [] as $block) {
            if (is_array($block) && is_string($block['type'] ?? null)) {
                /* @var array<string, mixed> $block */
                $blocks[] = $block;
            }
        }

        return new self(is_string($message['text'] ?? null) ? $message['text'] : '', $blocks);
    }
}
