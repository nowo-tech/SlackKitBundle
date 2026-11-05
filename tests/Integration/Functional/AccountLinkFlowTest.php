<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Integration\Functional;

use Nowo\SlackKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\SlackKitBundle\Tests\Integration\Kernel\SlackKitTestKernel;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use Twig\Environment;

use function array_slice;

/**
 * Contract §8 end to end: the profile card (UiKit), claim, e-mail lookup, confirmation DM, the signed Slack click on
 * the ingress, verification, unlink.
 */
final class AccountLinkFlowTest extends KernelTestCase
{
    private const LINKING = ['linking' => ['enabled' => true, 'identity_store' => 'test.identities', 'account_resolver' => 'test.accounts', 'email_lookup' => true, 'return_route' => 'test_profile']];

    public function testClaimConfirmByClickAndUnlink(): void
    {
        $kernel = $this->configured();
        $client = $this->client($kernel);

        $crawler = $client->request('GET', '/profile');
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) json_encode(array_slice($this->container($kernel)->get('logger')->records ?? [], -3)));
        self::assertSame('none', $crawler->filter('[data-testid="slack-kit-link-status"]')->attr('data-status'));
        self::assertCount(0, $crawler->filter('form[action="/slack-kit/account/slack/confirm"]'));

        $form = $crawler->filter('[data-testid="slack-kit-link-claim"]')->form(['nowo_slack_kit_link[slack_user_id]' => Fake::USER]);
        $client->submit($form);
        self::assertSame('/profile', $client->getResponse()->headers->get('Location'));
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Slack id saved.', (string) $client->getResponse()->getContent());
        self::assertSame('claimed', $crawler->filter('[data-testid="slack-kit-link-status"]')->attr('data-status'));

        $client->submit($crawler->filter('form[action="/slack-kit/account/slack/confirm"]')->form());
        $client->followRedirect();
        self::assertStringContainsString('Confirmation sent', (string) $client->getResponse()->getContent());
        $calls = $this->fakeSlack($kernel)->calls;
        self::assertSame(['conversations.open', 'chat.postMessage'], $this->fakeSlack($kernel)->methods());
        $posted = json_decode($calls[1]['body'], true);
        self::assertIsArray($posted);
        $button = $posted['blocks'][1]['elements'][0];
        self::assertSame('nowo_slack_kit.link.confirm', $button['action_id']);
        self::assertStringStartsWith('nsk1.', $button['value']);

        // Slack: the member clicks Confirm.
        $response = $this->click($kernel, $button['action_id'], $button['value']);
        self::assertSame(200, $response['status'], $response['body']);
        self::assertSame('Linked', $response['body']);
        self::assertTrue($this->identities($kernel)->links['admin']->isVerified());

        $crawler = $client->request('GET', '/profile');
        self::assertSame('verified', $crawler->filter('[data-testid="slack-kit-link-status"]')->attr('data-status'));
        self::assertCount(0, $crawler->filter('[data-testid="slack-kit-link-claim"]'));

        $client->submit($crawler->filter('form[action="/slack-kit/account/slack/unlink"]')->form());
        $client->followRedirect();
        self::assertStringContainsString('Your Slack account is unlinked.', (string) $client->getResponse()->getContent());
        self::assertArrayNotHasKey('admin', $this->identities($kernel)->links);
    }

    public function testFindByEmailAndInvalidIds(): void
    {
        $kernel                                     = $this->configured();
        $this->identities($kernel)->emails['admin'] = 'admin@example.test';
        $client                                     = $this->client($kernel);
        $crawler                                    = $client->request('GET', '/profile');

        $client->submit($crawler->filter('[data-testid="slack-kit-link-claim"]')->form(['nowo_slack_kit_link[slack_user_id]' => 'not-an-id']));
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Enter a Slack member id', (string) $client->getResponse()->getContent());

        $client->submit($crawler->filter('form[action="/slack-kit/account/slack/find"]')->form());
        $client->followRedirect();
        self::assertStringContainsString('Slack found your account.', (string) $client->getResponse()->getContent());
        self::assertSame(['users.lookupByEmail'], $this->fakeSlack($kernel)->methods());
    }

    public function testGuards(): void
    {
        $kernel = $this->configured();
        $client = $this->client($kernel);
        foreach (['claim', 'find', 'confirm', 'unlink'] as $action) {
            $client->request('POST', '/slack-kit/account/slack/' . $action, ['csrf_only' => ['_token' => 'forged'], 'nowo_slack_kit_link' => ['slack_user_id' => Fake::USER, '_token' => 'forged']]);
            self::assertSame(403, $client->getResponse()->getStatusCode(), $action);
        }
        $client->request('POST', '/slack-kit/account/slack/claim', []);
        self::assertSame(403, $client->getResponse()->getStatusCode(), 'not submitted');

        $anonymous = $this->client($kernel, null);
        $anonymous->request('GET', '/profile');
        self::assertCount(0, $anonymous->getCrawler()->filter('[data-testid="slack-kit-link-status"]'), 'no card for guests');
        $anonymous->request('POST', '/slack-kit/account/slack/unlink');
        self::assertSame(403, $anonymous->getResponse()->getStatusCode());
    }

    public function testIngressRoutesAndTwigUrl(): void
    {
        $kernel = $this->configured(['ingress' => ['interactions_path' => '/hooks/slack/interactions', 'events_enabled' => true]]);
        $client = $this->client($kernel, null);
        $client->request('POST', '/hooks/slack/interactions', [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'payload=%7B%7D');
        self::assertSame(401, $client->getResponse()->getStatusCode());
        $client->request('POST', '/slack/interactions', [], [], [], '');
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $body = (string) json_encode(['type' => 'url_verification', 'challenge' => 'fake_challenge']);
        $ts   = (string) time();
        $client->request('POST', '/slack/events', [], [], ['CONTENT_TYPE' => 'application/json'] + $this->signedServer($body, $ts), $body);
        self::assertSame('{"challenge":"fake_challenge"}', $client->getResponse()->getContent());

        $twig = $this->container($kernel)->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        self::assertSame('http://localhost/hooks/slack/interactions|http://localhost/hooks/slack/interactions/ops|http://localhost/slack/events', $twig->createTemplate("{{ nowo_slack_kit_ingress_url() }}|{{ nowo_slack_kit_ingress_url('interactions', 'ops') }}|{{ nowo_slack_kit_ingress_url('events') }}")->render());
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function configured(array $extra = []): SlackKitTestKernel
    {
        $kernel = $this->bootKernel(array_replace_recursive(self::LINKING, $extra));
        $this->settings($kernel)->save('slack', ['bot_token' => Fake::TOKEN, 'signing_secret' => Fake::SIGNING_SECRET, 'team_id' => Fake::TEAM, 'allow_email_lookup' => true]);

        return $kernel;
    }

    /**
     * @return array{status: int, body: string}
     */
    private function click(SlackKitTestKernel $kernel, string $actionId, string $value): array
    {
        $client = $this->client($kernel, null);
        $body   = Fake::form(Fake::blockActions($actionId, $value));
        $client->request('POST', '/slack/interactions', [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'] + $this->signedServer($body, (string) time()), $body);

        return ['status' => $client->getResponse()->getStatusCode(), 'body' => (string) $client->getResponse()->getContent()];
    }

    /**
     * @return array<string, string>
     */
    private function signedServer(string $body, string $ts): array
    {
        return Fake::signed($body, (int) $ts);
    }
}
