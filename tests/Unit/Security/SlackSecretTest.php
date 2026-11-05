<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Security;

use InvalidArgumentException;
use LogicException;
use Nowo\SlackKitBundle\Security\SecretRedactor;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use const JSON_THROW_ON_ERROR;

final class SlackSecretTest extends TestCase
{
    public function testNeverLeaksThroughCastsDumpsOrEncoders(): void
    {
        $secret = SlackSecret::botToken(' ' . Fake::TOKEN . ' ');

        self::assertSame(Fake::TOKEN, $secret->reveal());
        self::assertSame('[redacted]', (string) $secret);
        self::assertSame(['kind' => 'bot_token', 'value' => '[redacted]'], $secret->__debugInfo());
        ob_start();
        var_dump($secret);
        self::assertStringNotContainsString(Fake::TOKEN, (string) ob_get_clean());
        self::assertStringNotContainsString(Fake::TOKEN, print_r($secret, true));
    }

    public function testRefusesSerialisation(): void
    {
        $this->expectException(LogicException::class);
        serialize(SlackSecret::signingSecret(Fake::SIGNING_SECRET));
    }

    public function testRefusesUnserialisation(): void
    {
        $this->expectException(LogicException::class);
        (new ReflectionClass(SlackSecret::class))->newInstanceWithoutConstructor()->__unserialize([]);
    }

    public function testRefusesJson(): void
    {
        $this->expectException(LogicException::class);
        json_encode(SlackSecret::signingSecret(Fake::SIGNING_SECRET), JSON_THROW_ON_ERROR);
    }

    public function testRefusesExport(): void
    {
        $this->expectException(LogicException::class);
        SlackSecret::__set_state([]);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function otherTokens(): iterable
    {
        yield 'user' => ['xoxp-not-a-real-user-token', 'a user token'];
        yield 'app' => ['xapp-not-a-real-app-token', 'an app-level token'];
        yield 'workspace' => ['xoxa-not-real-at-all', 'a workspace token'];
        yield 'refresh' => ['xoxe.xoxp-not-real', 'a refresh token'];
        yield 'session' => ['xoxs-not-real-at-all', 'a session token'];
        yield 'rotating refresh' => ['xoxr-not-real-at-all', 'a refresh token'];
    }

    #[DataProvider('otherTokens')]
    public function testRefusesOtherTokensNamingTheKindOnly(string $value, string $kind): void
    {
        try {
            SlackSecret::botToken($value);
            self::fail('accepted');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString($kind, $e->getMessage());
            self::assertStringNotContainsString($value, $e->getMessage());
        }
    }

    public function testFormats(): void
    {
        self::assertNull(SlackSecret::tryBotToken(null));
        self::assertNull(SlackSecret::tryBotToken(' '));
        self::assertNull(SlackSecret::tryBotToken('xoxb-short'));
        self::assertNotNull(SlackSecret::tryBotToken(Fake::TOKEN));
        self::assertNull(SlackSecret::trySigningSecret('with-dashes-not-allowed'));
        self::assertNull(SlackSecret::trySigningSecret(null));
        self::assertSame(Fake::SIGNING_SECRET, SlackSecret::trySigningSecret(Fake::SIGNING_SECRET)?->reveal());
        self::assertSame(SlackSecret::WEBHOOK_URL, SlackSecret::webhookUrl(Fake::WEBHOOK)->kind);
        self::assertSame(SlackSecret::BUTTON_KEY, SlackSecret::key(str_repeat('k', 32))->kind);
    }

    /**
     * @return iterable<string, array{0: callable(): SlackSecret}>
     */
    public static function invalid(): iterable
    {
        yield 'bot' => [static fn (): SlackSecret => SlackSecret::botToken('xoxb-')];
        yield 'signing' => [static fn (): SlackSecret => SlackSecret::signingSecret('short')];
        yield 'webhook host' => [static fn (): SlackSecret => SlackSecret::webhookUrl('https://evil.example/services/TFAKE/BFAKE/fake')];
        yield 'webhook http' => [static fn (): SlackSecret => SlackSecret::webhookUrl('http://hooks.slack.com/services/TFAKE0000/BFAKE0000/fake')];
        yield 'key' => [static fn (): SlackSecret => SlackSecret::key('short')];
    }

    #[DataProvider('invalid')]
    public function testRefusesMalformedValues(callable $make): void
    {
        $this->expectException(InvalidArgumentException::class);
        $make();
    }

    public function testRedactor(): void
    {
        $text = 'Bearer ' . Fake::TOKEN . ' xoxp-1-2 xapp-1-A xoxe.xoxp-1-x token=abc&code=def&client_secret=ghi ' . Fake::WEBHOOK;
        $out  = SecretRedactor::redact($text);
        foreach ([Fake::TOKEN, 'xoxp-1-2', 'xapp-1-A', 'xoxe.xoxp-1-x', 'abc', 'def', 'ghi', 'BFAKE0000'] as $secret) {
            self::assertStringNotContainsString($secret, $out);
        }

        $array = SecretRedactor::redactArray(['token' => Fake::TOKEN, 'note' => 'xoxb-another-fake-1', 'nested' => ['signing_secret' => SlackSecret::signingSecret(Fake::SIGNING_SECRET), 'n' => 1], 'count' => 3]);
        self::assertSame(['token' => '[redacted]', 'note' => '[redacted]', 'nested' => ['signing_secret' => '[redacted]', 'n' => 1], 'count' => 3], $array);
    }
}
