<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Api;

use InvalidArgumentException;
use Nowo\SlackKitBundle\Api\ApiPolicy;
use Nowo\SlackKitBundle\Api\ResponseUrlClient;
use Nowo\SlackKitBundle\Api\SlackApiException;
use Nowo\SlackKitBundle\Api\SlackMessage;
use Nowo\SlackKitBundle\Api\SlackRateLimitedException;
use Nowo\SlackKitBundle\Api\SlackResponseUrlReply;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Spec 237 T000c: answers through the click's `response_url` (hooks.slack.com/actions only, no redirects).
 */
final class ResponseUrlClientTest extends TestCase
{
    public function testRepliesPostTheExpectedBodies(): void
    {
        $calls  = [];
        $client = new ResponseUrlClient(new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = ['url' => $url, 'body' => json_decode((string) $options['body'], true), 'redirects' => $options['max_redirects']];

            return new MockResponse('{"ok":true}', ['http_code' => 200]);
        }), new ApiPolicy());
        $url = SlackSecret::responseUrl(Fake::RESPONSE_URL);

        $client->respond($url, SlackResponseUrlReply::ephemeral(SlackMessage::plain('Only you')));
        $client->respond($url, SlackResponseUrlReply::replace(SlackMessage::plain('Assigned')));
        $client->respond($url, SlackResponseUrlReply::delete());

        self::assertSame(Fake::RESPONSE_URL, $calls[0]['url']);
        self::assertSame(0, $calls[0]['redirects']);
        self::assertSame('ephemeral', $calls[0]['body']['response_type']);
        self::assertFalse($calls[0]['body']['replace_original']);
        self::assertArrayNotHasKey('unfurl_links', $calls[0]['body']);
        self::assertTrue($calls[1]['body']['replace_original']);
        self::assertSame('Assigned', $calls[1]['body']['text']);
        self::assertSame(['delete_original' => true], $calls[2]['body']);
    }

    /**
     * @return iterable<string, array{0: MockResponse, 1: string}>
     */
    public static function failures(): iterable
    {
        yield 'json error' => [new MockResponse('{"ok":false,"error":"expired_url"}', ['http_code' => 404]), 'expired_url'];
        yield 'json error on 200' => [new MockResponse('{"ok":false,"error":"used_url"}', ['http_code' => 200]), 'used_url'];
        yield 'json weird error' => [new MockResponse('{"ok":false,"error":"Bad Thing"}', ['http_code' => 200]), 'unknown_error'];
        yield 'plain code' => [new MockResponse('no_text', ['http_code' => 400]), 'no_text'];
        yield 'plain junk' => [new MockResponse('<html>', ['http_code' => 200]), 'invalid_response'];
        yield 'http status' => [new MockResponse('', ['http_code' => 500]), 'http_500'];
        yield 'too large' => [new MockResponse(str_repeat('a', 70_000), ['http_code' => 200]), 'transport_error'];
        yield 'transport' => [new MockResponse('', ['error' => 'host unreachable']), 'transport_error'];
    }

    #[DataProvider('failures')]
    public function testFailuresAreTypedCodes(MockResponse $response, string $code): void
    {
        $client = new ResponseUrlClient(new MockHttpClient($response), new ApiPolicy(maxResponseBytes: 65_536));
        try {
            $client->respond(SlackSecret::responseUrl(Fake::RESPONSE_URL), SlackResponseUrlReply::delete());
            self::fail('Expected a Slack error.');
        } catch (SlackApiException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertStringNotContainsString('fakefakefakefake', $e->getMessage() . ($e->getPrevious()?->getMessage() ?? ''), 'the URL never leaks');
        }
    }

    public function testSuccessWithAPlainOkOrEmptyBodyAndRateLimits(): void
    {
        foreach (['ok', '', '[1]'] as $body) {
            (new ResponseUrlClient(new MockHttpClient(new MockResponse($body, ['http_code' => 200])), new ApiPolicy()))->respond(SlackSecret::responseUrl(Fake::RESPONSE_URL), SlackResponseUrlReply::delete());
        }
        $this->addToAssertionCount(1);

        $limited = new ResponseUrlClient(new MockHttpClient([new MockResponse('', ['http_code' => 429, 'response_headers' => ['retry-after' => '7']]), new MockResponse('', ['http_code' => 429])]), new ApiPolicy());
        foreach ([7, 30] as $expected) {
            try {
                $limited->respond(SlackSecret::responseUrl(Fake::RESPONSE_URL), SlackResponseUrlReply::delete());
                self::fail('Rate limited.');
            } catch (SlackRateLimitedException $e) {
                self::assertSame($expected, $e->retryAfterSeconds);
            }
        }
    }

    public function testOnlyResponseUrlsAreAccepted(): void
    {
        $called = false;
        $client = new ResponseUrlClient(new MockHttpClient(static function () use (&$called): MockResponse {
            $called = true;

            return new MockResponse('ok');
        }), new ApiPolicy());

        try {
            $client->respond(SlackSecret::webhookUrl(Fake::WEBHOOK), SlackResponseUrlReply::delete());
            self::fail('A webhook URL is not a response URL.');
        } catch (SlackApiException $e) {
            self::assertSame('invalid_response_url', $e->errorCode);
        }
        self::assertFalse($called);
    }

    public function testReplyValidation(): void
    {
        foreach ([static fn (): SlackResponseUrlReply => new SlackResponseUrlReply('shout', SlackMessage::plain('x')), static fn (): SlackResponseUrlReply => new SlackResponseUrlReply(SlackResponseUrlReply::REPLACE)] as $build) {
            try {
                $build();
                self::fail('Invalid reply accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
