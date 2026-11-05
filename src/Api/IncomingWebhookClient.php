<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Api;

use Nowo\SlackKitBundle\Security\SlackSecret;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function ctype_digit;
use function max;
use function min;
use function preg_match;
use function trim;

/**
 * Posts to a Slack incoming webhook (`https://hooks.slack.com/services/…` only, enforced by {@see SlackSecret::webhookUrl()}):
 * no redirects, timeouts, a response cap, and Slack's plain-text error codes mapped to {@see SlackApiException}.
 */
final readonly class IncomingWebhookClient
{
    /**
     * @param HttpClientInterface $http Symfony HttpClient
     * @param ApiPolicy $policy timeouts and caps
     */
    public function __construct(
        private HttpClientInterface $http,
        private ApiPolicy $policy,
    ) {
    }

    /**
     * Posts the message.
     *
     * @param SlackSecret $webhookUrl the destination's webhook URL ({@see SlackSecret::webhookUrl()})
     *
     * @throws SlackRateLimitedException on HTTP 429
     * @throws SlackApiException on any other failure (`invalid_payload`, `no_service`, `http_<status>`…)
     */
    public function post(SlackSecret $webhookUrl, SlackMessage $message): void
    {
        if ($webhookUrl->kind !== SlackSecret::WEBHOOK_URL) {
            throw new SlackApiException('invalid_webhook_url');
        }
        $cap = $this->policy->maxResponseBytes;
        try {
            $response = $this->http->request('POST', $webhookUrl->reveal(), [
                'json'          => $message->toArguments(),
                'timeout'       => $this->policy->timeout,
                'max_duration'  => $this->policy->maxDuration,
                'max_redirects' => 0,
                // @igor-ignore - Per-request HttpClient option; captures only the immutable byte cap, no request state.
                'on_progress' => static function (int $downloaded, int $total) use ($cap): void {
                    if ($downloaded > $cap || $total > $cap) {
                        throw new SlackApiException('response_too_large');
                    }
                },
            ]);
            $status = $response->getStatusCode();
            if ($status === 429) {
                $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? '';

                throw new SlackRateLimitedException(min($this->policy->maxRetryAfter, ctype_digit($retryAfter) ? max(1, (int) $retryAfter) : SlackApiClient::DEFAULT_RETRY_AFTER));
            }
            $body = trim($response->getContent(false));
        } catch (ExceptionInterface $e) {
            throw new SlackApiException('transport_error', $e);
        }
        if ($status === 200) {
            return;
        }

        throw new SlackApiException(preg_match('/^[a-z0-9_]{1,64}$/', $body) === 1 ? $body : 'http_' . $status);
    }
}
