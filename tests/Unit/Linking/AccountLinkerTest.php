<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Linking;

use DateTimeImmutable;
use Nowo\SlackKitBundle\Credentials\InMemoryInstallationStore;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Event\SlackAccountClaimedEvent;
use Nowo\SlackKitBundle\Event\SlackAccountUnlinkedEvent;
use Nowo\SlackKitBundle\Event\SlackAccountVerifiedEvent;
use Nowo\SlackKitBundle\Event\SlackConfirmationRateLimitedEvent;
use Nowo\SlackKitBundle\Event\SlackLinkReleasedEvent;
use Nowo\SlackKitBundle\Id\SlackChannelId;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Interaction\ButtonValueCodec;
use Nowo\SlackKitBundle\Interaction\LegacyValuePolicy;
use Nowo\SlackKitBundle\Interaction\SlackInteraction;
use Nowo\SlackKitBundle\Linking\AccountLinker;
use Nowo\SlackKitBundle\Linking\ConfirmLinkHandler;
use Nowo\SlackKitBundle\Linking\LinkResult;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use Nowo\SlackKitBundle\Tests\Support\FakeIdentityStore;
use Nowo\SlackKitBundle\Tests\Support\RecordingApi;
use Nowo\SlackKitBundle\Tests\Support\RecordingDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Contract §8: claims, proven claims, release, confirmation and its match matrix, limiters, unlink.
 */
final class AccountLinkerTest extends TestCase
{
    private MockClock $clock;
    private FakeIdentityStore $identities;
    private RecordingApi $api;
    private RecordingDispatcher $events;
    private ButtonValueCodec $codec;
    private InMemoryInstallationStore $store;

    protected function setUp(): void
    {
        $this->clock      = new MockClock('@' . Fake::NOW);
        $this->identities = new FakeIdentityStore();
        $this->api        = new RecordingApi();
        $this->events     = new RecordingDispatcher();
        $this->codec      = new ButtonValueCodec(ButtonValueCodec::deriveKey(Fake::KERNEL_SECRET), [], $this->clock);
        $this->store      = Fake::store();
    }

    public function testTypedClaimThenConfirmationThenClick(): void
    {
        $linker = $this->linker();
        self::assertSame(LinkResult::Claimed, $linker->claim('acc-1', ' ' . Fake::USER));
        self::assertSame(LinkResult::Claimed, $linker->claim('acc-1', Fake::USER), 'same claim again');
        self::assertFalse($linker->linkOf('acc-1')?->isVerified());
        self::assertSame('typed', $this->events->of(SlackAccountClaimedEvent::class)[0]->source);

        self::assertSame(LinkResult::ConfirmationSent, $linker->sendConfirmation('acc-1'));
        self::assertSame(['conversations.open', 'chat.postMessage'], $this->api->methods());
        $message = $this->api->calls[1][1][1];
        $button  = $message->blocks[1]['elements'][0];
        self::assertSame(AccountLinker::CONFIRM_ACTION, $button['action_id']);

        $click = $this->click($button['value']);
        $reply = (new ConfirmLinkHandler($linker))->handle($click);
        self::assertSame(200, $reply->status);
        self::assertNotNull($reply->replacement);
        self::assertTrue($linker->linkOf('acc-1')?->isVerified());
        self::assertCount(1, $this->events->of(SlackAccountVerifiedEvent::class));

        $linker->confirm($click);
        self::assertCount(1, $this->events->of(SlackAccountVerifiedEvent::class), 'idempotent');
        self::assertSame(LinkResult::AlreadyVerified, $linker->sendConfirmation('acc-1'));
        self::assertSame(LinkResult::AlreadyVerified, $linker->claim('acc-1', Fake::USER));
    }

    public function testConfirmationMatchMatrix(): void
    {
        $linker = $this->linker();
        $linker->claim('acc-1', Fake::USER);
        $claimedAt = $linker->linkOf('acc-1')?->claimedAt->getTimestamp();
        $data      = ['acc' => 'acc-1', 'c' => $claimedAt, 'uid' => Fake::USER];

        $cases = [
            'other clicker'  => $this->interaction($data, Fake::OTHER_USER),
            'other team'     => $this->interaction($data, Fake::USER, Fake::OTHER_TEAM),
            'other account'  => $this->interaction(['acc' => 'acc-2'] + $data),
            'other value id' => $this->interaction(['uid' => Fake::OTHER_USER] + $data),
            'old claim'      => $this->interaction(['c' => 1] + $data),
            'no account'     => $this->interaction(['c' => 1]),
            'no clicker'     => $this->interaction($data, null),
        ];
        foreach ($cases as $name => $interaction) {
            $reply = $linker->confirm($interaction);
            self::assertSame(403, $reply->status, $name);
            self::assertNotNull($reply->ephemeral, $name);
        }
        $this->identities->active['acc-1'] = false;
        self::assertSame(403, $linker->confirm($this->interaction($data))->status, 'inactive');
        self::assertFalse($linker->linkOf('acc-1')?->isVerified());
    }

    public function testConfirmationsWithoutClaimTimeOnlyInsideTheLegacyWindow(): void
    {
        $closed = $this->linker();
        $closed->claim('acc-1', Fake::USER);
        self::assertSame(403, $closed->confirm($this->interaction(['acc' => 'acc-1', 'uid' => Fake::USER]))->status, 'signed without c, window closed');
        self::assertSame(403, $closed->confirm($this->interaction(['u' => 'acc-1', 's' => Fake::USER], legacy: true))->status, 'legacy, window closed');

        $window = new LegacyValuePolicy('a', ['link_confirm' => AccountLinker::CONFIRM_ACTION], new DateTimeImmutable('@' . (Fake::NOW + 60)), $this->clock);
        $open   = $this->linker(legacy: $window);
        self::assertSame(200, $open->confirm($this->interaction(['acc' => 'acc-1', 'uid' => Fake::USER]))->status, 'pre-adoption Beacon button (acc, uid, no c)');
        $this->identities->removeLink('acc-1');
        $open->claim('acc-1', Fake::USER);
        self::assertSame(200, $open->confirm($this->interaction(['u' => 'acc-1', 's' => Fake::USER], legacy: true))->status, 'legacy unsigned button');
        $this->clock->sleep(60);
        self::assertSame(403, $open->confirm($this->interaction(['acc' => 'acc-1', 'uid' => Fake::USER]))->status, 'c required after the cutoff');
    }

    public function testClaimTimeWindowDoesNotNeedTheLegacyValueKey(): void
    {
        $window = new LegacyValuePolicy(null, [], new DateTimeImmutable('@' . (Fake::NOW + 60)), $this->clock);
        self::assertFalse($window->isActive(), 'no unsigned legacy values without legacy_value_key');
        self::assertTrue($window->isWindowOpen());
        $linker = $this->linker(legacy: $window);
        $linker->claim('acc-1', Fake::USER);
        self::assertSame(200, $linker->confirm($this->interaction(['acc' => 'acc-1', 'n' => 'AAAAAAAAAAAAAAAAAAAAAA', 'uid' => Fake::USER]))->status, 'nonce n is optional and ignored');
    }

    public function testSquattingRules(): void
    {
        $linker = $this->linker();
        $linker->claim('squatter', Fake::USER);
        self::assertSame(LinkResult::InUse, $linker->claim('owner', Fake::USER), 'a fresh unverified claim holds against a typed claim');

        $this->api->lookup                 = SlackUserId::fromString(Fake::USER);
        $this->identities->emails['owner'] = 'owner@example.test';
        self::assertSame(LinkResult::Found, $linker->findByEmail('owner'), 'a proven claim takes it at once');
        self::assertNull($linker->linkOf('squatter'));
        $released = $this->events->of(SlackLinkReleasedEvent::class)[0];
        self::assertSame(['squatter', 'owner', 'claimed_by_email'], [$released->holderAccountId, $released->claimantAccountId, $released->reason]);
        self::assertSame('email_lookup', $linker->linkOf('owner')?->source);
        self::assertFalse($linker->linkOf('owner')?->isVerified(), 'still needs the click');

        $linker->claim('squatter2', Fake::OTHER_USER);
        $this->clock->sleep(86_400);
        self::assertSame(LinkResult::Claimed, $linker->claim('owner2', Fake::OTHER_USER), 'stale after 24 h');
        self::assertSame('expired', $this->events->of(SlackLinkReleasedEvent::class)[1]->reason);

        $this->identities->saveLink($this->identities->links['owner2']->verified(new DateTimeImmutable()));
        $this->api->lookup                    = SlackUserId::fromString(Fake::OTHER_USER);
        $this->identities->emails['intruder'] = 'x@example.test';
        self::assertSame(LinkResult::InUse, $linker->findByEmail('intruder'), 'verified links never yield');
        self::assertSame(LinkResult::InUse, $linker->claim('intruder', Fake::OTHER_USER));
    }

    public function testFindByEmailOutcomes(): void
    {
        $linker = $this->linker();
        self::assertTrue($linker->canFindByEmail());
        self::assertSame(LinkResult::Unavailable, $linker->findByEmail('no-email'));
        $this->identities->emails['acc-1'] = 'acc@example.test';
        self::assertSame(LinkResult::NotFound, $linker->findByEmail('acc-1'));
        $this->api->fail = 'ratelimited';
        self::assertSame(LinkResult::Unavailable, $linker->findByEmail('acc-1'));
        $this->api->fail   = null;
        $this->api->lookup = SlackUserId::fromString(Fake::USER);
        self::assertSame(LinkResult::Found, $linker->findByEmail('acc-1'));
        self::assertSame(LinkResult::Found, $linker->findByEmail('acc-1'), 'already its own unverified claim');

        self::assertFalse($this->linker(emailLookup: false)->canFindByEmail());
        self::assertSame(LinkResult::Unavailable, $this->linker(emailLookup: false)->findByEmail('acc-1'));
    }

    public function testLimitersRefuseWithoutSending(): void
    {
        $linker = $this->linker(limit: 1);
        $linker->claim('acc-1', Fake::USER);
        self::assertSame(LinkResult::RateLimited, $linker->claim('acc-1', Fake::USER));
        self::assertSame(LinkResult::ConfirmationSent, $linker->sendConfirmation('acc-1'));
        self::assertSame(LinkResult::RateLimited, $linker->sendConfirmation('acc-1'));
        self::assertCount(2, $this->api->calls);
        $this->identities->emails['acc-1'] = 'acc@example.test';
        $linker->findByEmail('acc-1');
        self::assertSame(LinkResult::RateLimited, $linker->findByEmail('acc-1'));

        $limiters = array_map(static fn (SlackConfirmationRateLimitedEvent $e): string => $e->limiter, $this->events->of(SlackConfirmationRateLimitedEvent::class));
        self::assertSame(['claim_per_account', 'confirmation_per_account', 'email_lookup_per_account'], $limiters);
    }

    public function testPerTargetLimiter(): void
    {
        $linker = $this->linker(limit: 1, perAccount: 5);
        $linker->claim('acc-1', Fake::USER);
        self::assertSame(LinkResult::ConfirmationSent, $linker->sendConfirmation('acc-1'));
        self::assertSame(LinkResult::RateLimited, $linker->sendConfirmation('acc-1'));
        self::assertSame('confirmation_per_target', $this->events->of(SlackConfirmationRateLimitedEvent::class)[0]->limiter);
    }

    public function testUnavailableStates(): void
    {
        self::assertSame(LinkResult::Invalid, $this->linker()->claim('acc-1', 'not-an-id'));
        self::assertSame(LinkResult::Unavailable, $this->linker()->sendConfirmation('nobody'));

        $this->store = Fake::store(false);
        self::assertSame(LinkResult::Unavailable, $this->linker()->claim('acc-1', Fake::USER), 'no workspace id');

        $this->store = new InMemoryInstallationStore();
        self::assertFalse($this->linker()->canFindByEmail());

        $this->store = Fake::store();
        $linker      = $this->linker();
        $linker->claim('acc-1', Fake::USER);
        $this->identities->active['acc-1'] = false;
        self::assertSame(LinkResult::Unavailable, $linker->sendConfirmation('acc-1'));
        $this->identities->active['acc-1'] = true;
        $this->store->markRevoked(SlackInstallationKey::default(), new DateTimeImmutable());
        self::assertSame(LinkResult::Unavailable, $linker->sendConfirmation('acc-1'));
    }

    public function testConfirmationFailureAndUnlink(): void
    {
        $linker = $this->linker();
        $linker->claim('acc-1', Fake::USER);
        $this->api->fail = 'user_not_found';
        self::assertSame(LinkResult::ConfirmationFailed, $linker->sendConfirmation('acc-1'));

        self::assertSame(LinkResult::Unlinked, $linker->unlink('acc-1'));
        self::assertNull($linker->linkOf('acc-1'));
        self::assertSame(Fake::USER, $this->events->of(SlackAccountUnlinkedEvent::class)[0]->slackUserId);
        $linker->unlink('acc-1');
        self::assertNull($this->events->of(SlackAccountUnlinkedEvent::class)[1]->slackUserId);
    }

    public function testResultsMapToFlashes(): void
    {
        self::assertSame('link.result.in_use', LinkResult::InUse->translationKey());
        self::assertTrue(LinkResult::ConfirmationSent->isSuccess());
        self::assertFalse(LinkResult::RateLimited->isSuccess());
    }

    private function linker(bool $emailLookup = true, int $limit = 10, ?int $perAccount = null, ?LegacyValuePolicy $legacy = null): AccountLinker
    {
        $factory = static fn (string $id, int $l): RateLimiterFactory => new RateLimiterFactory(['id' => $id, 'policy' => 'fixed_window', 'limit' => $l, 'interval' => '1 hour'], new CacheStorage(new ArrayAdapter()));

        return new AccountLinker(
            $this->identities,
            $this->store,
            $this->api,
            $this->codec,
            $this->clock,
            [
                'claim_per_account'        => $factory('c', $limit),
                'email_lookup_per_account' => $factory('e', $limit),
                'confirmation_per_account' => $factory('p', $perAccount ?? $limit),
                'confirmation_per_target'  => $factory('t', $limit),
            ],
            86_400,
            86_400,
            $emailLookup,
            SlackInstallationKey::default(),
            $this->events,
            null,
            'en',
            new NullLogger(),
            $legacy,
        );
    }

    private function click(string $value): SlackInteraction
    {
        return $this->interaction($this->codec->verify(AccountLinker::CONFIRM_ACTION, $value, Fake::TEAM)->data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function interaction(array $data, ?string $clicker = Fake::USER, string $team = Fake::TEAM, bool $legacy = false): SlackInteraction
    {
        return new SlackInteraction(SlackInstallationKey::default(), AccountLinker::CONFIRM_ACTION, $data, SlackTeamId::fromString($team), $clicker !== null ? SlackUserId::fromString($clicker) : null, null, SlackChannelId::fromString(Fake::DM), '1.0', 'id', $legacy);
    }
}
