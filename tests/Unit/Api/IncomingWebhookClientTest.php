<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Api;

use Nowo\SlackKitBundle\Api\ApiPolicy;
use Nowo\SlackKitBundle\Api\IncomingWebhookClient;
use Nowo\SlackKitBundle\Api\RedactedTransportException;
use Nowo\SlackKitBundle\Api\SlackApiException;
use Nowo\SlackKitBundle\Api\SlackMessage;
use Nowo\SlackKitBundle\Api\SlackRateLimitedException;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class IncomingWebhookClientTest extends TestCase
{
    public function testPostsJsonWithoutRedirects(): void
    {
        $seen = null;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$url, $options['max_redirects'], $options['body']];

            return new MockResponse('ok');
        });
        (new IncomingWebhookClient($http, new ApiPolicy()))->post(SlackSecret::webhookUrl(Fake::WEBHOOK), SlackMessage::plain('hi'));
        self::assertNotNull($seen);
        self::assertSame(Fake::WEBHOOK, $seen[0]);
        self::assertSame(0, $seen[1]);
        self::assertStringContainsString('"text":"hi"', (string) $seen[2]);
    }

    public function testErrors(): void
    {
        $cases = [
            [new MockResponse('invalid_payload', ['http_code' => 400]), 'invalid_payload'],
            [new MockResponse('<html>oops</html>', ['http_code' => 500]), 'http_500'],
            [new MockResponse('', ['error' => 'down']), 'transport_error'],
            [new MockResponse(str_repeat('x', 70_000)), 'transport_error'],
        ];
        foreach ($cases as [$response, $code]) {
            try {
                (new IncomingWebhookClient(new MockHttpClient([$response]), new ApiPolicy(maxResponseBytes: 65_536)))->post(SlackSecret::webhookUrl(Fake::WEBHOOK), SlackMessage::plain('x'));
                self::fail('accepted');
            } catch (SlackApiException $e) {
                self::assertSame($code, $e->errorCode);
            }
        }
    }

    public function testTransportFailuresNeverCarryTheWebhookUrl(): void
    {
        // Audit FS-L2 PoC: the HttpClient message names the URL; the bundle keeps only a redacted copy.
        $http = new MockHttpClient(static fn (): MockResponse => throw new TransportException('Failed to connect to hooks.slack.com for "' . Fake::WEBHOOK . '".'));
        try {
            (new IncomingWebhookClient($http, new ApiPolicy()))->post(SlackSecret::webhookUrl(Fake::WEBHOOK), SlackMessage::plain('x'));
            self::fail('accepted');
        } catch (SlackApiException $e) {
            self::assertSame('Slack API error: transport_error', $e->getMessage());
            $previous = $e->getPrevious();
            self::assertInstanceOf(RedactedTransportException::class, $previous);
            self::assertNull($previous->getPrevious());
            self::assertStringNotContainsString('fakefakefakefakefake', $previous->getMessage());
            self::assertStringNotContainsString('TFAKE0000', $previous->getMessage());
            self::assertStringContainsString('TransportException', $previous->getMessage());
            self::assertStringNotContainsString('fakefake', (string) json_encode([(string) $e, $e->getTraceAsString()]));
        }
    }

    public function testRateLimitAndWrongSecretKind(): void
    {
        try {
            (new IncomingWebhookClient(new MockHttpClient([new MockResponse('', ['http_code' => 429, 'response_headers' => ['retry-after' => '7']])]), new ApiPolicy()))->post(SlackSecret::webhookUrl(Fake::WEBHOOK), SlackMessage::plain('x'));
            self::fail('accepted');
        } catch (SlackRateLimitedException $e) {
            self::assertSame(7, $e->retryAfterSeconds);
        }
        try {
            (new IncomingWebhookClient(new MockHttpClient([new MockResponse('', ['http_code' => 429])]), new ApiPolicy()))->post(SlackSecret::webhookUrl(Fake::WEBHOOK), SlackMessage::plain('x'));
            self::fail('accepted');
        } catch (SlackRateLimitedException $e) {
            self::assertSame(30, $e->retryAfterSeconds);
        }
        $this->expectExceptionMessage('Slack API error: invalid_webhook_url');
        (new IncomingWebhookClient(new MockHttpClient(), new ApiPolicy()))->post(SlackSecret::botToken(Fake::TOKEN), SlackMessage::plain('x'));
    }
}
