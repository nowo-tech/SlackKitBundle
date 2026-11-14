<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Credentials;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\SlackKitBundle\Credentials\InMemoryInstallationBindingStore;
use Nowo\SlackKitBundle\Credentials\InMemoryInstallationStore;
use Nowo\SlackKitBundle\Credentials\SlackAppRegistry;
use Nowo\SlackKitBundle\Credentials\SlackInstallation;
use Nowo\SlackKitBundle\Credentials\SlackInstallationBinding;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Credentials\SlackOwnerScope;
use Nowo\SlackKitBundle\Id\SlackEnterpriseId;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec 237 T000a: installation keys with an enterprise, the richer installation, owner scopes and bindings.
 */
final class MultiInstallationModelTest extends TestCase
{
    public function testKeysNameAppEnterpriseAndWorkspace(): void
    {
        $team  = SlackTeamId::fromString(Fake::TEAM);
        $ent   = SlackEnterpriseId::fromString(Fake::ENTERPRISE);
        $plain = new SlackInstallationKey('default', $team);
        $grid  = new SlackInstallationKey('default', $team, $ent);

        self::assertSame('default', SlackInstallationKey::default()->key());
        self::assertSame('default:' . Fake::TEAM, $plain->key());
        self::assertSame('default:' . Fake::ENTERPRISE . ':' . Fake::TEAM, $grid->key());
        self::assertSame('default:' . Fake::TEAM, $grid->workspaceKey());
        self::assertSame('default', SlackInstallationKey::default()->workspaceKey());
        self::assertTrue($grid->equals(new SlackInstallationKey('default', $team, $ent)));
        self::assertFalse($grid->equals($plain));
        self::assertFalse($grid->equals(null));
        self::assertTrue($grid->sameWorkspace($plain));
        self::assertTrue($plain->sameWorkspace($grid));
        self::assertFalse($grid->sameWorkspace(new SlackInstallationKey('default', $team, SlackEnterpriseId::fromString(Fake::OTHER_ENTERPRISE))));
        self::assertFalse($plain->sameWorkspace(new SlackInstallationKey('default', SlackTeamId::fromString(Fake::OTHER_TEAM))));
        self::assertFalse($plain->sameWorkspace(new SlackInstallationKey('ops', $team)));
        self::assertFalse(SlackInstallationKey::default()->sameWorkspace(SlackInstallationKey::default()), 'Workspace-less keys never name the same workspace.');
        self::assertFalse($plain->sameWorkspace(null));

        $this->expectException(InvalidArgumentException::class);
        new SlackInstallationKey('default', null, $ent);
    }

    public function testKeysAdmitOnlyTheirOwnWorkspace(): void
    {
        $team  = SlackTeamId::fromString(Fake::TEAM);
        $first = Fake::installation(Fake::TEAM, Fake::TOKEN, Fake::ENTERPRISE);

        self::assertTrue(SlackInstallationKey::default()->admits($first), 'A workspace-less key admits any installation of the app.');
        self::assertFalse((new SlackInstallationKey('ops'))->admits($first));
        self::assertTrue((new SlackInstallationKey('default', $team))->admits($first));
        self::assertTrue((new SlackInstallationKey('default', $team, SlackEnterpriseId::fromString(Fake::ENTERPRISE)))->admits($first));
        self::assertFalse((new SlackInstallationKey('default', $team, SlackEnterpriseId::fromString(Fake::OTHER_ENTERPRISE)))->admits($first));
        self::assertFalse((new SlackInstallationKey('default', SlackTeamId::fromString(Fake::OTHER_TEAM)))->admits($first));
        self::assertFalse((new SlackInstallationKey('default', $team))->admits(new SlackInstallation(SlackInstallationKey::default(), SlackSecret::botToken(Fake::TOKEN))), 'An installation without a workspace never serves a workspace key.');
    }

    public function testInstallationCarriesTheOauthFields(): void
    {
        $now          = new DateTimeImmutable('@' . Fake::NOW);
        $installation = new SlackInstallation(
            new SlackInstallationKey('default', SlackTeamId::fromString(Fake::TEAM)),
            SlackSecret::botToken(Fake::TOKEN),
            SlackTeamId::fromString(Fake::TEAM),
            true,
            'U0000000B',
            null,
            SlackEnterpriseId::fromString(Fake::ENTERPRISE),
            Fake::APP_ID,
            'Fake team',
            ['chat:write', 'users:read.email'],
            SlackUserId::fromString(Fake::USER),
            $now,
            SlackSecret::refreshToken(Fake::REFRESH_TOKEN),
            $now->modify('+1 hour'),
            null,
            SlackSecret::signingSecret(Fake::OTHER_SECRET),
        );

        self::assertTrue($installation->hasScope('users:read.email'));
        self::assertFalse($installation->hasScope('chat:write.public'));
        self::assertFalse($installation->isTokenExpired($now));
        self::assertTrue($installation->isTokenExpired($now->modify('+1 hour')));
        self::assertFalse(Fake::installation()->isTokenExpired($now));
        self::assertSame('default:' . Fake::ENTERPRISE . ':' . Fake::TEAM, $installation->storageKey()->key());
        self::assertSame('ops', (new SlackInstallation(new SlackInstallationKey('ops'), SlackSecret::botToken(Fake::TOKEN)))->storageKey()->key());

        $moved = $installation->withKey(new SlackInstallationKey('ops'));
        self::assertSame('ops', $moved->key->app);
        self::assertSame(['chat:write', 'users:read.email'], $moved->scopes);
        self::assertSame(Fake::OTHER_SECRET, $moved->signingSecret?->reveal());

        $revoked = $installation->revoked($now, 'app_uninstalled');
        self::assertTrue($revoked->isRevoked());
        self::assertSame('app_uninstalled', $revoked->revokedReason);
        self::assertSame('Fake team', $revoked->teamName);

        $tested = (new SlackInstallation(SlackInstallationKey::default(), SlackSecret::botToken(Fake::TOKEN)))->withWorkspace(SlackTeamId::fromString(Fake::OTHER_TEAM), 'U0000000C');
        self::assertSame(Fake::OTHER_TEAM, $tested->teamId?->value);
        self::assertSame('U0000000C', $tested->botUserId);
    }

    /**
     * @return iterable<string, array{0: callable(): mixed}>
     */
    public static function invalidInstallations(): iterable
    {
        $key = SlackInstallationKey::default();
        yield 'signing secret as token' => [static fn (): SlackInstallation => new SlackInstallation($key, SlackSecret::signingSecret(Fake::SIGNING_SECRET))];
        yield 'bot token as refresh token' => [static fn (): SlackInstallation => new SlackInstallation($key, SlackSecret::botToken(Fake::TOKEN), refreshToken: SlackSecret::botToken(Fake::TOKEN))];
        yield 'token as own-app secret' => [static fn (): SlackInstallation => new SlackInstallation($key, SlackSecret::botToken(Fake::TOKEN), signingSecret: SlackSecret::botToken(Fake::TOKEN))];
        yield 'app id' => [static fn (): SlackInstallation => new SlackInstallation($key, SlackSecret::botToken(Fake::TOKEN), appId: 'not-an-app')];
        yield 'scope' => [static fn (): SlackInstallation => new SlackInstallation($key, SlackSecret::botToken(Fake::TOKEN), scopes: ['Chat Write'])];
        yield 'scope type' => [static fn (): SlackOwnerScope => new SlackOwnerScope('Organization', 'org-1')];
        yield 'scope id' => [static fn (): SlackOwnerScope => new SlackOwnerScope('organization', 'org 1')];
        yield 'binding without workspace' => [static fn (): SlackInstallationBinding => new SlackInstallationBinding($key, new SlackOwnerScope('organization', 'org-1'))];
        yield 'enterprise id' => [static fn (): SlackEnterpriseId => SlackEnterpriseId::fromString(Fake::TEAM)];
        yield 'refresh token' => [static fn (): SlackSecret => SlackSecret::refreshToken(Fake::TOKEN)];
        yield 'client secret' => [static fn (): SlackSecret => SlackSecret::clientSecret('short')];
        yield 'response url' => [static fn (): SlackSecret => SlackSecret::responseUrl(Fake::WEBHOOK)];
    }

    /**
     * @param callable(): mixed $build
     */
    #[DataProvider('invalidInstallations')]
    public function testMalformedValuesAreRefused(callable $build): void
    {
        $this->expectException(InvalidArgumentException::class);
        $build();
    }

    public function testNewSecretKindsStayRedacted(): void
    {
        foreach ([SlackSecret::refreshToken(Fake::REFRESH_TOKEN), SlackSecret::clientSecret(Fake::CLIENT_SECRET), SlackSecret::responseUrl(Fake::RESPONSE_URL)] as $secret) {
            self::assertSame(SlackSecret::REDACTED, (string) $secret);
        }
        self::assertSame(SlackSecret::RESPONSE_URL, SlackSecret::tryResponseUrl(Fake::RESPONSE_URL)?->kind);
        self::assertNull(SlackSecret::tryResponseUrl('https://evil.example/actions/x'));
        self::assertNull(SlackSecret::tryResponseUrl(42));
        self::assertNull(SlackSecret::tryResponseUrl(' '));
        self::assertSame(SlackSecret::REFRESH_TOKEN, SlackSecret::tryRefreshToken(Fake::REFRESH_TOKEN)?->kind);
        self::assertNull(SlackSecret::tryRefreshToken(null));
        self::assertNull(SlackSecret::tryRefreshToken(''));
        self::assertNull(SlackSecret::tryRefreshToken(Fake::TOKEN));
        self::assertSame(Fake::ENTERPRISE, (string) SlackEnterpriseId::fromString(Fake::ENTERPRISE));
        self::assertNull(SlackEnterpriseId::tryFrom('enterprise'));
    }

    public function testOwnerScopesAndBindingsCompareExactly(): void
    {
        $org  = new SlackOwnerScope('organization', 'org-1');
        $key  = new SlackInstallationKey('default', SlackTeamId::fromString(Fake::TEAM));
        $grid = new SlackInstallationKey('default', SlackTeamId::fromString(Fake::TEAM), SlackEnterpriseId::fromString(Fake::ENTERPRISE));

        self::assertSame('organization:org-1', $org->key());
        self::assertTrue($org->equals(new SlackOwnerScope('organization', 'org-1')));
        self::assertFalse($org->equals(new SlackOwnerScope('organization', 'ORG-1')));
        self::assertFalse($org->equals(new SlackOwnerScope('personal', 'org-1')));
        self::assertFalse($org->equals(null));
        self::assertTrue((new SlackInstallationBinding($key, $org))->sameAs(new SlackInstallationBinding($grid, $org, new DateTimeImmutable('@1'), 'acct')));
        self::assertFalse((new SlackInstallationBinding($key, $org))->sameAs(new SlackInstallationBinding($key, new SlackOwnerScope('organization', 'org-2'))));
    }

    public function testInMemoryStoreKeepsWorkspacesApart(): void
    {
        $store = Fake::store();
        $store->save(Fake::installation(Fake::OTHER_TEAM, Fake::OTHER_TOKEN, Fake::ENTERPRISE));

        $first = $store->find(new SlackInstallationKey('default', SlackTeamId::fromString(Fake::TEAM)));
        $other = $store->find(new SlackInstallationKey('default', SlackTeamId::fromString(Fake::OTHER_TEAM)));
        self::assertSame(Fake::TOKEN, $first?->botToken->reveal());
        self::assertSame(Fake::OTHER_TOKEN, $other?->botToken->reveal());
        self::assertNull($store->find(new SlackInstallationKey('default', SlackTeamId::fromString(Fake::OTHER_TEAM), SlackEnterpriseId::fromString(Fake::OTHER_ENTERPRISE))), 'Another enterprise is refused.');

        $store->markRevoked(new SlackInstallationKey('default', SlackTeamId::fromString(Fake::OTHER_TEAM)), new DateTimeImmutable('@' . Fake::NOW));
        self::assertTrue($store->find(new SlackInstallationKey('default', SlackTeamId::fromString(Fake::OTHER_TEAM)))?->isRevoked());
        self::assertFalse($store->find(new SlackInstallationKey('default', SlackTeamId::fromString(Fake::TEAM)))?->isRevoked());
    }

    public function testInMemoryBindingStoreResetsToItsInitialBindings(): void
    {
        $key   = new SlackInstallationKey('default', SlackTeamId::fromString(Fake::TEAM));
        $org   = new SlackOwnerScope('organization', 'org-1');
        $store = new InMemoryInstallationBindingStore([new SlackInstallationBinding($key, $org)]);
        $store->bind(new SlackInstallationBinding(new SlackInstallationKey('default', SlackTeamId::fromString(Fake::OTHER_TEAM)), $org));
        self::assertCount(2, $store->bindingsFor($org));

        $store->reset();
        self::assertCount(1, $store->bindingsFor($org));
        self::assertCount(1, $store->bindingsOf($key));
    }

    public function testRegistryKnowsTheOauthClient(): void
    {
        $registry = new SlackAppRegistry('default', [
            'default' => ['app_id' => Fake::APP_ID, 'signing_secret' => null, 'client_id' => Fake::CLIENT_ID, 'client_secret' => Fake::CLIENT_SECRET],
            'ops'     => ['app_id' => null, 'signing_secret' => null, 'client_id' => '', 'client_secret' => 'bad secret'],
            'legacy'  => ['app_id' => Fake::APP_ID, 'signing_secret' => null],
        ], new InMemoryInstallationStore());

        self::assertTrue($registry->canInstall('default'));
        self::assertSame(Fake::CLIENT_ID, $registry->clientId('default'));
        self::assertSame(Fake::CLIENT_SECRET, $registry->clientSecret('default')?->reveal());
        self::assertFalse($registry->canInstall('ops'));
        self::assertNull($registry->clientId('ops'));
        self::assertNull($registry->clientSecret('ops'));
        self::assertFalse($registry->canInstall('legacy'));
        self::assertNull($registry->clientSecret('legacy'));
        self::assertFalse($registry->canInstall('missing'));
    }
}
