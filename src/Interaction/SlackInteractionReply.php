<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Interaction;

use Nowo\SlackKitBundle\Api\SlackMessage;
use Nowo\SlackKitBundle\BlockKit\MessageUpdate;

/**
 * What a handler answers (contract §6.4): an HTTP status and short body for Slack, plus Slack calls the router makes
 * after the response (an ephemeral message to the clicker, replacing or deleting the clicked message). By default
 * they go through the Web API with the click's installation (`chat.postEphemeral`, `chat.update`, `chat.delete`);
 * {@see self::viaResponseUrl()} sends them to the click's `response_url` instead (no bot token needed, spec 237
 * T000c).
 */
final readonly class SlackInteractionReply
{
    /**
     * @param int $status HTTP status (200, 403, 404…)
     * @param string $body short plain-text body
     * @param SlackMessage|null $ephemeral message only the clicker sees
     * @param SlackMessage|null $replacement new content of the clicked message
     * @param bool $deleteOriginal delete the clicked message
     * @param bool $viaResponseUrl answer through the click's `response_url` instead of the Web API
     */
    public function __construct(
        public int $status = 200,
        public string $body = '',
        public ?SlackMessage $ephemeral = null,
        public ?SlackMessage $replacement = null,
        public bool $deleteOriginal = false,
        public bool $viaResponseUrl = false,
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
        return new self($this->status, $this->body, $message, $this->replacement, $this->deleteOriginal, $this->viaResponseUrl);
    }

    /**
     * Same reply, replacing the clicked message.
     */
    public function replaceOriginal(SlackMessage $message): self
    {
        return new self($this->status, $this->body, $this->ephemeral, $message, false, $this->viaResponseUrl);
    }

    /**
     * Same reply, deleting the clicked message.
     */
    public function deleteOriginal(): self
    {
        return new self($this->status, $this->body, $this->ephemeral, null, true, $this->viaResponseUrl);
    }

    /**
     * Same reply, replacing the clicked message with itself minus the spent buttons plus a context line
     * ({@see MessageUpdate::withoutSpentButtons()}); unchanged when the click carries no message.
     *
     * @param list<string> $spentActionIds `action_id`s that no longer apply; empty: every interactive element
     * @param string|null $context context line, e.g. "Assigned to Ana" (escaped)
     */
    public function withSpentButtons(SlackInteraction $interaction, array $spentActionIds = [], ?string $context = null): self
    {
        return $interaction->message instanceof SlackOriginalMessage
            ? $this->replaceOriginal(MessageUpdate::withoutSpentButtons($interaction->message, $spentActionIds, $context))
            : $this;
    }

    /**
     * Same reply, sent through the click's `response_url` (ephemeral answer, replacement or deletion) instead of the
     * Web API. Without a valid `response_url` on the click, the Web API is used as usual.
     */
    public function viaResponseUrl(): self
    {
        return new self($this->status, $this->body, $this->ephemeral, $this->replacement, $this->deleteOriginal, true);
    }
}
