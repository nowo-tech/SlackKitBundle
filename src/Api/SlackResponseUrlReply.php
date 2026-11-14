<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Api;

use InvalidArgumentException;

use function in_array;

/**
 * What to send to an interaction's `response_url` (spec 237 T000c): an ephemeral message for the clicker, a
 * replacement of the clicked message, or its deletion.
 */
final readonly class SlackResponseUrlReply
{
    /** An ephemeral message only the clicker sees. */
    public const EPHEMERAL = 'ephemeral';

    /** Replaces the clicked message. */
    public const REPLACE = 'replace';

    /** Deletes the clicked message. */
    public const DELETE = 'delete';

    /**
     * @param string $mode {@see self::EPHEMERAL}, {@see self::REPLACE} or {@see self::DELETE}
     * @param SlackMessage|null $message the message (required unless deleting)
     *
     * @throws InvalidArgumentException when the mode is unknown or the message is missing
     */
    public function __construct(
        public string $mode,
        public ?SlackMessage $message = null,
    ) {
        if (!in_array($mode, [self::EPHEMERAL, self::REPLACE, self::DELETE], true)) {
            throw new InvalidArgumentException('Unknown Slack response URL mode.');
        }
        if ($mode !== self::DELETE && !$message instanceof SlackMessage) {
            throw new InvalidArgumentException('A Slack response URL reply needs a message.');
        }
    }

    /**
     * An ephemeral message for the clicker.
     */
    public static function ephemeral(SlackMessage $message): self
    {
        return new self(self::EPHEMERAL, $message);
    }

    /**
     * A replacement of the clicked message.
     */
    public static function replace(SlackMessage $message): self
    {
        return new self(self::REPLACE, $message);
    }

    /**
     * The deletion of the clicked message.
     */
    public static function delete(): self
    {
        return new self(self::DELETE);
    }

    /**
     * The JSON body Slack expects.
     *
     * @return array<string, mixed>
     */
    public function toBody(): array
    {
        if (!$this->message instanceof SlackMessage) {
            return ['delete_original' => true];
        }
        $arguments = $this->message->toArguments();
        unset($arguments['unfurl_links'], $arguments['unfurl_media']);

        return $this->mode === self::EPHEMERAL
            ? ['response_type' => 'ephemeral', 'replace_original' => false] + $arguments
            : ['replace_original' => true] + $arguments;
    }
}
