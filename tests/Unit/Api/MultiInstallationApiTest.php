<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Api;

use DateTimeImmutable;
use Nowo\SlackKitBundle\Api\ApiPolicy;
use Nowo\SlackKitBundle\Api\ClientMemory;
use Nowo\SlackKitBundle\Api\PostedMessage;
use Nowo\SlackKitBundle\Api\SlackApiClient;
use Nowo\SlackKitBundle\Api\SlackApiException;
use Nowo\SlackKitBundle\Api\SlackAuthException;
use Nowo\SlackKitBundle\Api\SlackMessage;
use Nowo\SlackKitBundle\Api\SlackRateLimitedException;
use Nowo\SlackKitBundle\Credentials\SlackInstallation;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Credentials\SlackInstallationStoreInterface;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Spec 237 T000a: the Web API client per installation — each workspace's token, and rate-limit memory per workspace.
 */
final class MultiInstallationApiTest extends TestCase
{
    public function testEveryCallUsesItsOwnWorkspaceToken(): void
    {
        $auth   = [];
        $client = $this->client($this->store(), static function (string $method, string $url, array $options) use (&$auth): MockResponse {
            $auth[] = $options['normalized_headers']['authorization'][0] ?? '';

            return Fake::ok();
        });

        $client->updateMessage($this->key(Fake::TEAM), new PostedMessage(Fake::CHANNEL, '1.0'), SlackMessage::plain('A'));
        $client->updateMessage($this->key(Fake::OTHER_TEAM), new PostedMessage(Fake::CHANNEL, '1.0'), SlackMessage::plain('B'));

        self::assertSame(['Authorization: Bearer ' . Fake::TOKEN, 'Authorization: Bearer ' . Fake::OTHER_TOKEN], $auth);
    }

    public function testAStoreAnswerForAnotherWorkspaceIsNeverUsed(): void
    {
        // A host store that ignores the workspace and always answers its first row.
        $careless = new class implements SlackInstallationStoreInterface {
            public function find(SlackInstallationKey $key): SlackInstallation
            {
                return Fake::installation(Fake::TEAM, Fake::TOKEN);
            }

            public function findSigningSecret(string $app): ?SlackSecret
            {
                return null;
            }

            public function save(SlackInstallation $installation): void
            {
            }

            public function markRevoked(SlackInstallationKey $key, DateTimeImmutable $at): void
            {
            }
        };
        $called = false;
        $client = $this->client($careless, static function () use (&$called): MockResponse {
            $called = true;

            return Fake::ok();
        });

        try {
            $client->updateMessage($this->key(Fake::OTHER_TEAM), new PostedMessage(Fake::CHANNEL, '1.0'), SlackMessage::plain('B'));
            self::fail('Another workspace must not be served.');
        } catch (SlackApiException $e) {
            self::assertSame('not_installed', $e->errorCode);
        }
        self::assertFalse($called, 'no request was sent with the other workspace token');
    }

    public function testRateLimitsAreRememberedPerWorkspace(): void
    {
        $client = $this->client($this->store(), static fn (string $method, string $url, array $options): MockResponse => ($options['normalized_headers']['authorization'][0] ?? '') === 'Authorization: Bearer ' . Fake::TOKEN
            ? new MockResponse('', ['http_code' => 429, 'response_headers' => ['retry-after' => '30']])
            : Fake::ok());

        try {
            $client->deleteMessage($this->key(Fake::TEAM), new PostedMessage(Fake::CHANNEL, '1.0'));
            self::fail('Rate limited.');
        } catch (SlackRateLimitedException $e) {
            self::assertSame(30, $e->retryAfterSeconds);
        }
        $client->deleteMessage($this->key(Fake::OTHER_TEAM), new PostedMessage(Fake::CHANNEL, '1.0'));
        $this->addToAssertionCount(1); // The other workspace is not blocked by the first one's 429.

        $this->expectException(SlackRateLimitedException::class);
        $client->deleteMessage($this->key(Fake::TEAM), new PostedMessage(Fake::CHANNEL, '1.0'));
    }

    public function testAnExpiredRotatingTokenIsNotSent(): void
    {
        $store = $this->store();
        $team  = SlackTeamId::fromString(Fake::OTHER_TEAM);
        $store->save(new SlackInstallation($this->key(Fake::OTHER_TEAM), SlackSecret::botToken(Fake::OTHER_TOKEN), $team, refreshToken: SlackSecret::refreshToken(Fake::REFRESH_TOKEN), tokenExpiresAt: new DateTimeImmutable('@' . Fake::NOW)));
        $client = $this->client($store, static fn (): MockResponse => Fake::ok());

        $this->expectException(SlackAuthException::class);
        $this->expectExceptionMessage('token_expired');
        $client->deleteMessage($this->key(Fake::OTHER_TEAM), new PostedMessage(Fake::CHANNEL, '1.0'));
    }

    private function store(): SlackInstallationStoreInterface
    {
        $store = Fake::store();
        $store->save(Fake::installation(Fake::OTHER_TEAM, Fake::OTHER_TOKEN));

        return $store;
    }

    private function key(string $team): SlackInstallationKey
    {
        return new SlackInstallationKey('default', SlackTeamId::fromString($team));
    }

    private function client(SlackInstallationStoreInterface $store, callable $responder): SlackApiClient
    {
        return new SlackApiClient(new MockHttpClient($responder), $store, new ApiPolicy(), new ClientMemory(new ArrayAdapter()), new MockClock('@' . Fake::NOW));
    }
}
