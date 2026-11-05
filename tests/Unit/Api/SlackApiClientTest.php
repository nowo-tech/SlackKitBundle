<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Api;

use DateTimeImmutable;
use Nowo\SlackKitBundle\Api\ApiPolicy;
use Nowo\SlackKitBundle\Api\ClientMemory;
use Nowo\SlackKitBundle\Api\EmailLookupDisabledException;
use Nowo\SlackKitBundle\Api\PostedMessage;
use Nowo\SlackKitBundle\Api\SlackApiClient;
use Nowo\SlackKitBundle\Api\SlackApiException;
use Nowo\SlackKitBundle\Api\SlackAuthException;
use Nowo\SlackKitBundle\Api\SlackMessage;
use Nowo\SlackKitBundle\Api\SlackRateLimitedException;
use Nowo\SlackKitBundle\Api\SlackScopeException;
use Nowo\SlackKitBundle\Credentials\InMemoryInstallationStore;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Event\SlackInstallationUnhealthyEvent;
use Nowo\SlackKitBundle\Id\SlackChannelId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use Nowo\SlackKitBundle\Tests\Support\RecordingDispatcher;
use Nowo\SlackKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Throwable;

final class SlackApiClientTest extends TestCase
{
    private RecordingDispatcher $events;
    private RecordingLogger $logger;
    private ArrayAdapter $cache;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->events = new RecordingDispatcher();
        $this->logger = new RecordingLogger();
        $this->cache  = new ArrayAdapter();
        $this->clock  = new MockClock('@' . Fake::NOW);
    }

    public function testCallsUseTheTokenHeaderOnlyJsonOrFormAndNoRedirects(): void
    {
        $calls = [];
        $http  = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = ['url' => $url, 'options' => $options];

            return match (true) {
                str_ends_with($url, 'auth.test')           => Fake::ok(['team' => 'Fake team', 'team_id' => Fake::TEAM, 'user' => 'fakebot', 'user_id' => 'U0000000B', 'bot_id' => 'B00000000']),
                str_ends_with($url, 'conversations.open')  => Fake::ok(['channel' => ['id' => Fake::DM]]),
                str_ends_with($url, 'chat.postMessage')    => Fake::ok(['channel' => Fake::DM, 'ts' => '1.000100']),
                str_ends_with($url, 'users.lookupByEmail') => Fake::ok(['user' => ['id' => Fake::USER]]),
                default                                    => Fake::ok(),
            };
        });
        $client = $this->client($http);
        $key    = SlackInstallationKey::default();

        $auth = $client->authTest($key);
        self::assertSame(['Fake team', Fake::TEAM, 'fakebot', 'U0000000B', 'B00000000'], [$auth->team, $auth->teamId, $auth->user, $auth->userId, $auth->botId]);
        $dm = $client->openDirectMessage($key, SlackUserId::fromString(Fake::USER));
        self::assertSame(Fake::DM, $dm->value);
        $posted = $client->postMessage($key, $dm, SlackMessage::plain('hi'));
        self::assertSame([Fake::DM, '1.000100'], [$posted->channel, $posted->ts]);
        $client->updateMessage($key, $posted, SlackMessage::plain('edited'));
        $client->deleteMessage($key, $posted);
        $client->postEphemeral($key, $dm, SlackUserId::fromString(Fake::USER), SlackMessage::plain('only you'));
        self::assertSame(Fake::USER, $client->lookupUserByEmail($key, 'member@example.test')?->value);

        self::assertCount(7, $calls);
        foreach ($calls as $call) {
            self::assertStringStartsWith('https://slack.com/api/', $call['url']);
            self::assertContains('Authorization: Bearer ' . Fake::TOKEN, $call['options']['headers']);
            self::assertSame(0, $call['options']['max_redirects']);
            self::assertStringNotContainsString(Fake::TOKEN, $call['url'] . ($call['options']['body'] ?? ''));
        }
        self::assertSame('', $calls[0]['options']['body']);
        self::assertSame('email=member%40example.test', $calls[6]['options']['body']);
        self::assertSame('{"users":"U00000001"}', $calls[1]['options']['body']);
        self::assertStringContainsString('"unfurl_links":false', $calls[2]['options']['body']);
        self::assertStringNotContainsString('unfurl', $calls[3]['options']['body'], 'chat.update takes no unfurl flags');
    }

    public function testPostMessageKeepsTheChannelWhenSlackOmitsIt(): void
    {
        $client = $this->client(new MockHttpClient([Fake::ok(['ts' => '2.0'])]));
        self::assertSame(Fake::CHANNEL, $client->postMessage(SlackInstallationKey::default(), SlackChannelId::fromString(Fake::CHANNEL), SlackMessage::plain('x'))->channel);
    }

    /**
     * @return iterable<string, array{0: MockResponse, 1: class-string<Throwable>, 2: string}>
     */
    public static function failures(): iterable
    {
        yield 'ok false' => [Fake::error('channel_not_found'), SlackApiException::class, 'channel_not_found'];
        yield 'odd error' => [Fake::error('Bad <script>'), SlackApiException::class, 'unknown_error'];
        yield 'no error' => [new MockResponse('{"ok":false}'), SlackApiException::class, 'unknown_error'];
        yield 'not json' => [new MockResponse('<html>'), SlackApiException::class, 'invalid_response'];
        yield 'json scalar' => [new MockResponse('"x"'), SlackApiException::class, 'invalid_response'];
        yield 'scope' => [Fake::error('missing_scope', ['needed' => 'im:write']), SlackScopeException::class, 'missing_scope'];
        yield 'auth' => [Fake::error('invalid_auth'), SlackAuthException::class, 'invalid_auth'];
        yield 'transport' => [new MockResponse('', ['error' => 'host unreachable']), SlackApiException::class, 'transport_error'];
        yield 'too large' => [new MockResponse(str_repeat('x', 70_000)), SlackApiException::class, 'transport_error'];
    }

    /**
     * @param class-string<Throwable> $class
     */
    #[DataProvider('failures')]
    public function testMapsFailures(MockResponse $response, string $class, string $code): void
    {
        try {
            $this->client(new MockHttpClient([$response]), new ApiPolicy(maxResponseBytes: 65_536))->openDirectMessage(SlackInstallationKey::default(), SlackUserId::fromString(Fake::USER));
            self::fail('no exception');
        } catch (SlackApiException $e) {
            self::assertInstanceOf($class, $e);
            self::assertSame($code, $e->errorCode);
            self::assertSame('Slack API error: ' . $code, $e->getMessage());
            for ($t = $e; $t instanceof Throwable; $t = $t->getPrevious()) {
                self::assertStringNotContainsString(Fake::TOKEN, $t->getMessage());
            }
        }
    }

    public function testScopeExceptionKeepsOnlyAWellFormedScope(): void
    {
        self::assertSame('im:write', (new SlackScopeException('im:write'))->neededScope);
        self::assertNull((new SlackScopeException('<x>'))->neededScope);
    }

    public function testInvalidResponses(): void
    {
        foreach (['openDirectMessage' => Fake::ok(['channel' => 'x']), 'postMessage' => Fake::ok([])] as $method => $response) {
            try {
                $client = $this->client(new MockHttpClient([$response]));
                $method === 'openDirectMessage'
                    ? $client->openDirectMessage(SlackInstallationKey::default(), SlackUserId::fromString(Fake::USER))
                    : $client->postMessage(SlackInstallationKey::default(), SlackChannelId::fromString(Fake::CHANNEL), SlackMessage::plain('x'));
                self::fail('accepted');
            } catch (SlackApiException $e) {
                self::assertSame('invalid_response', $e->errorCode);
            }
        }
    }

    public function testRateLimitIsThrownRememberedAndClamped(): void
    {
        $http   = new MockHttpClient([new MockResponse('', ['http_code' => 429, 'response_headers' => ['retry-after' => '9999']])]);
        $client = $this->client($http, new ApiPolicy(maxRetryAfter: 120));
        try {
            $client->authTest(SlackInstallationKey::default());
            self::fail('no exception');
        } catch (SlackRateLimitedException $e) {
            self::assertSame(120, $e->retryAfterSeconds());
        }
        // Remembered: no second HTTP call (the mock would fail with no more responses).
        try {
            $client->authTest(SlackInstallationKey::default());
            self::fail('no exception');
        } catch (SlackRateLimitedException $e) {
            self::assertSame(120, $e->retryAfterSeconds);
        }
        $this->clock->sleep(121);
        $this->expectException(SlackApiException::class);
        $client->authTest(SlackInstallationKey::default()); // asks Slack again; the mock has no response left
    }

    public function testRateLimitWithoutRetryAfterAndRatelimitedError(): void
    {
        try {
            $this->client(new MockHttpClient([new MockResponse('', ['http_code' => 429])]))->authTest(SlackInstallationKey::default());
        } catch (SlackRateLimitedException $e) {
            self::assertSame(30, $e->retryAfterSeconds);
        }
        try {
            $this->client(new MockHttpClient([Fake::error('ratelimited')]))->deleteMessage(SlackInstallationKey::default(), new PostedMessage(Fake::CHANNEL, '1.0'));
        } catch (SlackRateLimitedException $e) {
            self::assertSame(30, $e->retryAfterSeconds);
        }
        self::assertSame(1, (new SlackRateLimitedException(0))->retryAfterSeconds);
    }

    public function testAuthErrorsReportTheInstallationOncePerInterval(): void
    {
        $client = $this->client(new MockHttpClient([Fake::error('token_revoked'), Fake::error('token_revoked')]));
        for ($i = 0; $i < 2; ++$i) {
            try {
                $client->authTest(SlackInstallationKey::default());
            } catch (SlackAuthException) {
            }
        }
        $events = $this->events->of(SlackInstallationUnhealthyEvent::class);
        self::assertCount(1, $events);
        self::assertSame('token_revoked', $events[0]->errorCode);
    }

    public function testNotInstalledAndRevoked(): void
    {
        $empty = new SlackApiClient(new MockHttpClient(), new InMemoryInstallationStore(), new ApiPolicy(), new ClientMemory($this->cache), $this->clock);
        try {
            $empty->authTest(SlackInstallationKey::default());
            self::fail('no exception');
        } catch (SlackApiException $e) {
            self::assertSame('not_installed', $e->errorCode);
        }

        $store = Fake::store();
        $store->markRevoked(SlackInstallationKey::default(), new DateTimeImmutable());
        $this->expectExceptionMessage('Slack API error: not_installed');
        (new SlackApiClient(new MockHttpClient(), $store, new ApiPolicy(), new ClientMemory($this->cache), $this->clock))->authTest(SlackInstallationKey::default());
    }

    public function testEmailLookup(): void
    {
        $client = $this->client(new MockHttpClient([Fake::error('users_not_found'), Fake::error('fatal_error'), Fake::ok(['user' => 'x'])]));
        self::assertNull($client->lookupUserByEmail(SlackInstallationKey::default(), 'nobody@example.test'));
        try {
            $client->lookupUserByEmail(SlackInstallationKey::default(), 'x@example.test');
            self::fail('no exception');
        } catch (SlackApiException $e) {
            self::assertSame('fatal_error', $e->errorCode);
        }
        self::assertNull($client->lookupUserByEmail(SlackInstallationKey::default(), 'x@example.test'));

        $this->expectException(EmailLookupDisabledException::class);
        (new SlackApiClient(new MockHttpClient(), Fake::store(true, false), new ApiPolicy(), new ClientMemory($this->cache), $this->clock))->lookupUserByEmail(SlackInstallationKey::default(), 'x@example.test');
    }

    private function client(MockHttpClient $http, ?ApiPolicy $policy = null): SlackApiClient
    {
        return new SlackApiClient($http, Fake::store(), $policy ?? new ApiPolicy(), new ClientMemory($this->cache), $this->clock, $this->events, $this->logger);
    }
}
