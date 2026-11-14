<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Support;

use Nowo\SlackKitBundle\Credentials\InMemoryInstallationStore;
use Nowo\SlackKitBundle\Credentials\SlackInstallation;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Id\SlackEnterpriseId;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Ingress\SignatureVerifier;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fake Slack values (owner rule: never a real workspace, token or secret; contract §17). None of them has the shape
 * secret scanners look for.
 */
final class Fake
{
    public const TOKEN            = 'xoxb-test-not-a-real-token';
    public const SIGNING_SECRET   = 'testsigningsecretnotreal00000000';
    public const OTHER_SECRET     = 'othersigningsecretnotreal0000000';
    public const KERNEL_SECRET    = 'test-secret-not-real';
    public const TEAM             = 'T00000000';
    public const OTHER_TEAM       = 'T00000001';
    public const USER             = 'U00000001';
    public const OTHER_USER       = 'U00000002';
    public const CHANNEL          = 'C00000001';
    public const DM               = 'D00000001';
    public const APP_ID           = 'A00000000';
    public const NOW              = 1_793_836_800; // 2026-11-05T00:00:00Z
    public const WEBHOOK          = 'https://hooks.slack.com/services/TFAKE0000/BFAKE0000/fakefakefakefakefake';
    public const OTHER_TOKEN      = 'xoxb-test-not-a-real-token-two';
    public const OTHER_APP_ID     = 'A00000001';
    public const ENTERPRISE       = 'E00000000';
    public const OTHER_ENTERPRISE = 'E00000001';
    public const RESPONSE_URL     = 'https://hooks.slack.com/actions/TFAKE0000/0000000000/fakefakefakefake';
    public const CLIENT_ID        = '1111111111.2222222222';
    public const CLIENT_SECRET    = 'testclientsecretnotreal000000000';
    public const REFRESH_TOKEN    = 'xoxe-1-test-not-a-real-refresh';

    public static function store(bool $withTeam = true, bool $emailLookup = true): InMemoryInstallationStore
    {
        return new InMemoryInstallationStore(
            [SlackInstallationKey::DEFAULT_APP => SlackSecret::signingSecret(self::SIGNING_SECRET)],
            [new SlackInstallation(SlackInstallationKey::default(), SlackSecret::botToken(self::TOKEN), $withTeam ? SlackTeamId::fromString(self::TEAM) : null, $emailLookup)],
        );
    }

    /**
     * The fake installation of a workspace.
     */
    public static function installation(string $team = self::OTHER_TEAM, string $token = self::OTHER_TOKEN, ?string $enterprise = null, ?string $appId = null, ?string $ownSecret = null, ?string $botUserId = null): SlackInstallation
    {
        $teamId = SlackTeamId::fromString($team);
        $ent    = $enterprise !== null ? SlackEnterpriseId::fromString($enterprise) : null;

        return new SlackInstallation(
            new SlackInstallationKey(SlackInstallationKey::DEFAULT_APP, $teamId, $ent),
            SlackSecret::botToken($token),
            $teamId,
            false,
            $botUserId,
            null,
            $ent,
            $appId,
            signingSecret: $ownSecret !== null ? SlackSecret::signingSecret($ownSecret) : null,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function ok(array $body = []): MockResponse
    {
        return new MockResponse((string) json_encode(['ok' => true] + $body), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function error(string $code, array $extra = []): MockResponse
    {
        return new MockResponse((string) json_encode(['ok' => false, 'error' => $code] + $extra), ['http_code' => 200]);
    }

    /**
     * Headers of a request signed by the fake app at `$timestamp`.
     *
     * @return array<string, string>
     */
    public static function signed(string $body, int $timestamp = self::NOW, string $secret = self::SIGNING_SECRET): array
    {
        return [
            'HTTP_X_SLACK_REQUEST_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_SLACK_SIGNATURE'         => SignatureVerifier::sign(SlackSecret::signingSecret($secret), (string) $timestamp, $body),
        ];
    }

    /**
     * A `block_actions` payload.
     *
     * @return array<string, mixed>
     */
    public static function blockActions(string $actionId, string $value, string $team = self::TEAM, string $user = self::USER): array
    {
        return [
            'type'       => 'block_actions',
            'api_app_id' => self::APP_ID,
            'team'       => ['id' => $team],
            'user'       => ['id' => $user],
            'container'  => ['type' => 'message', 'channel_id' => self::DM, 'message_ts' => '1793836800.000100'],
            'channel'    => ['id' => self::DM],
            'actions'    => [['type' => 'button', 'action_id' => $actionId, 'value' => $value, 'action_ts' => '1793836801.000200']],
        ];
    }

    /**
     * Form body of an interaction payload.
     *
     * @param array<string, mixed> $payload
     */
    public static function form(array $payload): string
    {
        return http_build_query(['payload' => json_encode($payload)]);
    }
}
