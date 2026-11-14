<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Api;

use JsonException;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function ctype_digit;
use function is_array;
use function is_string;
use function json_decode;
use function max;
use function min;
use function preg_match;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Posts to an interaction `response_url` (`https://hooks.slack.com/actions/…` only, enforced by
 * {@see SlackSecret::responseUrl()}): no redirects, timeouts, a response cap, and Slack's error codes mapped to
 * {@see SlackApiException}. Uses the logger-free HttpClient of incoming webhooks: the URL is a credential.
 */
final readonly class ResponseUrlClient implements ResponseUrlClientInterface
{
    /**
     * @param HttpClientInterface $http Symfony HttpClient (no logger, not traced)
     * @param ApiPolicy $policy timeouts and caps
     */
    public function __construct(
        private HttpClientInterface $http,
        private ApiPolicy $policy,
    ) {
    }

    /**
     * Posts the reply.
     */
    public function respond(SlackSecret $responseUrl, SlackResponseUrlReply $reply): void
    {
        if ($responseUrl->kind !== SlackSecret::RESPONSE_URL) {
            throw new SlackApiException('invalid_response_url');
        }
        $cap = $this->policy->maxResponseBytes;
        try {
            $response = $this->http->request('POST', $responseUrl->reveal(), [
                'json'          => $reply->toBody(),
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
            throw new SlackApiException('transport_error', RedactedTransportException::from($e));
        }

        $error = $this->error($body);
        if ($status === 200 && $error === null) {
            return;
        }

        throw new SlackApiException($error ?? 'http_' . $status);
    }

    /**
     * The error code of an answer: `{"ok":false,"error":"…"}` or a plain-text code; null for a success.
     */
    private function error(string $body): ?string
    {
        try {
            $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $body === 'ok' || $body === '' ? null : (preg_match('/^[a-z0-9_]{1,64}$/', $body) === 1 ? $body : 'invalid_response');
        }
        if (!is_array($data) || ($data['ok'] ?? true) !== false) {
            return null;
        }

        return is_string($data['error'] ?? null) && preg_match('/^[a-z0-9_]{1,64}$/', $data['error']) === 1 ? $data['error'] : 'unknown_error';
    }
}
