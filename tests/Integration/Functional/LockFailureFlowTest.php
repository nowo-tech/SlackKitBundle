<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Integration\Functional;

use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Linking\ClickLinker;
use Nowo\SlackKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\SlackKitBundle\Tests\Integration\Kernel\SlackKitTestKernel;
use Nowo\SlackKitBundle\Tests\Support\FailingLockStore;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use Nowo\SlackKitBundle\Tests\Support\HostInstallationStore;
use Nowo\SlackKitBundle\Tests\Support\RecordingLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

use function array_column;
use function array_replace_recursive;
use function is_string;
use function parse_str;
use function parse_url;

use const PHP_URL_QUERY;

/**
 * REQ-RUNTIME-003 on the bundle's own HTML routes: a request that loses a link lock keeps the Post/Redirect/Get answer
 * and carries `X-Conflict-Code: CONFLICT_001` + `Retry-After`; an unreachable lock store fails closed with a flash,
 * never a 500.
 */
final class LockFailureFlowTest extends KernelTestCase
{
    private const LINKING = ['linking' => ['enabled' => true, 'identity_store' => 'test.identities', 'account_resolver' => 'test.accounts', 'email_lookup' => true, 'return_route' => 'test_profile']];

    private const INSTALL = [
        'apps'          => ['default' => ['app_id' => Fake::APP_ID, 'client_id' => Fake::CLIENT_ID, 'client_secret' => '%env(SLACK_KIT_TEST_CLIENT_SECRET)%']],
        'credentials'   => ['store' => 'test.host_store'],
        'installations' => ['binding_store' => 'test.host_bindings', 'oauth' => ['enabled' => true, 'authorizer' => 'test.install_authorizer', 'account_resolver' => 'test.accounts', 'return_route' => 'test_integrations']],
    ];

    public function testAClaimThatLosesTheMemberLockAnswersConflict001(): void
    {
        $kernel = $this->boot(self::LINKING, new Definition(InMemoryStore::class));
        $this->settings($kernel)->save('slack', ['bot_token' => Fake::TOKEN, 'signing_secret' => Fake::SIGNING_SECRET, 'team_id' => Fake::TEAM, 'allow_email_lookup' => true]);
        $client = $this->client($kernel);
        $locks  = $this->container($kernel)->get('test.locks');
        self::assertInstanceOf(LockFactory::class, $locks);
        $held = $locks->createLock(ClickLinker::pairLockKey(SlackInstallationKey::DEFAULT_APP, SlackTeamId::fromString(Fake::TEAM), SlackUserId::fromString(Fake::USER)));
        self::assertTrue($held->acquire());

        $crawler = $client->request('GET', '/profile');
        $form    = $crawler->filter('[data-testid="slack-kit-link-claim"]')->form(['nowo_slack_kit_link[slack_user_id]' => Fake::USER]);
        $client->submit($form);
        $response = $client->getResponse();
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
        self::assertSame('1', $response->headers->get('Retry-After'));
        $client->followRedirect();
        self::assertStringContainsString('Another Slack link request for this account is in progress.', (string) $client->getResponse()->getContent());
        self::assertSame([], $this->identities($kernel)->links, 'nothing written');

        $held->release();
        $client->submit($client->request('GET', '/profile')->filter('[data-testid="slack-kit-link-claim"]')->form(['nowo_slack_kit_link[slack_user_id]' => Fake::USER]));
        self::assertFalse($client->getResponse()->headers->has('X-Conflict-Code'));
        $client->followRedirect();
        self::assertStringContainsString('Slack id saved.', (string) $client->getResponse()->getContent());
    }

    public function testAnUnreachableInstallStateStoreFailsClosed(): void
    {
        $kernel = $this->boot(self::INSTALL, new Definition(FailingLockStore::class, [['install_state']]));
        $client = $this->client($kernel);

        $crawler = $client->request('GET', '/test/integrations');
        $client->submit($crawler->filter('form')->form());
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $query);
        self::assertTrue(is_string($query['state'] ?? null));

        $client->request('GET', '/slack-kit/install/callback', ['code' => 'fake-code-1', 'state' => $query['state']]);
        self::assertSame(303, $client->getResponse()->getStatusCode());
        $client->followRedirect();
        self::assertStringContainsString('Slack install is temporarily unavailable.', (string) $client->getResponse()->getContent());
        $store = $this->container($kernel)->get('test.host_store');
        self::assertInstanceOf(HostInstallationStore::class, $store);
        self::assertSame([], $store->rows);
        self::assertSame([], $this->fakeSlack($kernel)->calls, 'the code is never exchanged');
        $logger = $this->container($kernel)->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);
        self::assertContains('state_store', array_column(array_column($logger->records, 'context'), 'reason'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function boot(array $config, Definition $store): SlackKitTestKernel
    {
        return $this->bootKernel(array_replace_recursive($config, ['ingress' => ['replay_lock_factory' => 'test.locks']]), build: static function (ContainerBuilder $container) use ($store): void {
            $container->setDefinition('test.locks', new Definition(LockFactory::class, [$store]))->setPublic(true);
        });
    }
}
