<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Credentials;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\SlackKitBundle\Credentials\InMemoryInstallationStore;
use Nowo\SlackKitBundle\Credentials\InstanceSettingsInstallationStore;
use Nowo\SlackKitBundle\Credentials\SlackAppRegistry;
use Nowo\SlackKitBundle\Credentials\SlackInstallation;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use Nowo\SlackKitBundle\Tests\Support\FakeInstanceSettings;
use PHPUnit\Framework\TestCase;

final class StoresTest extends TestCase
{
    public function testInstallationKey(): void
    {
        self::assertSame('default', SlackInstallationKey::default()->key());
        self::assertSame('ops:' . Fake::TEAM, (new SlackInstallationKey('ops', SlackTeamId::fromString(Fake::TEAM)))->key());
        $this->expectException(InvalidArgumentException::class);
        new SlackInstallationKey('Bad App');
    }

    public function testInMemoryStore(): void
    {
        $store = Fake::store();
        $team  = SlackTeamId::fromString(Fake::TEAM);
        self::assertNotNull($store->find(SlackInstallationKey::default()));
        self::assertNotNull($store->find(new SlackInstallationKey('default', $team)));
        self::assertNull($store->find(new SlackInstallationKey('default', SlackTeamId::fromString(Fake::OTHER_TEAM))));
        self::assertNull($store->find(new SlackInstallationKey('other')));
        self::assertSame(Fake::SIGNING_SECRET, $store->findSigningSecret('default')?->reveal());
        self::assertNull($store->findSigningSecret('other'));

        $store->markRevoked(new SlackInstallationKey('default', $team), new DateTimeImmutable('@1'));
        $store->markRevoked(new SlackInstallationKey('default', $team), new DateTimeImmutable('@2'));
        self::assertSame(1, $store->find(SlackInstallationKey::default())?->revokedAt?->getTimestamp(), 'idempotent');
        $store->markRevoked(new SlackInstallationKey('none'), new DateTimeImmutable());

        $store->setSigningSecret('ops', SlackSecret::signingSecret(Fake::OTHER_SECRET));
        self::assertSame(Fake::OTHER_SECRET, $store->findSigningSecret('ops')?->reveal());
        $store->save(new SlackInstallation(new SlackInstallationKey('ops'), SlackSecret::botToken(Fake::TOKEN)));
        self::assertNotNull($store->find(new SlackInstallationKey('ops')));
        $store->reset();
        self::assertNull($store->find(new SlackInstallationKey('ops')));
        self::assertNull($store->findSigningSecret('ops'), 'worker reset drops runtime secrets');
        self::assertNotNull($store->findSigningSecret('default'));
        self::assertFalse($store->find(SlackInstallationKey::default())?->isRevoked());
    }

    public function testInstanceSettingsStoreUsesBeaconKeys(): void
    {
        $settings = new FakeInstanceSettings(['slack.team_id' => Fake::TEAM, 'slack.allow_email_lookup' => true], ['slack.bot_token' => Fake::TOKEN, 'slack.signing_secret' => Fake::SIGNING_SECRET]);
        $store    = new InstanceSettingsInstallationStore($settings);

        $installation = $store->find(SlackInstallationKey::default());
        self::assertNotNull($installation);
        self::assertSame(Fake::TOKEN, $installation->botToken->reveal());
        self::assertSame(Fake::TEAM, $installation->teamId?->value);
        self::assertTrue($installation->allowEmailLookup);
        self::assertNotNull($store->find(new SlackInstallationKey('default', SlackTeamId::fromString(Fake::TEAM))));
        self::assertNull($store->find(new SlackInstallationKey('default', SlackTeamId::fromString(Fake::OTHER_TEAM))));
        self::assertNull($store->find(new SlackInstallationKey('ops')));
        self::assertSame(Fake::SIGNING_SECRET, $store->findSigningSecret('default')?->reveal());
        self::assertNull($store->findSigningSecret('ops'));

        $store->save(new SlackInstallation(new SlackInstallationKey('ops'), $installation->botToken, SlackTeamId::fromString(Fake::OTHER_TEAM)));
        $store->save(new SlackInstallation(SlackInstallationKey::default(), $installation->botToken));
        self::assertSame(Fake::TEAM, $settings->values['slack.team_id']);
        $store->save(new SlackInstallation(SlackInstallationKey::default(), $installation->botToken, SlackTeamId::fromString(Fake::OTHER_TEAM)));
        self::assertSame(Fake::OTHER_TEAM, $settings->values['slack.team_id']);

        $store->markRevoked(new SlackInstallationKey('ops'), new DateTimeImmutable());
        self::assertArrayHasKey('slack.bot_token', $settings->secrets);
        $store->markRevoked(SlackInstallationKey::default(), new DateTimeImmutable());
        self::assertArrayNotHasKey('slack.bot_token', $settings->secrets);
        self::assertNull($store->find(SlackInstallationKey::default()));
        $store->markRevoked(SlackInstallationKey::default(), new DateTimeImmutable());
    }

    public function testInstanceSettingsStoreIgnoresMalformedTokens(): void
    {
        $store = new InstanceSettingsInstallationStore(new FakeInstanceSettings([], ['slack.bot_token' => 'xoxp-user-token-not-real']));
        self::assertNull($store->find(SlackInstallationKey::default()));
    }

    public function testAppRegistry(): void
    {
        $store    = new InMemoryInstallationStore(['default' => SlackSecret::signingSecret(Fake::SIGNING_SECRET)]);
        $registry = new SlackAppRegistry('default', ['default' => ['app_id' => null, 'signing_secret' => null], 'ops' => ['app_id' => Fake::APP_ID, 'signing_secret' => Fake::OTHER_SECRET]], $store);
        self::assertSame(['default', 'ops'], $registry->names());
        self::assertTrue($registry->has('ops'));
        self::assertNull($registry->appId('default'));
        self::assertSame(Fake::APP_ID, $registry->appId('ops'));
        self::assertSame(Fake::SIGNING_SECRET, $registry->signingSecret('default')?->reveal());
        self::assertSame(Fake::OTHER_SECRET, $registry->signingSecret('ops')?->reveal());
        self::assertNull($registry->signingSecret('nope'));
    }
}
