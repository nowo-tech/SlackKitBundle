<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Linking;

use Closure;
use DateTimeImmutable;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Event\SlackAccountClaimedEvent;
use Nowo\SlackKitBundle\Id\SlackChannelId;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Interaction\ButtonValueCodec;
use Nowo\SlackKitBundle\Interaction\SlackInteraction;
use Nowo\SlackKitBundle\Linking\AccountLinker;
use Nowo\SlackKitBundle\Linking\ClickLinker;
use Nowo\SlackKitBundle\Linking\LinkResult;
use Nowo\SlackKitBundle\Linking\SlackIdentityLinksInterface;
use Nowo\SlackKitBundle\Linking\SlackLink;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use Nowo\SlackKitBundle\Tests\Support\FakeIdentityLinks;
use Nowo\SlackKitBundle\Tests\Support\RecordingApi;
use Nowo\SlackKitBundle\Tests\Support\RecordingDispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Audit R2-D1 (ported from PzDeltaR21PocTest, inverted): the 1.2 typed-claim, e-mail lookup and confirm flows write the
 * same identity store; a pair another account holds answers InUse (never a 500), and they share the member lock of
 * link-from-click.
 */
final class AccountLinkerConflictTest extends TestCase
{
    private MockClock $clock;
    private FakeIdentityLinks $ids;
    private RecordingDispatcher $events;
    private LockFactory $locks;

    protected function setUp(): void
    {
        $this->clock  = new MockClock('@' . Fake::NOW);
        $this->ids    = new FakeIdentityLinks();
        $this->events = new RecordingDispatcher();
        $this->locks  = new LockFactory(new InMemoryStore());
    }

    public function testAClaimRacingAClickLinkAnswersInUse(): void
    {
        $racing = $this->racing(fn () => $this->ids->saveLink($this->link('acc-click', true)));

        self::assertSame(LinkResult::InUse, $this->linker($racing)->claim('acc-typed', Fake::USER));
        self::assertSame('acc-click', $this->holder()?->accountId, 'the click link is kept');
        self::assertSame([], $this->events->of(SlackAccountClaimedEvent::class));
    }

    public function testAConfirmationRacingAClickLinkAnswersForbidden(): void
    {
        $this->ids->saveLink($this->link('acc-typed', false));
        $racing = $this->racing(function (): void {
            $this->ids->removeLinkIn('acc-typed', SlackTeamId::fromString(Fake::TEAM));
            $this->ids->saveLink($this->link('acc-click', true));
        }, true);

        $reply = $this->linker($racing)->confirm($this->click(['acc' => 'acc-typed', 'uid' => Fake::USER, 'c' => 1]));
        self::assertSame(403, $reply->status);
        self::assertSame('acc-click', $this->holder()?->accountId);
    }

    public function testAClaimWaitsForNoClickLinkOfTheSameMember(): void
    {
        $lock = $this->locks->createLock(ClickLinker::pairLockKey(SlackInstallationKey::DEFAULT_APP, SlackTeamId::fromString(Fake::TEAM), SlackUserId::fromString(Fake::USER)));
        self::assertTrue($lock->acquire());

        self::assertSame(LinkResult::Unavailable, $this->linker($this->ids)->claim('acc-typed', Fake::USER), 'a link-from-click of that member is running');
        $lock->release();
        self::assertSame(LinkResult::Claimed, $this->linker($this->ids)->claim('acc-typed', Fake::USER));
    }

    private function holder(): ?SlackLink
    {
        return $this->ids->findLinkBySlackUser(SlackTeamId::fromString(Fake::TEAM), SlackUserId::fromString(Fake::USER));
    }

    private function link(string $account, bool $verified): SlackLink
    {
        return new SlackLink($account, SlackTeamId::fromString(Fake::TEAM), SlackUserId::fromString(Fake::USER), new DateTimeImmutable('@1'), $verified ? new DateTimeImmutable('@1') : null);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function click(array $data): SlackInteraction
    {
        return new SlackInteraction(SlackInstallationKey::default(), AccountLinker::CONFIRM_ACTION, $data, SlackTeamId::fromString(Fake::TEAM), SlackUserId::fromString(Fake::USER), null, SlackChannelId::fromString(Fake::DM), '1.0', 'id');
    }

    private function linker(SlackIdentityLinksInterface $identities): AccountLinker
    {
        $limiter = new RateLimiterFactory(['id' => 'l', 'policy' => 'fixed_window', 'limit' => 50, 'interval' => '1 hour'], new InMemoryStorage());

        return new AccountLinker(
            $identities,
            Fake::store(),
            new RecordingApi(),
            new ButtonValueCodec(ButtonValueCodec::deriveKey(Fake::KERNEL_SECRET), [], $this->clock),
            $this->clock,
            ['claim_per_account' => $limiter, 'email_lookup_per_account' => $limiter, 'confirmation_per_account' => $limiter, 'confirmation_per_target' => $limiter],
            events: $this->events,
            locks: $this->locks,
        );
    }

    /**
     * The store of a host where a link-from-click lands between the 1.2 flow's read and its write (another process).
     *
     * @param bool $onAccountRead run on `findLinkByAccount()` (confirm) instead of `findLinkBySlackUser()` (claim)
     */
    private function racing(Closure $between, bool $onAccountRead = false): SlackIdentityLinksInterface
    {
        return new class($this->ids, $between, $onAccountRead) implements SlackIdentityLinksInterface {
            public function __construct(private readonly FakeIdentityLinks $inner, private ?Closure $between, private readonly bool $onAccountRead)
            {
            }

            public function findLinkBySlackUser(SlackTeamId $team, SlackUserId $user): ?SlackLink
            {
                $found = $this->inner->findLinkBySlackUser($team, $user);
                if (!$this->onAccountRead) {
                    $this->fire();
                }

                return $found;
            }

            public function findLinkByAccount(string $accountId): ?SlackLink
            {
                $found = $this->inner->findLinkByAccount($accountId);
                if ($this->onAccountRead) {
                    $this->fire();
                }

                return $found;
            }

            public function linksOf(string $accountId): array
            {
                return $this->inner->linksOf($accountId);
            }

            public function removeLinkIn(string $accountId, SlackTeamId $team): void
            {
                $this->inner->removeLinkIn($accountId, $team);
            }

            public function accountLabel(string $accountId): string
            {
                return 'x';
            }

            public function isAccountActive(string $accountId): bool
            {
                return true;
            }

            public function accountEmail(string $accountId): ?string
            {
                return null;
            }

            public function accountLocale(string $accountId): string
            {
                return 'en';
            }

            public function saveLink(SlackLink $link): void
            {
                $this->inner->saveLink($link);
            }

            public function removeLink(string $accountId): void
            {
                $this->inner->removeLink($accountId);
            }

            private function fire(): void
            {
                if ($this->between instanceof Closure) {
                    $between       = $this->between;
                    $this->between = null;
                    $between();
                }
            }
        };
    }
}
