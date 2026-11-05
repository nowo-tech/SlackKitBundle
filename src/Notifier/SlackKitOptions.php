<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Notifier;

use Nowo\SlackKitBundle\BlockKit\Blocks;
use Nowo\SlackKitBundle\Id\SlackChannelId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Symfony\Component\Notifier\Message\MessageOptionsInterface;

/**
 * Options of a `ChatMessage` sent through the `nowo-slack://` transport: the recipient (a conversation, or a member
 * whose DM is opened) and Block Kit blocks from {@see Blocks}.
 */
final readonly class SlackKitOptions implements MessageOptionsInterface
{
    /**
     * @param SlackChannelId|SlackUserId $recipient conversation or member
     * @param list<array<string, mixed>> $blocks Block Kit blocks
     * @param string|null $threadTs reply in this thread
     */
    public function __construct(
        public SlackChannelId|SlackUserId $recipient,
        public array $blocks = [],
        public ?string $threadTs = null,
    ) {
    }

    /**
     * The options as an array.
     *
     * @return array{recipient: string, blocks: list<array<string, mixed>>, thread_ts: string|null}
     */
    public function toArray(): array
    {
        return ['recipient' => $this->recipient->value, 'blocks' => $this->blocks, 'thread_ts' => $this->threadTs];
    }

    /**
     * The conversation or member id.
     */
    public function getRecipientId(): string
    {
        return $this->recipient->value;
    }
}
