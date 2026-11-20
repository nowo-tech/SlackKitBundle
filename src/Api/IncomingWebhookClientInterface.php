<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Api;

use Nowo\SlackKitBundle\Security\SlackSecret;

/**
 * Posts to a Slack incoming webhook (`https://hooks.slack.com/services/…` only). Hosts type-hint this interface
 * instead of the final {@see IncomingWebhookClient}, so they can substitute a test double.
 */
interface IncomingWebhookClientInterface
{
    /**
     * Posts the message.
     *
     * @param SlackSecret $webhookUrl the destination's webhook URL ({@see SlackSecret::webhookUrl()})
     *
     * @throws SlackRateLimitedException on HTTP 429
     * @throws SlackApiException on any other failure (`invalid_payload`, `no_service`, `http_<status>`…)
     */
    public function post(SlackSecret $webhookUrl, SlackMessage $message): void;
}
