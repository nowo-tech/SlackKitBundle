<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Integration\Functional;

use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Event\SlackInstallationCompletedEvent;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\SlackKitBundle\Tests\Integration\Kernel\SlackKitTestKernel;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use Nowo\SlackKitBundle\Tests\Support\FakeInstallAuthorizer;
use Nowo\SlackKitBundle\Tests\Support\HostBindingStore;
use Nowo\SlackKitBundle\Tests\Support\HostInstallationStore;
use Nowo\SlackKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Twig\Environment;

use function array_filter;
use function base64_encode;
use function is_string;
use function parse_str;
use function parse_url;
use function str_starts_with;

use const PHP_URL_QUERY;

/**
 * Spec 237 FR-003 "Add to Slack" in a DDD host (its own installation and binding stores): state bound to the session
 * and the account, single use, host authoriser at both ends, grant checks, re-install, nothing secret in logs.
 */
final class InstallFlowTest extends KernelTestCase
{
    private const CONFIG = [
        'apps'          => ['default' => ['app_id' => Fake::APP_ID, 'client_id' => Fake::CLIENT_ID, 'client_secret' => '%env(SLACK_KIT_TEST_CLIENT_SECRET)%']],
        'credentials'   => ['store' => 'test.host_store'],
        'installations' => ['binding_store' => 'test.host_bindings', 'oauth' => ['enabled' => true, 'authorizer' => 'test.install_authorizer', 'account_resolver' => 'test.accounts', 'optional_scopes' => ['users:read.email'], 'return_route' => 'test_integrations']],
    ];

    public function testAddToSlackInstallsBindsAndReinstalls(): void
    {
        $kernel   = $this->bootKernel(self::CONFIG);
        $client   = $this->client($kernel);
        $recorder = new class {
            /** @var list<SlackInstallationCompletedEvent> */
            public array $events = [];
        };
        $this->dispatcher($kernel)->addListener(SlackInstallationCompletedEvent::class, static function (SlackInstallationCompletedEvent $e) use ($recorder): void {
            $recorder->events[] = $e;
        });

        $crawler = $client->request('GET', '/test/integrations');
        self::assertSame('http://localhost/slack-kit/install/callback', $crawler->filter('[data-testid="redirect"]')->text());
        $query = $this->start($client);
        self::assertSame(Fake::CLIENT_ID, $query['client_id']);
        self::assertSame('chat:write,channels:read,groups:read,users:read,users:read.email', $query['scope']);
        self::assertSame('http://localhost/slack-kit/install/callback', $query['redirect_uri']);

        $this->fakeSlack($kernel)->oauth = $this->grant();
        $client->request('GET', '/slack-kit/install/callback', ['code' => 'fake-code-1', 'state' => $query['state']]);
        self::assertSame('/test/integrations', $client->getResponse()->headers->get('Location'));
        $client->followRedirect();
        self::assertStringContainsString('Slack is installed in Fake team two.', (string) $client->getResponse()->getContent());

        $exchange = $this->fakeSlack($kernel)->calls[0];
        self::assertSame('https://slack.com/api/oauth.v2.access', $exchange['url']);
        self::assertSame('Authorization: Basic ' . base64_encode(Fake::CLIENT_ID . ':' . Fake::CLIENT_SECRET), $exchange['auth']);
        parse_str($exchange['body'], $sent);
        self::assertSame(['code' => 'fake-code-1', 'redirect_uri' => 'http://localhost/slack-kit/install/callback'], $sent);

        $key          = new SlackInstallationKey('default', SlackTeamId::fromString(Fake::OTHER_TEAM));
        $installation = $this->hostStore($kernel)->find($key);
        self::assertSame(Fake::OTHER_TOKEN, $installation?->botToken->reveal());
        self::assertSame(Fake::APP_ID, $installation->appId);
        self::assertSame('Fake team two', $installation->teamName);
        self::assertSame(['chat:write', 'channels:read', 'groups:read', 'users:read', 'users:read.email'], $installation->scopes);
        self::assertTrue($installation->allowEmailLookup);
        self::assertSame('U0000000B', $installation->botUserId);
        self::assertSame(Fake::USER, $installation->installerUserId?->value);
        self::assertNotNull($installation->installedAt);
        self::assertNull($installation->signingSecret);
        self::assertCount(1, $this->hostBindings($kernel)->bindingsOf($key));
        self::assertSame('admin', $this->hostBindings($kernel)->bindingsOf($key)[0]->boundByAccountId);
        self::assertCount(1, $recorder->events);
        self::assertFalse($recorder->events[0]->reinstalled);
        self::assertSame('organization:org-1', $recorder->events[0]->scope->key());
        self::assertSame('admin', $recorder->events[0]->accountId);

        // The same state cannot be used twice.
        $client->request('GET', '/slack-kit/install/callback', ['code' => 'fake-code-1', 'state' => $query['state']]);
        $client->followRedirect();
        self::assertStringContainsString('invalid or expired', (string) $client->getResponse()->getContent());

        // Re-installing the same workspace for the same scope updates the token and keeps one binding.
        $query                           = $this->start($client);
        $this->fakeSlack($kernel)->oauth = ['access_token' => 'xoxb-test-not-a-real-token-three'] + $this->grant();
        $client->request('GET', '/slack-kit/install/callback', ['code' => 'fake-code-2', 'state' => $query['state']]);
        self::assertSame('xoxb-test-not-a-real-token-three', $this->hostStore($kernel)->find($key)?->botToken->reveal());
        self::assertCount(1, $this->hostBindings($kernel)->bindingsOf($key));
        self::assertCount(2, $recorder->events);
        self::assertTrue($recorder->events[1]->reinstalled);

        // The bundle's own log lines (the framework's router logs the request URI of every request, query included).
        $logs = (string) json_encode(array_filter($this->logger($kernel)->records, static fn (array $r): bool => str_starts_with($r['message'], 'Slack')));
        self::assertStringContainsString('Slack app installed.', $logs);
        foreach (['fake-code', 'xoxb-test', Fake::CLIENT_SECRET, $query['state']] as $secret) {
            self::assertStringNotContainsString($secret, $logs, 'no code, token, client secret or state in logs');
        }
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string|null, 2: string}>
     */
    public static function refusedGrants(): iterable
    {
        yield 'missing required scope' => [['scope' => 'chat:write'], null, 'missing_scope'];
        yield 'another Slack app' => [['app_id' => Fake::OTHER_APP_ID], null, 'app_mismatch'];
        yield 'org-wide install' => [['is_enterprise_install' => true, 'enterprise' => ['id' => Fake::ENTERPRISE]], null, 'enterprise_install'];
        yield 'token rotation' => [['refresh_token' => Fake::REFRESH_TOKEN, 'expires_in' => 43200], null, 'token_rotation_unsupported'];
        yield 'user token only' => [['token_type' => 'user', 'access_token' => 'xoxp-not-a-bot'], null, 'invalid_grant'];
        yield 'no workspace' => [['team' => null], null, 'invalid_grant'];
        yield 'slack refuses the code' => [[], 'invalid_code', 'invalid_code'];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('refusedGrants')]
    public function testRefusedGrantsSaveNothing(array $override, ?string $error, string $reason): void
    {
        $kernel                               = $this->bootKernel(self::CONFIG);
        $client                               = $this->client($kernel);
        $query                                = $this->start($client);
        $this->fakeSlack($kernel)->oauth      = $override + $this->grant();
        $this->fakeSlack($kernel)->oauthError = $error;

        $client->request('GET', '/slack-kit/install/callback', ['code' => 'fake-code-1', 'state' => $query['state']]);
        $client->followRedirect();

        self::assertStringContainsString($reason, (string) $client->getResponse()->getContent());
        self::assertSame([], $this->hostStore($kernel)->rows);
        self::assertSame([], $this->hostBindings($kernel)->rows);
    }

    public function testCancelledStaleForeignAndRevokedAttempts(): void
    {
        $kernel = $this->bootKernel(self::CONFIG);
        $client = $this->client($kernel);

        $query = $this->start($client);
        $client->request('GET', '/slack-kit/install/callback', ['error' => 'access_denied', 'state' => $query['state']]);
        $client->followRedirect();
        self::assertStringContainsString('The Slack install was cancelled.', (string) $client->getResponse()->getContent());

        $client->request('GET', '/slack-kit/install/callback', ['code' => 'fake-code-1']);
        $client->followRedirect();
        self::assertStringContainsString('invalid or expired', (string) $client->getResponse()->getContent());

        // A state started in another browser session (login CSRF) is refused.
        $query    = $this->start($client);
        $attacker = $this->browser($kernel, 'admin');
        $attacker->request('GET', '/slack-kit/install/callback', ['code' => 'fake-code-1', 'state' => $query['state']]);
        self::assertSame([], $this->hostStore($kernel)->rows);

        // The authoriser is asked again on the way back.
        $query                            = $this->start($client);
        $this->authorizer($kernel)->allow = false;
        $this->fakeSlack($kernel)->oauth  = $this->grant();
        $client->request('GET', '/slack-kit/install/callback', ['code' => 'fake-code-1', 'state' => $query['state']]);
        $client->followRedirect();
        self::assertStringContainsString('not_allowed', (string) $client->getResponse()->getContent());
        self::assertSame([], $this->hostStore($kernel)->rows);
        self::assertSame([], $this->fakeSlack($kernel)->calls, 'the code is never exchanged for a refused attempt');

        $client->request('GET', '/slack-kit/install/callback', ['state' => 'x.y']);
        self::assertSame(303, $client->getResponse()->getStatusCode());

        // A callback without a usable code.
        $this->authorizer($kernel)->allow = true;
        foreach ([[], ['code' => 'not a code']] as $code) {
            $query = $this->start($client);
            $client->request('GET', '/slack-kit/install/callback', $code + ['state' => $query['state']]);
            $client->followRedirect();
            self::assertStringContainsString('invalid_request', (string) $client->getResponse()->getContent());
        }
        self::assertSame([], $this->fakeSlack($kernel)->calls);
    }

    public function testStartIsGuarded(): void
    {
        $kernel = $this->bootKernel(self::CONFIG);
        $admin  = $this->browser($kernel, 'admin');

        $admin->request('POST', '/slack-kit/install/default/organization/org-1', ['csrf_only' => ['_token' => 'forged']]);
        self::assertSame(403, $admin->getResponse()->getStatusCode(), 'CSRF');
        $admin->request('POST', '/slack-kit/install/ops/organization/org-1');
        self::assertSame(404, $admin->getResponse()->getStatusCode(), 'unknown app');
        $admin->request('GET', '/slack-kit/install/default/organization/org-1');
        self::assertSame(405, $admin->getResponse()->getStatusCode(), 'POST only');

        $viewer  = $this->browser($kernel, 'viewer');
        $crawler = $viewer->request('GET', '/test/integrations');
        self::assertCount(0, $crawler->filter('form'), 'no "Add to Slack" for someone the authoriser refuses');

        $crawler = $admin->request('GET', '/test/integrations');
        $form    = $crawler->filter('form')->form();
        $viewer->request('POST', '/slack-kit/install/default/organization/org-1', $form->getPhpValues());
        self::assertSame(403, $viewer->getResponse()->getStatusCode(), 'the authoriser refuses the viewer');

        $crawler                          = $admin->request('GET', '/test/integrations');
        $this->authorizer($kernel)->allow = false;
        $admin->submit($crawler->filter('form')->form());
        self::assertSame(403, $admin->getResponse()->getStatusCode(), 'the authoriser is asked when the flow starts');
        self::assertCount(0, $admin->request('GET', '/test/integrations')->filter('form'));

        $anonymous = $this->browser($kernel, null);
        $anonymous->request('GET', '/slack-kit/install/callback', ['code' => 'x', 'state' => 'y']);
        self::assertSame(401, $anonymous->getResponse()->getStatusCode(), 'sign in first');

        $twig = $this->container($kernel)->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        self::assertSame('none', $twig->createTemplate("{{ nowo_slack_kit_install_action('organization', 'org-1') is null ? 'none' : 'action' }}")->render(), 'nobody signed in outside a request');
    }

    /**
     * A browser of its own (its own cookie jar and session).
     */
    private function browser(SlackKitTestKernel $kernel, ?string $user): KernelBrowser
    {
        $browser = new KernelBrowser($kernel, $user === null ? [] : ['PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => $user]);
        $browser->disableReboot();

        return $browser;
    }

    /**
     * @return array<string, string>
     */
    private function start(KernelBrowser $client): array
    {
        $crawler = $client->request('GET', '/test/integrations');
        $client->submit($crawler->filter('form')->form());
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://slack.com/oauth/v2/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $parsed);
        $query = [];
        foreach ($parsed as $name => $value) {
            $query[(string) $name] = is_string($value) ? $value : '';
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function grant(): array
    {
        return [
            'access_token'          => Fake::OTHER_TOKEN,
            'token_type'            => 'bot',
            'scope'                 => 'chat:write,channels:read,groups:read,users:read,users:read.email',
            'bot_user_id'           => 'U0000000B',
            'app_id'                => Fake::APP_ID,
            'team'                  => ['id' => Fake::OTHER_TEAM, 'name' => 'Fake team two'],
            'enterprise'            => null,
            'authed_user'           => ['id' => Fake::USER, 'access_token' => 'xoxp-never-kept'],
            'is_enterprise_install' => false,
        ];
    }

    private function hostStore(SlackKitTestKernel $kernel): HostInstallationStore
    {
        $store = $this->container($kernel)->get('test.host_store');
        self::assertInstanceOf(HostInstallationStore::class, $store);

        return $store;
    }

    private function hostBindings(SlackKitTestKernel $kernel): HostBindingStore
    {
        $store = $this->container($kernel)->get('test.host_bindings');
        self::assertInstanceOf(HostBindingStore::class, $store);

        return $store;
    }

    private function authorizer(SlackKitTestKernel $kernel): FakeInstallAuthorizer
    {
        $authorizer = $this->container($kernel)->get('test.install_authorizer');
        self::assertInstanceOf(FakeInstallAuthorizer::class, $authorizer);

        return $authorizer;
    }

    private function logger(SlackKitTestKernel $kernel): RecordingLogger
    {
        $logger = $this->container($kernel)->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);

        return $logger;
    }

    private function dispatcher(SlackKitTestKernel $kernel): EventDispatcherInterface
    {
        $dispatcher = $this->container($kernel)->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
