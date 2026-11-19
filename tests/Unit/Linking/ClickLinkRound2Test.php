<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Linking;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\SlackKitBundle\Api\SlackMessage;
use Nowo\SlackKitBundle\Api\SlackUserProfile;
use Nowo\SlackKitBundle\Credentials\InMemoryInstallationStore;
use Nowo\SlackKitBundle\Credentials\SlackInstallation;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Event\SlackIdentityLinkedEvent;
use Nowo\SlackKitBundle\Event\SlackLinkReleasedEvent;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Interaction\ButtonValueCodec;
use Nowo\SlackKitBundle\Linking\ClickLinker;
use Nowo\SlackKitBundle\Linking\ClickLinkPreview;
use Nowo\SlackKitBundle\Linking\ClickLinkSigner;
use Nowo\SlackKitBundle\Linking\LinkResult;
use Nowo\SlackKitBundle\Linking\SlackIdentityLinksInterface;
use Nowo\SlackKitBundle\Linking\SlackLink;
use Nowo\SlackKitBundle\Linking\SlackVerifiedEmailCheck;
use Nowo\SlackKitBundle\Linking\SlackVerifiedEmailCheckerInterface;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use Nowo\SlackKitBundle\Tests\Support\FakeDirectory;
use Nowo\SlackKitBundle\Tests\Support\FakeIdentityLinks;
use Nowo\SlackKitBundle\Tests\Support\RacingIdentityLinks;
use Nowo\SlackKitBundle\Tests\Support\RecordingApi;
use Nowo\SlackKitBundle\Tests\Support\RecordingDispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function explode;
use function json_decode;
use function json_encode;
use function str_repeat;
use function substr;

/**
 * Audit PZ round 2 (ported from PzRound2PocTest, expectations inverted): no concurrent takeover, the token survives a
 * refused confirmation, ASCII-only e-mail checks, a sanitised plain-text DM label, Slack's unconfirmed e-mails refused.
 */
final class ClickLinkRound2Test extends TestCase
{
    private MockClock $clock;
    private InMemoryInstallationStore $store;
    private FakeIdentityLinks $inner;
    private FakeDirectory $directory;
    private RecordingApi $api;
    private RecordingDispatcher $events;
    private ClickLinkSigner $signer;
    private LockFactory $locks;

    protected function setUp(): void
    {
        $this->clock                           = new MockClock('@' . Fake::NOW);
        $this->store                           = Fake::store();
        $this->inner                           = new FakeIdentityLinks();
        $this->directory                       = new FakeDirectory();
        $this->api                             = new RecordingApi();
        $this->events                          = new RecordingDispatcher();
        $this->signer                          = new ClickLinkSigner(ClickLinkSigner::deriveKey(Fake::KERNEL_SECRET), $this->clock);
        $this->locks                           = new LockFactory(new InMemoryStore());
        $this->directory->profiles[Fake::USER] = new SlackUserProfile(SlackUserId::fromString(Fake::USER), 'Ana', 'ana@example.test');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function nonAsciiEmails(): iterable
    {
        yield 'Kelvin sign' => ["victim@\u{212A}orp.example"];
        yield 'dotted capital I' => ["\u{0130}nfo@x.example"];
        yield 'zero-width space' => ["a\u{200B}@x.example"];
        yield 'space inside' => ['a b@x.example'];
        yield 'two @' => ['a@b@x.example'];
        yield 'no local part' => ['@x.example'];
        yield 'too long' => [str_repeat('a', 250) . '@x.test'];
    }

    /**
     * R2-L1: only printable-ASCII addresses; ASCII lower-casing only.
     */
    #[DataProvider('nonAsciiEmails')]
    public function testEmailChecksRefuseNonAsciiAndInvisibleCharacters(string $email): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SlackVerifiedEmailCheck('a', $email);
    }

    public function testALookalikeSlackEmailNeverMatches(): void
    {
        self::assertSame('victim@korp.example', (new SlackVerifiedEmailCheck('a', " Victim@KORP.example\n"))->email);
        $this->inner->verifiedEmails['acc-a']  = ['victim@korp.example'];
        $this->directory->profiles[Fake::USER] = new SlackUserProfile(SlackUserId::fromString(Fake::USER), 'Ana', "victim@\u{212A}orp.example");

        self::assertSame(LinkResult::EmailUnavailable, $this->linker($this->inner)->confirm('acc-a', $this->token()));
        self::assertSame([], $this->inner->all());
    }

    /**
     * R2-M1: two accounts confirm the same member at once (opt-in installation). The second runs while the first holds
     * the (workspace, member) lock: it is refused, the first links, nothing is taken, and the second's token survives.
     */
    public function testConcurrentConfirmationsNeverTakeAnIdentity(): void
    {
        $this->optIn();
        $box    = new RaceBox();
        $tokenB = $this->token();
        $store  = new RacingIdentityLinks($this->inner, static function () use ($box, $tokenB): void {
            $box->result = $box->linker?->confirm('acc-b', $tokenB);
        });
        $box->linker = $this->linker($store);

        self::assertSame(LinkResult::Linked, $box->linker->confirm('acc-a', $this->token()));
        self::assertSame(LinkResult::Busy, $box->result, 'the concurrent confirmation is refused while the first holds the lock');
        self::assertSame('acc-a', $this->holder()?->accountId);
        self::assertSame([], $this->events->of(SlackLinkReleasedEvent::class));
        self::assertCount(1, $this->events->of(SlackIdentityLinkedEvent::class), 'one link, one DM');

        // B retries with the same (unburned) token: the identity is now verified for A.
        self::assertSame(LinkResult::InUse, $this->linker($this->inner)->confirm('acc-b', $tokenB));
        self::assertSame('acc-a', $this->holder()?->accountId);
    }

    /**
     * R2-M1, same-account variant: one account confirms two members of one workspace at once.
     */
    public function testOneAccountCannotLinkTwoMembersOfAWorkspaceAtOnce(): void
    {
        $this->inner->verifiedEmails['acc-a']        = ['ana@example.test'];
        $this->directory->profiles[Fake::OTHER_USER] = new SlackUserProfile(SlackUserId::fromString(Fake::OTHER_USER), 'Ana 2', 'ana@example.test');
        $box                                         = new RaceBox();
        $second                                      = $this->token(Fake::OTHER_USER);
        $store                                       = new RacingIdentityLinks($this->inner, static function () use ($box, $second): void {
            $box->result = $box->linker?->confirm('acc-a', $second);
        });
        $box->linker = $this->linker($store);

        self::assertSame(LinkResult::Linked, $box->linker->confirm('acc-a', $this->token()));
        self::assertSame(LinkResult::Busy, $box->result);
        self::assertCount(1, $this->inner->linksOf('acc-a'));
        self::assertSame(LinkResult::AlreadyLinkedInWorkspace, $this->linker($this->inner)->confirm('acc-a', $second));
    }

    /**
     * R2-M1: a writer that bypasses the locks (the 1.2 typed-claim flow, another process) is caught by the store
     * contract: saveLink() refuses, the confirmation answers InUse and nothing is replaced or announced.
     */
    public function testAStoreConflictMapsToInUse(): void
    {
        $this->optIn();
        $store = new RacingIdentityLinks($this->inner, function (): void {
            $at = new DateTimeImmutable('@1');
            $this->inner->saveLink(new SlackLink('acc-b', SlackTeamId::fromString(Fake::TEAM), SlackUserId::fromString(Fake::USER), $at, $at));
        });
        $token = $this->token();

        self::assertSame(LinkResult::InUse, $this->linker($store)->confirm('acc-a', $token));
        self::assertSame('acc-b', $this->holder()?->accountId);
        self::assertSame([], $this->events->of(SlackIdentityLinkedEvent::class));
        self::assertSame([], $this->api->calls, 'no DM');
    }

    /**
     * R2-L2 + R2-I1: a refused confirmation does not burn the token, and the page does not count against the limit.
     */
    public function testRefusedConfirmationsKeepTheTokenAndPagesAreFree(): void
    {
        $linker = $this->linker($this->inner, 3);
        $token  = $this->token();
        for ($i = 0; $i < 5; ++$i) {
            self::assertInstanceOf(ClickLinkPreview::class, $linker->preview('acc-a', $token), 'the page never counts');
        }
        self::assertSame(LinkResult::EmailMismatch, $linker->confirm('acc-a', $token), 'e-mail not verified yet');
        $this->inner->verifiedEmails['acc-a'] = ['ana@example.test'];
        self::assertSame(LinkResult::Linked, $linker->confirm('acc-a', $token), 'the same link still works');
        self::assertSame(LinkResult::AlreadyVerified, $linker->confirm('acc-a', $token), 'reused: nothing changes');

        // Once a confirmation claimed it, the token is spent even if the link goes away.
        $fresh              = $this->linker($this->inner);
        $this->inner->links = [];
        $spent              = $this->token();
        self::assertSame(LinkResult::Linked, $fresh->confirm('acc-a', $spent));
        $this->inner->links = [];
        self::assertSame(LinkResult::LinkExpired, $fresh->confirm('acc-a', $spent), 'single use once it linked');

        $at = new DateTimeImmutable('@1');
        $this->inner->saveLink(new SlackLink('acc-a', SlackTeamId::fromString(Fake::TEAM), SlackUserId::fromString(Fake::USER), $at, $at));
        $limited = $this->linker($this->inner, 1);
        $other   = $this->token(Fake::OTHER_USER);
        self::assertSame(LinkResult::AlreadyLinkedInWorkspace, $limited->confirm('acc-a', $other));
        self::assertSame(LinkResult::RateLimited, $limited->confirm('acc-a', $other));
    }

    /**
     * R2-L4: a member whose e-mail Slack reports as unconfirmed is refused; without the flag the e-mail match applies.
     */
    public function testSlackUnconfirmedEmailsAreRefused(): void
    {
        $this->inner->verifiedEmails['acc-a']  = ['ana@example.test'];
        $this->directory->profiles[Fake::USER] = new SlackUserProfile(SlackUserId::fromString(Fake::USER), 'Ana', 'ana@example.test', false, false, false);
        self::assertSame(LinkResult::EmailUnconfirmed, $this->linker($this->inner)->confirm('acc-a', $this->token()));

        $this->directory->profiles[Fake::USER] = new SlackUserProfile(SlackUserId::fromString(Fake::USER), 'Ana', 'ana@example.test', false, false, true);
        self::assertSame(LinkResult::Linked, $this->linker($this->inner)->confirm('acc-a', $this->token()));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function labels(): iterable
    {
        yield 'an e-mail' => ['ana.private@example.test', 'slack.linked_dm.your_account'];
        yield 'a link' => ['see https://evil.example/unlink', 'slack.linked_dm.your_account'];
        yield 'a control character' => ["Ana\u{202E}nimda", 'slack.linked_dm.your_account'];
        yield 'empty' => ['  ', 'slack.linked_dm.your_account'];
        yield 'mrkdwn' => ['*Ana* <!channel>', '*Ana* <!channel>'];
        yield 'long' => [str_repeat('a', 100), str_repeat('a', 79) . '…'];
    }

    /**
     * R2-L3 (Beacon Q9): the label is a display name, sanitised, and sent as plain text (no mrkdwn, mentions, links).
     */
    #[DataProvider('labels')]
    public function testTheDmLabelIsSanitisedPlainText(string $label, string $shown): void
    {
        $this->inner->verifiedEmails['acc-a'] = ['ana@example.test'];
        self::assertSame(LinkResult::Linked, $this->linker(new RacingIdentityLinks($this->inner, null, $label))->confirm('acc-a', $this->token()));

        $dm = $this->api->calls[1][1][1];
        self::assertInstanceOf(SlackMessage::class, $dm);
        self::assertSame('plain_text', $dm->blocks[0]['text']['type']);
        self::assertFalse($dm->blocks[0]['text']['emoji']);
        self::assertSame('linked to ' . $shown, $dm->blocks[0]['text']['text']);
        self::assertSame('slack.confirm.linked', $dm->text, 'the notification text carries no label');
    }

    /**
     * Ported as is: no button value, retargeted team, other key or expired token passes.
     */
    public function testTokenCannotBeForgedFromAButtonValueOrRetargeted(): void
    {
        $codec  = new ButtonValueCodec(ButtonValueCodec::deriveKey(Fake::KERNEL_SECRET), [], $this->clock);
        $button = $codec->sign('nowo_slack_kit.link.confirm', ['uid' => Fake::USER], SlackTeamId::fromString(Fake::TEAM));
        self::assertNull($this->signer->verify($button));
        self::assertNull($this->signer->verify(substr($button, 5)));
        [$payload, $mac] = explode('.', $this->token());
        $data            = json_decode((string) ButtonValueCodec::base64UrlDecode($payload), true);
        self::assertIsArray($data);
        $data['t'] = Fake::OTHER_TEAM;
        self::assertNull($this->signer->verify(ButtonValueCodec::base64UrlEncode((string) json_encode($data)) . '.' . $mac));
        self::assertNull((new ClickLinkSigner(SlackSecret::key(str_repeat('k', 32)), $this->clock))->verify($this->token()));
        $expired = $this->token();
        $this->clock->modify('+601 seconds');
        self::assertNull($this->signer->verify($expired));
    }

    private function holder(): ?SlackLink
    {
        return $this->inner->findLinkBySlackUser(SlackTeamId::fromString(Fake::TEAM), SlackUserId::fromString(Fake::USER));
    }

    private function optIn(): void
    {
        $team = SlackTeamId::fromString(Fake::TEAM);
        $this->store->save(new SlackInstallation(new SlackInstallationKey('default', $team), SlackSecret::botToken(Fake::TOKEN), $team, allowUnverifiedLinkConfirm: true));
    }

    private function token(string $user = Fake::USER): string
    {
        $team = SlackTeamId::fromString(Fake::TEAM);

        return $this->signer->issue(new SlackInstallationKey('default', $team), $team, SlackUserId::fromString($user));
    }

    private function linker(SlackIdentityLinksInterface&SlackVerifiedEmailCheckerInterface $identities, int $limit = 50): ClickLinker
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('https://example.test/x');
        $translator = new class implements TranslatorInterface {
            /**
             * @param array<string, mixed> $parameters
             */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id === 'slack.linked_dm.text' ? 'linked to ' . ($parameters['%account%'] ?? '') : $id;
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };

        return new ClickLinker(
            $identities,
            $identities,
            $this->store,
            $this->api,
            $this->directory,
            $this->signer,
            $this->locks,
            new RateLimiterFactory(['id' => 'click', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 hour'], new InMemoryStorage()),
            $urls,
            $this->clock,
            'app_integrations',
            false,
            $this->events,
            $translator,
        );
    }
}

/**
 * Shared state of a raced confirmation.
 */
final class RaceBox
{
    public ?ClickLinker $linker = null;

    public ?LinkResult $result = null;
}
