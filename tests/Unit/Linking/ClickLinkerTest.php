<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Linking;

use DateTimeImmutable;
use Nowo\SlackKitBundle\Api\SlackMessage;
use Nowo\SlackKitBundle\Api\SlackUserProfile;
use Nowo\SlackKitBundle\Credentials\InMemoryInstallationStore;
use Nowo\SlackKitBundle\Credentials\SlackInstallation;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Event\SlackAccountUnlinkedEvent;
use Nowo\SlackKitBundle\Event\SlackAccountVerifiedEvent;
use Nowo\SlackKitBundle\Event\SlackIdentityLinkedEvent;
use Nowo\SlackKitBundle\Event\SlackLinkReleasedEvent;
use Nowo\SlackKitBundle\Id\SlackChannelId;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Interaction\SlackInteraction;
use Nowo\SlackKitBundle\Linking\ClickLinker;
use Nowo\SlackKitBundle\Linking\ClickLinkPreview;
use Nowo\SlackKitBundle\Linking\ClickLinkSigner;
use Nowo\SlackKitBundle\Linking\ClickLinkToken;
use Nowo\SlackKitBundle\Linking\LinkResult;
use Nowo\SlackKitBundle\Linking\SlackLink;
use Nowo\SlackKitBundle\Linking\SlackVerifiedEmailCheck;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use Nowo\SlackKitBundle\Tests\Support\FakeDirectory;
use Nowo\SlackKitBundle\Tests\Support\FakeIdentityLinks;
use Nowo\SlackKitBundle\Tests\Support\RecordingApi;
use Nowo\SlackKitBundle\Tests\Support\RecordingDispatcher;
use Nowo\SlackKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function explode;
use function json_encode;
use function str_repeat;
use function strlen;
use function substr;

/**
 * Spec 237 T000b: link from a click — signed single-use link, verified e-mail match by default, one identity per
 * workspace, confirmation DM; never for resolver-verified, own-app or legacy clicks; nothing personal in logs.
 */
final class ClickLinkerTest extends TestCase
{
    private const EMAIL = 'Ana@Example.test';

    private MockClock $clock;
    private InMemoryInstallationStore $store;
    private FakeIdentityLinks $identities;
    private FakeDirectory $directory;
    private RecordingApi $api;
    private RecordingDispatcher $events;
    private RecordingLogger $logger;
    private ClickLinkSigner $signer;

    protected function setUp(): void
    {
        $this->clock      = new MockClock('@' . Fake::NOW);
        $this->store      = Fake::store();
        $this->identities = new FakeIdentityLinks();
        $this->directory  = new FakeDirectory();
        $this->api        = new RecordingApi();
        $this->events     = new RecordingDispatcher();
        $this->logger     = new RecordingLogger();
        $this->signer     = new ClickLinkSigner(ClickLinkSigner::deriveKey(Fake::KERNEL_SECRET), $this->clock);
        $this->store->save(Fake::installation(Fake::OTHER_TEAM, Fake::OTHER_TOKEN));
        $this->directory->profiles[Fake::USER]     = new SlackUserProfile(SlackUserId::fromString(Fake::USER), 'Ana', self::EMAIL);
        $this->identities->verifiedEmails['acc-a'] = ['ana@example.test'];
    }

    public function testAnUnlinkedSlackClickGetsASignedLinkOnly(): void
    {
        $linker = $this->linker();
        $offer  = $linker->offer($this->click());
        self::assertInstanceOf(SlackMessage::class, $offer);
        $button = $offer->blocks[1]['elements'][0];
        self::assertSame(ClickLinker::OPEN_ACTION, $button['action_id']);
        self::assertStringStartsWith('https://example.test/link/', $button['url']);
        $token = $this->signer->verify(substr($button['url'], strlen('https://example.test/link/')));
        self::assertInstanceOf(ClickLinkToken::class, $token);
        self::assertSame(Fake::TEAM, $token->team->value);
        self::assertSame(Fake::USER, $token->user->value);
        self::assertSame('default:' . Fake::TEAM, $token->installation->key());
        self::assertSame(Fake::NOW + 600, $token->expiresAt);

        self::assertNull($linker->offer($this->click(resolver: true)), 'resolver-verified: signer-chosen member');
        self::assertNull($linker->offer($this->click(ownApp: true)), 'own-app: signer-chosen member');
        self::assertNull($linker->offer($this->click(legacy: true)), 'legacy unsigned value');
        self::assertNull($linker->offer($this->click(installationTeam: Fake::OTHER_TEAM)), 'click from another workspace than the installation');
        self::assertNull($linker->offer($this->click(user: null)));
        $this->identities->saveLink($this->link('acc-z', Fake::TEAM, Fake::USER));
        self::assertNull($linker->offer($this->click()), 'the member is already linked');
    }

    public function testConfirmLinksWithAVerifiedEmailOnceAndNotifiesTheMember(): void
    {
        $linker = $this->linker();
        $token  = $this->token();

        $preview = $linker->preview('acc-a', $token);
        self::assertInstanceOf(ClickLinkPreview::class, $preview);
        self::assertSame('Ana', $preview->displayName);
        self::assertSame(Fake::TEAM, $preview->teamName);
        self::assertSame(Fake::USER, $preview->slackUserId);
        self::assertSame([], $this->identities->all(), 'the page links nothing');

        self::assertSame(LinkResult::Linked, $linker->confirm('acc-a', $token));
        $link = $this->identities->findLinkBySlackUser(SlackTeamId::fromString(Fake::TEAM), SlackUserId::fromString(Fake::USER));
        self::assertSame('acc-a', $link?->accountId);
        self::assertTrue($link->isVerified());
        self::assertSame(SlackLink::SOURCE_CLICK, $link->source);
        self::assertSame('ana@example.test', $this->identities->checks[0]->email, 'compared lower-cased');
        self::assertCount(1, $this->events->of(SlackAccountVerifiedEvent::class));
        $linked = $this->events->of(SlackIdentityLinkedEvent::class);
        self::assertTrue($linked[0]->emailMatched);
        self::assertSame('default:' . Fake::TEAM, $linked[0]->installation->key());
        self::assertSame(['conversations.open', 'chat.postMessage'], $this->api->methods());
        self::assertSame(['default:' . Fake::TEAM, 'default:' . Fake::TEAM], $this->api->keys, 'the DM goes through the click installation');
        $dm = $this->api->calls[1][1][1];
        self::assertInstanceOf(SlackMessage::class, $dm);
        self::assertSame('slack.linked_dm.unlink', $dm->blocks[1]['elements'][0]['text']['text']);

        self::assertSame(LinkResult::LinkExpired, $linker->confirm('acc-a', $token), 'single use');
        $logs = (string) json_encode($this->logger->records);
        self::assertStringNotContainsStringIgnoringCase('example.test', $logs, 'no e-mail in logs');
        self::assertStringNotContainsString($token, $logs, 'no token in logs');
    }

    public function testAForwardedLinkOrAnUnverifiedEmailIsRefused(): void
    {
        $linker = $this->linker();
        // Luis opens the link Ana's click produced: his verified e-mails are not Ana's.
        $this->identities->verifiedEmails['acc-luis'] = ['luis@example.test'];
        self::assertSame(LinkResult::EmailMismatch, $linker->confirm('acc-luis', $this->token()));
        // An account that only has Ana's address unverified (not in its verified list) is refused too.
        self::assertSame(LinkResult::EmailMismatch, $linker->confirm('acc-unverified', $this->token()));
        // Slack does not tell the e-mail.
        $this->directory->profiles[Fake::USER] = new SlackUserProfile(SlackUserId::fromString(Fake::USER), 'Ana');
        self::assertSame(LinkResult::EmailUnavailable, $linker->confirm('acc-a', $this->token()));
        $this->directory->profiles[Fake::USER] = new SlackUserProfile(SlackUserId::fromString(Fake::USER), 'Ana', 'not-an-email');
        self::assertSame(LinkResult::EmailUnavailable, $linker->confirm('acc-a', $this->token()));
        self::assertSame([], $this->identities->all());
        self::assertSame([], $this->api->calls, 'no DM for a refusal');
    }

    public function testTheInstallationOptInConfirmsWithoutTheEmailMatch(): void
    {
        $optIn = new SlackInstallation(new SlackInstallationKey('default', SlackTeamId::fromString(Fake::TEAM)), SlackSecret::botToken(Fake::TOKEN), SlackTeamId::fromString(Fake::TEAM), teamName: 'Client A', allowUnverifiedLinkConfirm: true);
        $this->store->save($optIn);
        $linker = $this->linker();

        $preview = $linker->preview('acc-luis', $this->token());
        self::assertInstanceOf(ClickLinkPreview::class, $preview);
        self::assertSame('Client A', $preview->teamName);
        // Without the e-mail proof, an unverified claim of another account does not yield.
        $this->identities->saveLink(new SlackLink('acc-claimant', SlackTeamId::fromString(Fake::TEAM), SlackUserId::fromString(Fake::USER), new DateTimeImmutable('@1')));
        self::assertSame(LinkResult::InUse, $linker->confirm('acc-luis', $this->token()));
        $this->identities->links = [];

        self::assertSame(LinkResult::Linked, $linker->confirm('acc-luis', $this->token()));
        self::assertFalse($this->events->of(SlackIdentityLinkedEvent::class)[0]->emailMatched);
        self::assertSame([], $this->identities->checks, 'no e-mail check with the opt-in');
    }

    public function testIdentitiesAreNeverTakenAndOnePerWorkspace(): void
    {
        $linker = $this->linker();
        $this->identities->saveLink($this->link('acc-holder', Fake::TEAM, Fake::USER));
        self::assertSame(LinkResult::InUse, $linker->preview('acc-a', $this->token()));
        self::assertSame(LinkResult::InUse, $linker->confirm('acc-a', $this->token()), 'a verified identity of another account is never taken');
        self::assertSame([], $this->directory->calls, 'refused before asking Slack');

        // An unverified claim yields to a proven link only.
        $this->identities->links = [];
        $this->identities->saveLink(new SlackLink('acc-claimant', SlackTeamId::fromString(Fake::TEAM), SlackUserId::fromString(Fake::USER), new DateTimeImmutable('@1')));
        self::assertSame(LinkResult::Linked, $linker->confirm('acc-a', $this->token()));
        self::assertSame('claimed_by_click', $this->events->of(SlackLinkReleasedEvent::class)[0]->reason);
        self::assertSame('acc-a', $this->identities->findLinkBySlackUser(SlackTeamId::fromString(Fake::TEAM), SlackUserId::fromString(Fake::USER))?->accountId);
        self::assertSame(LinkResult::AlreadyVerified, $linker->preview('acc-a', $this->token()));

        // One identity per workspace: another member of the same workspace is refused for that account.
        $this->directory->profiles[Fake::OTHER_USER] = new SlackUserProfile(SlackUserId::fromString(Fake::OTHER_USER), 'Ana 2', self::EMAIL);
        self::assertSame(LinkResult::AlreadyLinkedInWorkspace, $linker->confirm('acc-a', $this->token(Fake::TEAM, Fake::OTHER_USER)));

        // …but a second workspace is fine: identities are per workspace.
        $this->directory->profiles[Fake::OTHER_USER] = new SlackUserProfile(SlackUserId::fromString(Fake::OTHER_USER), 'Ana B', self::EMAIL);
        self::assertSame(LinkResult::Linked, $linker->confirm('acc-a', $this->token(Fake::OTHER_TEAM, Fake::OTHER_USER)));
        self::assertCount(2, $linker->linksOf('acc-a'));

        self::assertSame(LinkResult::Unlinked, $linker->unlinkIn('acc-a', SlackTeamId::fromString(Fake::OTHER_TEAM)));
        self::assertSame(LinkResult::Unlinked, $linker->unlinkIn('acc-a', SlackTeamId::fromString(Fake::OTHER_TEAM)));
        self::assertCount(1, $linker->linksOf('acc-a'));
        $unlinked = $this->events->of(SlackAccountUnlinkedEvent::class);
        self::assertCount(1, $unlinked);
        self::assertSame(Fake::OTHER_TEAM, $unlinked[0]->teamId);
    }

    public function testUnusableTokensAccountsAndMembers(): void
    {
        $linker    = $this->linker();
        $token     = $this->token();
        [$payload] = explode('.', $token);

        self::assertSame(LinkResult::LinkExpired, $linker->preview('acc-a', $payload . '.' . str_repeat('A', 43)), 'forged MAC');
        self::assertSame(LinkResult::LinkExpired, $linker->preview('acc-a', 'not-a-token'));
        $other = new ClickLinkSigner(ClickLinkSigner::deriveKey('another-kernel-secret-not-real'), $this->clock);
        self::assertSame(LinkResult::LinkExpired, $linker->confirm('acc-a', $other->issue(new SlackInstallationKey('default', SlackTeamId::fromString(Fake::TEAM)), SlackTeamId::fromString(Fake::TEAM), SlackUserId::fromString(Fake::USER))), 'another key');

        $this->identities->active['acc-a'] = false;
        self::assertSame(LinkResult::Unavailable, $linker->preview('acc-a', $token), 'disabled account');
        $this->identities->active['acc-a'] = true;

        $this->directory->fail = 'user_not_found';
        self::assertSame(LinkResult::Unavailable, $linker->preview('acc-a', $token));
        self::assertSame('user_not_found', $this->logger->records[0]['context']['error'] ?? null);
        $this->directory->fail = null;

        $this->directory->profiles[Fake::USER] = new SlackUserProfile(SlackUserId::fromString(Fake::USER), 'bot', self::EMAIL, true);
        self::assertSame(LinkResult::Invalid, $linker->preview('acc-a', $token));
        $this->directory->profiles[Fake::USER] = new SlackUserProfile(SlackUserId::fromString(Fake::USER), 'gone', self::EMAIL, false, true);
        self::assertSame(LinkResult::Invalid, $linker->preview('acc-a', $token));

        $this->store->markRevoked(new SlackInstallationKey('default', SlackTeamId::fromString(Fake::TEAM)), new DateTimeImmutable('@1'));
        self::assertSame(LinkResult::Unavailable, $linker->preview('acc-a', $token), 'uninstalled workspace');
    }

    public function testExpiryRateLimitAndDmFailure(): void
    {
        $linker = $this->linker(limit: 2);
        $token  = $this->token();
        $this->clock->sleep(600);
        self::assertSame(LinkResult::LinkExpired, $linker->preview('acc-a', $token), 'expired after 10 minutes');

        $this->api->fail = 'channel_not_found';
        self::assertInstanceOf(ClickLinkPreview::class, $linker->preview('acc-a', $this->token()));
        self::assertSame(LinkResult::Linked, $linker->confirm('acc-a', $this->token()), 'the DM is best effort');
        self::assertSame('channel_not_found', $this->logger->records[1]['context']['error'] ?? null);
        self::assertSame(LinkResult::RateLimited, $linker->preview('acc-a', $this->token()));
    }

    public function testTheDmHasNoUnlinkButtonWithoutAManagePage(): void
    {
        $this->linker(manageRoute: null)->confirm('acc-a', $this->token());
        $dm = $this->api->calls[1][1][1];
        self::assertInstanceOf(SlackMessage::class, $dm);
        self::assertCount(1, $dm->blocks);
        self::assertSame('ana@example.test', (new SlackVerifiedEmailCheck('x', ' ANA@example.test '))->email);
    }

    private function linker(int $limit = 50, ?string $manageRoute = 'app_integrations'): ClickLinker
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route, array $params = []): string => $route === 'app_integrations' ? 'https://example.test/integrations' : 'https://example.test/link/' . ($params['token'] ?? ''));

        return new ClickLinker(
            $this->identities,
            $this->identities,
            $this->store,
            $this->api,
            $this->directory,
            $this->signer,
            new LockFactory(new InMemoryStore()),
            new RateLimiterFactory(['id' => 'click', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 hour'], new InMemoryStorage()),
            $urls,
            $this->clock,
            $manageRoute,
            false,
            $this->events,
            null,
            'en',
            $this->logger,
        );
    }

    private function token(string $team = Fake::TEAM, string $user = Fake::USER): string
    {
        return $this->signer->issue(new SlackInstallationKey('default', SlackTeamId::fromString($team)), SlackTeamId::fromString($team), SlackUserId::fromString($user));
    }

    private function link(string $account, string $team, string $user): SlackLink
    {
        return new SlackLink($account, SlackTeamId::fromString($team), SlackUserId::fromString($user), new DateTimeImmutable('@1'), new DateTimeImmutable('@1'));
    }

    private function click(bool $resolver = false, bool $ownApp = false, bool $legacy = false, string $installationTeam = Fake::TEAM, ?string $user = Fake::USER): SlackInteraction
    {
        return new SlackInteraction(
            new SlackInstallationKey('default', SlackTeamId::fromString($installationTeam)),
            'beacon.issue.resolve',
            [],
            SlackTeamId::fromString(Fake::TEAM),
            $user !== null ? SlackUserId::fromString($user) : null,
            null,
            SlackChannelId::fromString(Fake::CHANNEL),
            '1.0',
            'id',
            $legacy,
            $resolver,
            verifiedByOwnApp: $ownApp,
        );
    }
}
