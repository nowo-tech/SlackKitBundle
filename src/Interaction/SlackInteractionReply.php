<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Interaction;

use Nowo\SlackKitBundle\Api\SlackMessage;

/**
 * What a handler answers (contract §6.4): an HTTP status and short body for Slack, plus Slack calls the router makes
 * after the response (an ephemeral message to the clicker, replacing or deleting the clicked message).
 */
final readonly class SlackInteractionReply
{
    /**
     * @param int $status HTTP status (200, 403, 404…)
     * @param string $body short plain-text body
     * @param SlackMessage|null $ephemeral message only the clicker sees
     * @param SlackMessage|null $replacement new content of the clicked message
     * @param bool $deleteOriginal delete the clicked message
     */
    public function __construct(
        public int $status = 200,
        public string $body = '',
        public ?SlackMessage $ephemeral = null,
        public ?SlackMessage $replacement = null,
        public bool $deleteOriginal = false,
    ) {
    }

    /**
     * 200, nothing else.
     */
    public static function done(string $body = ''): self
    {
        return new self(200, $body);
    }

    /**
     * 404: the item no longer exists.
     */
    public static function gone(string $body = 'Gone'): self
    {
        return new self(404, $body);
    }

    /**
     * 403: the actor lost access to the item.
     */
    public static function noAccess(string $body = 'No access'): self
    {
        return new self(403, $body);
    }

    /**
     * 403: the actor may see the item but not do this.
     */
    public static function forbidden(string $body = 'Forbidden'): self
    {
        return new self(403, $body);
    }

    /**
     * Same reply with an ephemeral message.
     */
    public function withEphemeral(SlackMessage $message): self
    {
        return new self($this->status, $this->body, $message, $this->replacement, $this->deleteOriginal);
    }

    /**
     * Same reply, replacing the clicked message.
     */
    public function replaceOriginal(SlackMessage $message): self
    {
        return new self($this->status, $this->body, $this->ephemeral, $message, false);
    }

    /**
     * Same reply, deleting the clicked message.
     */
    public function deleteOriginal(): self
    {
        return new self($this->status, $this->body, $this->ephemeral, null, true);
    }
}
