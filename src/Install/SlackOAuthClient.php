<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Install;

use JsonException;
use Nowo\SlackKitBundle\Api\ApiPolicy;
use Nowo\SlackKitBundle\Api\RedactedTransportException;
use Nowo\SlackKitBundle\Api\SlackApiClient;
use Nowo\SlackKitBundle\Api\SlackApiException;
use Nowo\SlackKitBundle\Id\SlackEnterpriseId;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function explode;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function preg_match;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * `oauth.v2.access` on `https://slack.com/api/` only: client credentials in the HTTP Basic header, no redirects,
 * timeouts and a response cap. Uses the logger-free HttpClient: the answer holds the bot token.
 */
final readonly class SlackOAuthClient implements SlackOAuthClientInterface
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
     * Exchanges the code.
     */
    public function exchange(SlackOAuthExchange $exchange): SlackOAuthGrant
    {
        $cap = $this->policy->maxResponseBytes;
        try {
            $response = $this->http->request('POST', SlackApiClient::BASE_URL . 'oauth.v2.access', [
                'auth_basic'    => [$exchange->clientId, $exchange->clientSecret->reveal()],
                'body'          => ['code' => $exchange->code, 'redirect_uri' => $exchange->redirectUri],
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
            $content = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw new SlackApiException('transport_error', RedactedTransportException::from($e));
        }
        try {
            $data = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SlackApiException('invalid_response', $e);
        }
        if (!is_array($data)) {
            throw new SlackApiException('invalid_response');
        }
        if (($data['ok'] ?? null) !== true) {
            throw new SlackApiException(is_string($data['error'] ?? null) ? $data['error'] : 'unknown_error');
        }

        return $this->grant($data);
    }

    /**
     * @param array<mixed> $data
     */
    private function grant(array $data): SlackOAuthGrant
    {
        $team       = is_array($data['team'] ?? null) ? $data['team'] : [];
        $enterprise = is_array($data['enterprise'] ?? null) ? $data['enterprise'] : [];
        $authed     = is_array($data['authed_user'] ?? null) ? $data['authed_user'] : [];
        $scopes     = [];
        foreach (explode(',', is_string($data['scope'] ?? null) ? $data['scope'] : '') as $scope) {
            $scope = trim($scope);
            if (preg_match('/^[a-z][a-z0-9_.:]{0,63}$/', $scope) === 1) {
                $scopes[] = $scope;
            }
        }
        $appId = is_string($data['app_id'] ?? null) && preg_match('/^A[A-Z0-9]{2,30}$/', $data['app_id']) === 1 ? $data['app_id'] : null;
        $bot   = is_string($data['bot_user_id'] ?? null) && preg_match('/^[UWB][A-Z0-9]{2,30}$/', $data['bot_user_id']) === 1 ? $data['bot_user_id'] : null;

        return new SlackOAuthGrant(
            $appId,
            SlackTeamId::tryFrom($team['id'] ?? null),
            is_string($team['name'] ?? null) ? $team['name'] : '',
            SlackEnterpriseId::tryFrom($enterprise['id'] ?? null),
            ($data['is_enterprise_install'] ?? false) === true,
            ($data['token_type'] ?? null) === 'bot' ? SlackSecret::tryBotToken(is_string($data['access_token'] ?? null) ? $data['access_token'] : null) : null,
            $bot,
            $scopes,
            SlackUserId::tryFrom($authed['id'] ?? null),
            isset($data['refresh_token']) || is_int($data['expires_in'] ?? null),
        );
    }
}
