<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Notifier;

use Nowo\SlackKitBundle\Api\SlackApiClientInterface;
use Nowo\SlackKitBundle\Api\SlackApiException;
use Nowo\SlackKitBundle\Api\SlackMessage;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Symfony\Component\Notifier\Exception\RuntimeException;
use Symfony\Component\Notifier\Exception\UnsupportedMessageTypeException;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Transport\TransportInterface;

/**
 * Symfony Notifier chat transport on top of {@see SlackApiClientInterface} (contract §11): the token comes from the
 * installation store, never from the DSN; a member recipient gets a DM through `conversations.open`.
 */
final readonly class SlackKitTransport implements TransportInterface
{
    /**
     * @param SlackApiClientInterface $api Web API client
     * @param SlackInstallationKey $installation installation named by the DSN
     */
    public function __construct(
        private SlackApiClientInterface $api,
        private SlackInstallationKey $installation,
    ) {
    }

    /**
     * Sends a `ChatMessage` with {@see SlackKitOptions}; the message id is `<channel>:<ts>`.
     *
     * @throws UnsupportedMessageTypeException for other messages
     * @throws RuntimeException when Slack refuses the message
     */
    public function send(MessageInterface $message): SentMessage
    {
        $options = $message instanceof ChatMessage ? $message->getOptions() : null;
        if (!$message instanceof ChatMessage || !$options instanceof SlackKitOptions || !$this->supports($message)) {
            throw new UnsupportedMessageTypeException(self::class, ChatMessage::class, $message);
        }
        try {
            $channel = $options->recipient instanceof SlackUserId ? $this->api->openDirectMessage($this->installation, $options->recipient) : $options->recipient;
            $posted  = $this->api->postMessage($this->installation, $channel, new SlackMessage($message->getSubject(), $options->blocks, threadTs: $options->threadTs));
        } catch (SlackApiException $e) {
            throw new RuntimeException('Slack refused the message: ' . $e->errorCode . '.', 0, $e);
        }
        $sent = new SentMessage($message, (string) $this);
        $sent->setMessageId($posted->channel . ':' . $posted->ts);

        return $sent;
    }

    /**
     * `ChatMessage` with {@see SlackKitOptions} (and no other transport named).
     */
    public function supports(MessageInterface $message): bool
    {
        return $message instanceof ChatMessage && $message->getOptions() instanceof SlackKitOptions
            && ($message->getTransport() === null || $message->getTransport() === 'nowo-slack');
    }

    /**
     * `nowo-slack://default?app=<app>[&team=<T…>]`.
     */
    public function __toString(): string
    {
        return 'nowo-slack://default?app=' . $this->installation->app . ($this->installation->team instanceof SlackTeamId ? '&team=' . $this->installation->team->value : '');
    }
}
