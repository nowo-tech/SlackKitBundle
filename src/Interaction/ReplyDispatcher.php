<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Interaction;

use Nowo\SlackKitBundle\Api\PostedMessage;
use Nowo\SlackKitBundle\Api\ResponseUrlClientInterface;
use Nowo\SlackKitBundle\Api\SlackApiClientInterface;
use Nowo\SlackKitBundle\Api\SlackApiException;
use Nowo\SlackKitBundle\Api\SlackMessage;
use Nowo\SlackKitBundle\Api\SlackResponseUrlReply;
use Nowo\SlackKitBundle\Id\SlackChannelId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Service\ResetInterface;

use function count;

/**
 * Makes the Slack calls of interaction replies after the response is sent (`kernel.terminate`), so a slow Slack call
 * never pushes an interaction past Slack's 3-second limit. Best effort: failures are logged with their code.
 *
 * Web API answers use the click's own installation (its workspace's token, spec 237). Interactions verified with a
 * host-resolved secret get no Web API calls (the token belongs to another workspace); a reply sent
 * {@see SlackInteractionReply::viaResponseUrl()} goes to the click's `response_url` instead, which needs no token.
 */
final class ReplyDispatcher implements ResetInterface
{
    /** @var list<array{0: SlackInteraction, 1: SlackInteractionReply, 2: SlackSecret|null}> */
    private array $pending = [];

    /**
     * @param SlackApiClientInterface $api Web API client
     * @param LoggerInterface $logger PSR-3 logger
     * @param ResponseUrlClientInterface|null $responses `response_url` client
     */
    public function __construct(
        private readonly SlackApiClientInterface $api,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?ResponseUrlClientInterface $responses = null,
    ) {
    }

    /**
     * Queues the reply's Slack calls.
     */
    public function schedule(SlackInteraction $interaction, SlackInteractionReply $reply): void
    {
        if (!$reply->ephemeral instanceof SlackMessage && !$reply->replacement instanceof SlackMessage && !$reply->deleteOriginal) {
            return;
        }
        $url = $reply->viaResponseUrl && $this->responses instanceof ResponseUrlClientInterface ? $interaction->responseUrl : null;
        if ($interaction->verifiedByResolver && !$url instanceof SlackSecret) {
            return;
        }
        // @igor-ignore - Unit-of-work queue: flushed at kernel.terminate / console.terminate and cleared by reset().
        $this->pending[] = [$interaction, $reply, $url];
    }

    /**
     * Performs the queued calls (listener of `kernel.terminate` and `console.terminate`).
     */
    public function flush(): void
    {
        $pending       = $this->pending;
        $this->pending = [];
        foreach ($pending as [$interaction, $reply, $url]) {
            try {
                if ($url instanceof SlackSecret) {
                    $this->respond($url, $reply);
                } else {
                    $this->callApi($interaction, $reply);
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

    private function respond(SlackSecret $url, SlackInteractionReply $reply): void
    {
        if ($reply->ephemeral instanceof SlackMessage) {
            $this->responses?->respond($url, SlackResponseUrlReply::ephemeral($reply->ephemeral));
        }
        if ($reply->replacement instanceof SlackMessage) {
            $this->responses?->respond($url, SlackResponseUrlReply::replace($reply->replacement));
        } elseif ($reply->deleteOriginal) {
            $this->responses?->respond($url, SlackResponseUrlReply::delete());
        }
    }

    private function callApi(SlackInteraction $interaction, SlackInteractionReply $reply): void
    {
        $channel = $interaction->channel;
        if (!$channel instanceof SlackChannelId) {
            return;
        }
        if ($reply->ephemeral instanceof SlackMessage && $interaction->user instanceof SlackUserId) {
            $this->api->postEphemeral($interaction->installation, $channel, $interaction->user, $reply->ephemeral);
        }
        if ($interaction->messageTs !== null) {
            $target = new PostedMessage($channel->value, $interaction->messageTs);
            if ($reply->replacement instanceof SlackMessage) {
                $this->api->updateMessage($interaction->installation, $target, $reply->replacement);
            } elseif ($reply->deleteOriginal) {
                $this->api->deleteMessage($interaction->installation, $target);
            }
        }
    }
}
