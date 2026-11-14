<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Api;

use JsonException;
use Nowo\SlackKitBundle\Credentials\SlackInstallation;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Credentials\SlackInstallationStoreInterface;
use Nowo\SlackKitBundle\Event\SlackInstallationUnhealthyEvent;
use Nowo\SlackKitBundle\Id\SlackChannelId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function ctype_digit;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function max;
use function min;

use const JSON_THROW_ON_ERROR;

/**
 * The Slack Web API client (contract §4): constant base URL, token in the `Authorization` header only, no redirects,
 * idle and total timeouts, a response size cap, typed errors and rate limits that are thrown, never slept on.
 */
final readonly class SlackApiClient implements SlackApiClientInterface
{
    /** The only host the client talks to. */
    public const BASE_URL = 'https://slack.com/api/';

    /** Methods that take form arguments; the others take JSON. */
    public const FORM_METHODS = ['auth.test', 'users.lookupByEmail'];

    /** Default wait when Slack rate limits without `Retry-After`. */
    public const DEFAULT_RETRY_AFTER = 30;

    /**
     * @param HttpClientInterface $http Symfony HttpClient (a MockHttpClient in tests and the demo)
     * @param SlackInstallationStoreInterface $store installation store
     * @param ApiPolicy $policy timeouts and caps
     * @param ClientMemory $memory remembered rate limits and unhealthy reports
     * @param ClockInterface $clock clock
     * @param EventDispatcherInterface|null $events dispatcher of {@see SlackInstallationUnhealthyEvent}
     * @param LoggerInterface $logger PSR-3 logger (codes only)
     * @param string $userAgent `User-Agent` header
     */
    public function __construct(
        private HttpClientInterface $http,
        private SlackInstallationStoreInterface $store,
        private ApiPolicy $policy,
        private ClientMemory $memory,
        private ClockInterface $clock,
        private ?EventDispatcherInterface $events = null,
        private LoggerInterface $logger = new NullLogger(),
        private string $userAgent = 'nowo-slack-kit/1.0',
    ) {
    }

    /**
     * `auth.test`.
     */
    public function authTest(SlackInstallationKey $installation): AuthTestResult
    {
        $data = $this->call($installation, 'auth.test', []);

        return new AuthTestResult($this->string($data, 'team'), $this->string($data, 'team_id'), $this->string($data, 'user'), $this->string($data, 'user_id'), $this->string($data, 'bot_id'));
    }

    /**
     * `conversations.open`.
     */
    public function openDirectMessage(SlackInstallationKey $installation, SlackUserId $user): SlackChannelId
    {
        $data    = $this->call($installation, 'conversations.open', ['users' => $user->value]);
        $channel = $data['channel'] ?? null;

        return SlackChannelId::tryFrom(is_array($channel) ? ($channel['id'] ?? null) : null) ?? throw new SlackApiException('invalid_response');
    }

    /**
     * `chat.postMessage`.
     */
    public function postMessage(SlackInstallationKey $installation, SlackChannelId $channel, SlackMessage $message): PostedMessage
    {
        $data = $this->call($installation, 'chat.postMessage', ['channel' => $channel->value] + $message->toArguments());
        $ts   = $this->string($data, 'ts');
        if ($ts === '') {
            throw new SlackApiException('invalid_response');
        }

        return new PostedMessage($this->string($data, 'channel') !== '' ? $this->string($data, 'channel') : $channel->value, $ts);
    }

    /**
     * `chat.update`.
     */
    public function updateMessage(SlackInstallationKey $installation, PostedMessage $target, SlackMessage $message): void
    {
        $arguments = ['channel' => $target->channel, 'ts' => $target->ts] + $message->toArguments();
        unset($arguments['unfurl_links'], $arguments['unfurl_media'], $arguments['thread_ts']);
        $this->call($installation, 'chat.update', $arguments);
    }

    /**
     * `chat.delete`.
     */
    public function deleteMessage(SlackInstallationKey $installation, PostedMessage $target): void
    {
        $this->call($installation, 'chat.delete', ['channel' => $target->channel, 'ts' => $target->ts]);
    }

    /**
     * `chat.postEphemeral`.
     */
    public function postEphemeral(SlackInstallationKey $installation, SlackChannelId $channel, SlackUserId $user, SlackMessage $message): void
    {
        $arguments = ['channel' => $channel->value, 'user' => $user->value] + $message->toArguments();
        unset($arguments['unfurl_links'], $arguments['unfurl_media']);
        $this->call($installation, 'chat.postEphemeral', $arguments);
    }

    /**
     * `users.lookupByEmail`.
     */
    public function lookupUserByEmail(SlackInstallationKey $installation, string $email): ?SlackUserId
    {
        $resolved = $this->installation($installation);
        if (!$resolved->allowEmailLookup) {
            throw new EmailLookupDisabledException();
        }
        try {
            $data = $this->call($installation, 'users.lookupByEmail', ['email' => $email], $resolved);
        } catch (SlackApiException $e) {
            if ($e->errorCode === 'users_not_found') {
                return null;
            }
            throw $e;
        }
        $user = $data['user'] ?? null;

        return SlackUserId::tryFrom(is_array($user) ? ($user['id'] ?? null) : null);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function call(SlackInstallationKey $key, string $method, array $arguments, ?SlackInstallation $installation = null): array
    {
        $installation ??= $this->installation($key);
        $now = $this->clock->now()->getTimestamp();
        // Rate limits are remembered per installation (one bucket per app and workspace, spec 237 FR-018).
        $memoryKey = $installation->storageKey()->workspaceKey();
        $wait      = $this->memory->rateLimitedFor($memoryKey, $method, $now);
        if ($wait > 0) {
            throw new SlackRateLimitedException($wait);
        }

        $cap     = $this->policy->maxResponseBytes;
        $options = [
            'headers'       => ['Authorization' => 'Bearer ' . $installation->botToken->reveal(), 'User-Agent' => $this->userAgent],
            'timeout'       => $this->policy->timeout,
            'max_duration'  => $this->policy->maxDuration,
            'max_redirects' => 0,
            // @igor-ignore - Per-request HttpClient option; captures only the immutable byte cap, no request state.
            'on_progress' => static function (int $downloaded, int $total) use ($cap): void {
                if ($downloaded > $cap || $total > $cap) {
                    throw new SlackApiException('response_too_large');
                }
            },
        ];
        $options[in_array($method, self::FORM_METHODS, true) ? 'body' : 'json'] = $arguments;

        try {
            $response = $this->http->request('POST', self::BASE_URL . $method, $options);
            $status   = $response->getStatusCode();
            if ($status === 429) {
                $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? '';
                $seconds    = min($this->policy->maxRetryAfter, ctype_digit($retryAfter) ? max(1, (int) $retryAfter) : self::DEFAULT_RETRY_AFTER);
                $this->memory->rememberRateLimit($memoryKey, $method, $now, $seconds);
                $this->logger->warning('Slack rate limited a call.', ['installation' => $key->key(), 'method' => $method, 'retry_after' => $seconds]);

                throw new SlackRateLimitedException($seconds);
            }
            $content = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw new SlackApiException('transport_error', RedactedTransportException::from($e));
        }

        try {
            $data = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SlackApiException('invalid_response', $e);
        }
        if (!is_array($data)) {
            throw new SlackApiException('invalid_response');
        }
        /** @var array<string, mixed> $data */
        if (($data['ok'] ?? null) === true) {
            return $data;
        }

        $error = is_string($data['error'] ?? null) ? $data['error'] : 'unknown_error';
        $this->logger->info('Slack refused a call.', ['installation' => $key->key(), 'method' => $method, 'error' => (new SlackApiException($error))->errorCode]);
        if ($error === 'ratelimited') {
            $seconds = min($this->policy->maxRetryAfter, self::DEFAULT_RETRY_AFTER);
            $this->memory->rememberRateLimit($memoryKey, $method, $now, $seconds);

            throw new SlackRateLimitedException($seconds);
        }
        if (in_array($error, SlackAuthException::CODES, true)) {
            if ($this->events instanceof EventDispatcherInterface && $this->memory->shouldReportUnhealthy($memoryKey)) {
                $this->events->dispatch(new SlackInstallationUnhealthyEvent($key, $error));
            }

            throw new SlackAuthException($error);
        }
        if ($error === 'missing_scope') {
            throw new SlackScopeException($data['needed'] ?? null);
        }

        throw new SlackApiException($error);
    }

    /**
     * The installation of the key. A store answer for another workspace (a store that ignores part of the key) is
     * refused like a missing installation: one workspace's token never serves another (spec 237).
     */
    private function installation(SlackInstallationKey $key): SlackInstallation
    {
        $installation = $this->store->find($key);
        if (!$installation instanceof SlackInstallation || $installation->isRevoked() || !$key->admits($installation)) {
            throw new SlackApiException('not_installed');
        }
        if ($installation->isTokenExpired($this->clock->now())) {
            throw new SlackAuthException('token_expired');
        }

        return $installation;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function string(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : '';
    }
}
