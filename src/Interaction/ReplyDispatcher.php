<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Interaction;

use Nowo\SlackKitBundle\Api\PostedMessage;
use Nowo\SlackKitBundle\Api\SlackApiClientInterface;
use Nowo\SlackKitBundle\Api\SlackApiException;
use Nowo\SlackKitBundle\Api\SlackMessage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Service\ResetInterface;

use function count;

/**
 * Makes the Slack calls of interaction replies after the response is sent (`kernel.terminate`), so a slow Slack call
 * never pushes an interaction past Slack's 3-second limit. Best effort: failures are logged with their code.
 * Interactions verified with a host-resolved secret get no Slack calls (the instance token belongs to another
 * workspace).
 */
final class ReplyDispatcher implements ResetInterface
{
    /** @var list<array{0: SlackInteraction, 1: SlackInteractionReply}> */
    private array $pending = [];

    /**
     * @param SlackApiClientInterface $api Web API client
     * @param LoggerInterface $logger PSR-3 logger
     */
    public function __construct(
        private readonly SlackApiClientInterface $api,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Queues the reply's Slack calls.
     */
    public function schedule(SlackInteraction $interaction, SlackInteractionReply $reply): void
    {
        if ($interaction->verifiedByResolver || (!$reply->ephemeral instanceof SlackMessage && !$reply->replacement instanceof SlackMessage && !$reply->deleteOriginal)) {
            return;
        }
        $this->pending[] = [$interaction, $reply];
    }

    /**
     * Performs the queued calls (listener of `kernel.terminate` and `console.terminate`).
     */
    public function flush(): void
    {
        $pending       = $this->pending;
        $this->pending = [];
        foreach ($pending as [$interaction, $reply]) {
            $channel = $interaction->channel;
            if ($channel === null) {
                continue;
            }
            try {
                if ($reply->ephemeral !== null && $interaction->user !== null) {
                    $this->api->postEphemeral($interaction->installation, $channel, $interaction->user, $reply->ephemeral);
                }
                if ($interaction->messageTs !== null) {
                    $target = new PostedMessage($channel->value, $interaction->messageTs);
                    if ($reply->replacement !== null) {
                        $this->api->updateMessage($interaction->installation, $target, $reply->replacement);
                    } elseif ($reply->deleteOriginal) {
                        $this->api->deleteMessage($interaction->installation, $target);
                    }
                }
            } catch (SlackApiException $e) {
                $this->logger->info('Slack interaction answer failed.', ['action_id' => $interaction->actionId, 'error' => $e->errorCode]);
            }
        }
    }

    /**
     * Number of queued replies (tests, diagnostics).
     */
    public function count(): int
    {
        return count($this->pending);
    }

    /**
     * Drops queued replies (worker reset).
     */
    public function reset(): void
    {
        $this->pending = [];
    }
}
