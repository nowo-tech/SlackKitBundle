<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Api;

use Nowo\SlackKitBundle\Interaction\SlackInteractionReply;
use Nowo\SlackKitBundle\Security\SlackSecret;

/**
 * Answers an interaction through its `response_url` (spec 237 T000c): no bot token, valid for 30 minutes after the
 * click. Hosts with their own handlers may use it directly; the reply dispatcher uses it for
 * {@see SlackInteractionReply::viaResponseUrl()}.
 */
interface ResponseUrlClientInterface
{
    /**
     * Posts the reply to the response URL.
     *
     * @param SlackSecret $responseUrl the click's `response_url` ({@see SlackSecret::responseUrl()})
     *
     * @throws SlackRateLimitedException on HTTP 429
     * @throws SlackApiException on any other failure (`expired_url`, `http_<status>`…)
     */
    public function respond(SlackSecret $responseUrl, SlackResponseUrlReply $reply): void;
}
