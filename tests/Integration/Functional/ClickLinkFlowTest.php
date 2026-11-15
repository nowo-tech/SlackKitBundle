<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Integration\Functional;

use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Interaction\ButtonValueCodecInterface;
use Nowo\SlackKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\SlackKitBundle\Tests\Integration\Kernel\SlackKitTestKernel;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use Nowo\SlackKitBundle\Tests\Support\FakeIdentityLinks;
use Nowo\SlackKitBundle\Tests\Support\HostInstallationStore;
use Nowo\SlackKitBundle\Tests\Support\RecordingLogger;
use Nowo\SlackKitBundle\Tests\Support\UpdatingHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_filter;
use function is_array;
use function json_decode;
use function json_encode;
use function parse_url;
use function random_int;
use function sprintf;
use function str_starts_with;
use function time;

use const PHP_URL_PATH;

/**
 * Spec 237 T000b end to end in a DDD host (its own identity, e-mail and installation stores): an unlinked click gets
 * a signed link, the signed-in member confirms on a UiKit page, the e-mail must match a verified e-mail, the member gets
 * a DM, the next click acts as them; per-workspace identities can be listed and unlinked.
 */
final class ClickLinkFlowTest extends KernelTestCase
{
    private const CONFIG = [
        'apps'        => ['default' => ['app_id' => Fake::APP_ID]],
        'credentials' => ['store' => 'test.host_store'],
        'linking'     => [
            'enabled'          => true,
            'identity_store'   => 'test.identity_links',
            'account_resolver' => 'test.accounts',
            'return_route'     => 'test_integrations',
            'from_click'       => ['enabled' => true, 'email_checker' => 'test.identity_links', 'allow_http' => true, 'manage_route' => 'test_integrations'],
        ],
    ];

    public function testAnUnlinkedClickerLinksFromTheClickAndThenActs(): void
    {
        $kernel                                        = $this->kernel();
        $this->links($kernel)->verifiedEmails['admin'] = ['admin@example.test'];

        $url = $this->unlinkedClick($kernel, Fake::USER, '1');

        $admin   = $this->browser($kernel, 'admin');
        $crawler = $admin->request('GET', $url);
        self::assertSame(200, $admin->getResponse()->getStatusCode(), (string) $admin->getResponse()->getContent());
        $page = (string) $admin->getResponse()->getContent();
        self::assertStringContainsString('Ana Slack', $page, 'the Slack display name is shown');
        self::assertStringContainsString(Fake::TEAM, $page, 'the workspace is shown');
        self::assertSame([], $this->links($kernel)->all(), 'opening the page links nothing');

        $this->fakeSlack($kernel)->calls = [];
        $admin->submit($crawler->filter('form[action="' . $url . '"]')->form());
        self::assertSame('/test/integrations', $admin->getResponse()->headers->get('Location'));
        $crawler = $admin->followRedirect();
        self::assertStringContainsString('Your Slack account is linked for this workspace.', (string) $admin->getResponse()->getContent());
        self::assertSame(Fake::TEAM . ' ' . Fake::USER . ' click', $crawler->filter('[data-testid="identity"]')->text());
        self::assertSame(['users.info', 'conversations.open', 'chat.postMessage'], $this->fakeSlack($kernel)->methods(), 'the DM goes to the member');
        self::assertStringContainsString('now linked to Account admin', $this->fakeSlack($kernel)->calls[2]['body']);

        // The same link cannot be used twice.
        $admin->request('POST', $url, $crawler->filter('form')->first()->form()->getPhpValues());
        self::assertSame(403, $admin->getResponse()->getStatusCode(), 'the CSRF form of the page is gone; a replay is refused');

        // The next click acts as the linked account.
        $this->fakeSlack($kernel)->calls = [];
        $response                        = $this->click($kernel, Fake::USER, '2');
        self::assertSame(200, $response['status'], $response['body']);
        $handler = $this->container($kernel)->get('test.updating_handler');
        self::assertInstanceOf(UpdatingHandler::class, $handler);
        self::assertSame('admin', $handler->seen[0]['account']);

        // Unlink that workspace from the integrations page.
        $crawler = $admin->request('GET', '/test/integrations');
        $admin->submit($crawler->filter('form[action="/slack-kit/account/slack/unlink/' . Fake::TEAM . '"]')->form());
        $admin->followRedirect();
        self::assertStringContainsString('Your Slack account is unlinked.', (string) $admin->getResponse()->getContent());
        self::assertSame([], $this->links($kernel)->all());

        $logs = (string) json_encode(array_filter($this->logger($kernel)->records, static fn (array $r): bool => str_starts_with($r['message'], 'Slack')));
        self::assertStringNotContainsString('admin@example.test', $logs, 'no e-mail in logs');
    }

    public function testAForwardedLinkIsRefusedWithoutAMatchingVerifiedEmail(): void
    {
        $kernel = $this->kernel();
        $url    = $this->unlinkedClick($kernel, Fake::USER, '1');

        // Viewer opens the link Ana's click produced: their account has no matching verified e-mail.
        $viewer  = $this->browser($kernel, 'viewer');
        $crawler = $viewer->request('GET', $url);
        $viewer->submit($crawler->filter('form[action="' . $url . '"]')->form());
        $viewer->followRedirect();
        self::assertStringContainsString('is not a verified e-mail of this account', (string) $viewer->getResponse()->getContent());
        self::assertSame([], $this->links($kernel)->all());

        $anonymous = $this->browser($kernel, null);
        $anonymous->request('GET', $url);
        self::assertSame(401, $anonymous->getResponse()->getStatusCode(), 'sign in first');

        $viewer->request('POST', $url, ['csrf_only' => ['_token' => 'forged']]);
        self::assertSame(403, $viewer->getResponse()->getStatusCode(), 'CSRF');

        $viewer->request('GET', '/slack-kit/account/slack/link/' . str_repeat('A', 30) . '.' . str_repeat('B', 43));
        $viewer->followRedirect();
        self::assertStringContainsString('invalid, expired or already used', (string) $viewer->getResponse()->getContent());
    }

    /**
     * REQ-RUNTIME-002: one kernel, no resetter between requests; two accounts link members in turn, each link goes to
     * the account that confirmed it and nothing of the previous request leaks.
     */
    public function testAlternatingAccountsInOneKernel(): void
    {
        $kernel                = $this->kernel();
        $links                 = $this->links($kernel);
        $links->verifiedEmails = ['admin' => ['admin@example.test'], 'viewer' => ['viewer@example.test']];
        $browsers              = ['admin' => $this->browser($kernel, 'admin'), 'viewer' => $this->browser($kernel, 'viewer')];

        // 5 links per account (2 limiter hits each) stay inside the per-account limit (10 per hour).
        for ($i = 0; $i < 10; ++$i) {
            $account                           = $i % 2 === 0 ? 'admin' : 'viewer';
            $member                            = sprintf('U%08d', 100 + $i);
            $this->fakeSlack($kernel)->profile = ['display_name' => 'Member ' . $i, 'email' => $account . '@example.test'];
            $url                               = $this->unlinkedClick($kernel, $member, 'w' . $i);
            $crawler                           = $browsers[$account]->request('GET', $url);
            $browsers[$account]->submit($crawler->filter('form[action="' . $url . '"]')->form());

            $link = $links->findLinkBySlackUser(SlackTeamId::fromString(Fake::TEAM), SlackUserId::fromString($member));
            self::assertSame($account, $link?->accountId, "Request {$i}: linked to the confirming account.");
            $links->links                    = [];
            $this->fakeSlack($kernel)->calls = [];
            $this->logger($kernel)->records  = [];
        }
    }

    private function kernel(): SlackKitTestKernel
    {
        $kernel = $this->bootKernel(self::CONFIG);
        $store  = $this->container($kernel)->get('test.host_store');
        self::assertInstanceOf(HostInstallationStore::class, $store);
        $store->save(Fake::installation(Fake::TEAM, Fake::TOKEN));

        return $kernel;
    }

    /**
     * A click by an unlinked member: 403, and the path of the link in the ephemeral answer.
     */
    private function unlinkedClick(SlackKitTestKernel $kernel, string $user, string $nth): string
    {
        $response = $this->click($kernel, $user, $nth);
        self::assertSame(403, $response['status']);
        $ephemeral = null;
        foreach ($this->fakeSlack($kernel)->calls as $call) {
            if ($call['url'] === 'https://slack.com/api/chat.postEphemeral') {
                $ephemeral = json_decode($call['body'], true);
            }
        }
        self::assertTrue(is_array($ephemeral));
        $button = $ephemeral['blocks'][1]['elements'][0];
        self::assertSame('nowo_slack_kit.link.open', $button['action_id']);

        return (string) parse_url($button['url'], PHP_URL_PATH);
    }

    /**
     * @return array{status: int, body: string}
     */
    private function click(SlackKitTestKernel $kernel, string $user, string $nth): array
    {
        $codec = $this->container($kernel)->get(ButtonValueCodecInterface::class);
        self::assertInstanceOf(ButtonValueCodecInterface::class, $codec);
        $payload                            = Fake::blockActions('test.item.done', $codec->sign('test.item.done', [], SlackTeamId::fromString(Fake::TEAM)), Fake::TEAM, $user);
        $payload['actions'][0]['action_ts'] = '1793836801.' . $nth . random_int(100000, 999999); // unique body: the replay memory is shared across test kernels
        $body                               = Fake::form($payload);
        $client                             = $this->browser($kernel, null);
        $client->request('POST', '/slack/interactions', [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'] + Fake::signed($body, time()), $body);

        return ['status' => $client->getResponse()->getStatusCode(), 'body' => (string) $client->getResponse()->getContent()];
    }

    private function browser(SlackKitTestKernel $kernel, ?string $user): KernelBrowser
    {
        $browser = new KernelBrowser($kernel, $user === null ? [] : ['PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => $user]);
        $browser->disableReboot();

        return $browser;
    }

    private function links(SlackKitTestKernel $kernel): FakeIdentityLinks
    {
        $links = $this->container($kernel)->get('test.identity_links');
        self::assertInstanceOf(FakeIdentityLinks::class, $links);

        return $links;
    }

    private function logger(SlackKitTestKernel $kernel): RecordingLogger
    {
        $logger = $this->container($kernel)->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);

        return $logger;
    }
}
