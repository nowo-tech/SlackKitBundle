<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Integration\Functional;

use Nowo\SlackKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\SlackKitBundle\Tests\Support\Fake;

/**
 * The `slack` instance settings section and *Test connection*, end to end, against the fake Slack API.
 */
final class SettingsSectionTest extends KernelTestCase
{
    private const PAGE = '/admin/settings/sections/slack';
    private const TEST = '/slack-kit/settings/test-connection';

    public function testUnconfiguredSectionShowsFieldsAndNotice(): void
    {
        $client = $this->client($this->bootKernel());
        $client->request('GET', self::PAGE);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Bot token', $html);
        self::assertStringContainsString('Signing secret', $html);
        self::assertStringContainsString('http://localhost/slack/interactions', $html);
        self::assertStringContainsString('Slack is not configured', $html);
        self::assertStringNotContainsString('/slack-kit/settings/test-connection', $html);
    }

    public function testTestConnectionFillsTheWorkspaceAndNeverShowsTheToken(): void
    {
        $kernel = $this->bootKernel();
        $this->settings($kernel)->save('slack', ['bot_token' => Fake::TOKEN, 'signing_secret' => Fake::SIGNING_SECRET]);
        $client = $this->client($kernel);

        $crawler = $client->request('GET', self::PAGE);
        self::assertStringContainsString('The workspace id is missing', (string) $client->getResponse()->getContent());
        $client->submit($crawler->filter('form[action="' . self::TEST . '"]')->form());

        self::assertSame(303, $client->getResponse()->getStatusCode());
        self::assertSame(self::PAGE, $client->getResponse()->headers->get('Location'));
        $client->followRedirect();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Connected to Fake team (' . Fake::TEAM . ') as fakebot.', $html);
        self::assertStringNotContainsString(Fake::TOKEN, $html);
        self::assertSame(Fake::TEAM, $this->settings($kernel)->getString('slack.team_id'));
        self::assertSame(['auth.test'], $this->fakeSlack($kernel)->methods());
    }

    public function testRateLimitAndGuards(): void
    {
        $kernel = $this->bootKernel(['test_connection' => ['rate_limit' => ['limit' => 1, 'interval' => '1 hour']]]);
        $this->settings($kernel)->save('slack', ['bot_token' => Fake::TOKEN, 'signing_secret' => Fake::SIGNING_SECRET, 'team_id' => Fake::TEAM]);
        $client = $this->client($kernel);
        for ($i = 0; $i < 2; ++$i) {
            $crawler = $client->request('GET', self::PAGE);
            $client->submit($crawler->filter('form[action="' . self::TEST . '"]')->form());
            $client->followRedirect();
        }
        self::assertStringContainsString('Too many connection tests.', (string) $client->getResponse()->getContent());

        $client->request('POST', self::TEST, ['csrf_only' => ['_token' => 'forged']]);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $client->request('GET', self::TEST);
        self::assertSame(405, $client->getResponse()->getStatusCode());

        $viewer = $this->client($this->bootKernel(), 'viewer');
        $viewer->request('POST', self::TEST, ['csrf_only' => ['_token' => 'x']]);
        self::assertSame(403, $viewer->getResponse()->getStatusCode());
    }

    public function testSlackRefusal(): void
    {
        $kernel = $this->bootKernel();
        $this->settings($kernel)->save('slack', ['bot_token' => 'xoxb-test-revoked-not-real', 'signing_secret' => Fake::SIGNING_SECRET, 'team_id' => Fake::TEAM]);
        $this->fakeSlack($kernel)->failWith = 'invalid_auth';
        $client                             = $this->client($kernel);
        $crawler                            = $client->request('GET', self::PAGE);
        $client->submit($crawler->filter('form[action="' . self::TEST . '"]')->form());
        $client->followRedirect();
        self::assertStringContainsString('Slack refused the connection test: invalid_auth.', (string) $client->getResponse()->getContent());
    }
}
