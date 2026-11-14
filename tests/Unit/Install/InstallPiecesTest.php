<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Install;

use InvalidArgumentException;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\SlackKitBundle\Api\ApiPolicy;
use Nowo\SlackKitBundle\Api\SlackApiException;
use Nowo\SlackKitBundle\Credentials\InMemoryInstallationStore;
use Nowo\SlackKitBundle\Credentials\SlackAppRegistry;
use Nowo\SlackKitBundle\Install\SlackOAuthClient;
use Nowo\SlackKitBundle\Install\SlackOAuthExchange;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use Nowo\SlackKitBundle\Tests\Support\FakeIdentityStore;
use Nowo\SlackKitBundle\Tests\Support\FakeInstallAuthorizer;
use Nowo\SlackKitBundle\Twig\SlackKitTwigExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Spec 237 FR-003 pieces: the OAuth exchange value, `oauth.v2.access` failures and the Twig install action guards.
 */
final class InstallPiecesTest extends TestCase
{
    /**
     * @return iterable<string, array{0: callable(): SlackOAuthExchange}>
     */
    public static function invalidExchanges(): iterable
    {
        yield 'client id' => [static fn (): SlackOAuthExchange => new SlackOAuthExchange('client', SlackSecret::clientSecret(Fake::CLIENT_SECRET), 'code', 'https://example.test/cb')];
        yield 'secret kind' => [static fn (): SlackOAuthExchange => new SlackOAuthExchange(Fake::CLIENT_ID, SlackSecret::signingSecret(Fake::SIGNING_SECRET), 'code', 'https://example.test/cb')];
        yield 'code' => [static fn (): SlackOAuthExchange => new SlackOAuthExchange(Fake::CLIENT_ID, SlackSecret::clientSecret(Fake::CLIENT_SECRET), 'a code', 'https://example.test/cb')];
    }

    /**
     * @param callable(): SlackOAuthExchange $build
     */
    #[DataProvider('invalidExchanges')]
    public function testExchangeValidation(callable $build): void
    {
        $this->expectException(InvalidArgumentException::class);
        $build();
    }

    /**
     * @return iterable<string, array{0: MockResponse, 1: string}>
     */
    public static function oauthFailures(): iterable
    {
        yield 'transport' => [new MockResponse('', ['error' => 'down']), 'transport_error'];
        yield 'too large' => [new MockResponse(str_repeat('x', 70_000)), 'transport_error'];
        yield 'not json' => [new MockResponse('<html>'), 'invalid_response'];
        yield 'not an object' => [new MockResponse('"ok"'), 'invalid_response'];
        yield 'refused without a code' => [new MockResponse('{"ok":false}'), 'unknown_error'];
        yield 'refused' => [new MockResponse('{"ok":false,"error":"invalid_code"}'), 'invalid_code'];
    }

    #[DataProvider('oauthFailures')]
    public function testOauthFailuresAreTypedAndRedacted(MockResponse $response, string $code): void
    {
        $client = new SlackOAuthClient(new MockHttpClient($response), new ApiPolicy(maxResponseBytes: 65_536));
        try {
            $client->exchange(new SlackOAuthExchange(Fake::CLIENT_ID, SlackSecret::clientSecret(Fake::CLIENT_SECRET), 'fake-code', 'https://example.test/cb'));
            self::fail('Expected a Slack error.');
        } catch (SlackApiException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertStringNotContainsString(Fake::CLIENT_SECRET, $e->getMessage() . ($e->getPrevious()?->getMessage() ?? ''));
        }
    }

    public function testGrantParsingKeepsOnlyValidValues(): void
    {
        $client = new SlackOAuthClient(new MockHttpClient(new MockResponse((string) json_encode([
            'ok'           => true,
            'token_type'   => 'bot',
            'access_token' => Fake::TOKEN,
            'scope'        => 'chat:write, Bad Scope ,users:read',
            'app_id'       => 'bad',
            'bot_user_id'  => 'bad',
            'team'         => ['id' => Fake::TEAM],
            'enterprise'   => ['id' => Fake::ENTERPRISE],
            'authed_user'  => ['id' => Fake::USER, 'access_token' => 'xoxp-never-kept'],
        ]))), new ApiPolicy());

        $grant = $client->exchange(new SlackOAuthExchange(Fake::CLIENT_ID, SlackSecret::clientSecret(Fake::CLIENT_SECRET), 'fake-code', 'https://example.test/cb'));

        self::assertSame(['chat:write', 'users:read'], $grant->scopes);
        self::assertNull($grant->appId);
        self::assertNull($grant->botUserId);
        self::assertSame('', $grant->teamName);
        self::assertSame(Fake::ENTERPRISE, $grant->enterprise?->value);
        self::assertFalse($grant->enterpriseInstall);
        self::assertFalse($grant->rotating);
        self::assertSame(Fake::TOKEN, $grant->botToken?->reveal());
        self::assertSame(Fake::USER, $grant->installer?->value, 'only the installer id is kept from authed_user, never its token');
    }

    public function testTwigInstallActionGuards(): void
    {
        $urls     = $this->createStub(UrlGeneratorInterface::class);
        $apps     = new SlackAppRegistry('default', ['default' => ['app_id' => Fake::APP_ID, 'signing_secret' => null, 'client_id' => Fake::CLIENT_ID, 'client_secret' => Fake::CLIENT_SECRET]], new InMemoryInstallationStore());
        $accounts = new FakeIdentityStore();
        $forms    = new CsrfOnlyFormFactory($this->createStub(FormFactoryInterface::class));
        $twig     = new SlackKitTwigExtension($urls, null, null, null, $forms, $apps, new FakeInstallAuthorizer(), $accounts);

        self::assertNull($twig->installAction('organization', 'org-1'), 'nobody signed in');
        $accounts->current = 'admin';
        self::assertNull($twig->installAction('Bad Type', 'org-1'), 'malformed scope');
        self::assertNull($twig->installAction('organization', 'org-1', 'ops'), 'unknown app');
        self::assertNull($twig->installAction('organization', 'org-2'), 'the authoriser refuses');
        self::assertNull((new SlackKitTwigExtension($urls))->installAction('organization', 'org-1'), 'flow off');
        self::assertNull((new SlackKitTwigExtension($urls))->installRedirectUrl());
    }
}
