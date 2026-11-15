<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Interaction;

use DateTimeImmutable;
use Nowo\SlackKitBundle\Api\SlackMessage;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Id\SlackChannelId;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Interaction\ActorResolver;
use Nowo\SlackKitBundle\Interaction\ButtonValueCodec;
use Nowo\SlackKitBundle\Interaction\InteractionRouter;
use Nowo\SlackKitBundle\Interaction\LegacyValuePolicy;
use Nowo\SlackKitBundle\Interaction\ReplyDispatcher;
use Nowo\SlackKitBundle\Interaction\SlackInteraction;
use Nowo\SlackKitBundle\Linking\ClickLinkerInterface;
use Nowo\SlackKitBundle\Linking\LinkResult;
use Nowo\SlackKitBundle\Linking\SlackLink;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use Nowo\SlackKitBundle\Tests\Support\FakeIdentityLinks;
use Nowo\SlackKitBundle\Tests\Support\RecordingApi;
use Nowo\SlackKitBundle\Tests\Support\StubHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Spec 237 T000b: per-workspace actors (one identity per workspace, team mismatch refused) and the "Link your
 * account" answer for unlinked clickers; anonymous only for handlers marked for it.
 */
final class PerTeamIdentityTest extends TestCase
{
    public function testTheActorIsTheIdentityOfTheClickWorkspace(): void
    {
        $identities = new FakeIdentityLinks();
        $identities->saveLink($this->link('ana', Fake::TEAM, Fake::USER));
        $identities->saveLink($this->link('ana', Fake::OTHER_TEAM, Fake::OTHER_USER));
        $identities->saveLink($this->link('luis', Fake::OTHER_TEAM, Fake::USER));
        $actors = new ActorResolver($identities);
        $teamA  = SlackTeamId::fromString(Fake::TEAM);
        $teamB  = SlackTeamId::fromString(Fake::OTHER_TEAM);

        self::assertSame('ana', $actors->resolve($teamA, SlackUserId::fromString(Fake::USER), new SlackInstallationKey('default', $teamA))?->accountId);
        self::assertSame('ana', $actors->resolve($teamB, SlackUserId::fromString(Fake::OTHER_USER), new SlackInstallationKey('default', $teamB))?->accountId);
        self::assertSame('luis', $actors->resolve($teamB, SlackUserId::fromString(Fake::USER), new SlackInstallationKey('default', $teamB))?->accountId, 'the same member id in another workspace is another identity');
        self::assertNull($actors->resolve($teamB, SlackUserId::fromString(Fake::USER), new SlackInstallationKey('default', $teamA)), 'a click whose workspace is not the installation one');
        self::assertSame('ana', $actors->resolve($teamA, SlackUserId::fromString(Fake::USER), SlackInstallationKey::default())?->accountId, 'a workspace-less installation key (1.2 hosts) keeps working');
    }

    public function testAnUnlinkedClickerGetsTheLinkOfferAndAnonymousHandlersStillRun(): void
    {
        $offer = new SlackMessage('Link your account', []);
        $links = new class($offer) implements ClickLinkerInterface {
            /** @var list<SlackInteraction> */
            public array $asked = [];

            public function __construct(private readonly ?SlackMessage $offer)
            {
            }

            public function offer(SlackInteraction $interaction): ?SlackMessage
            {
                $this->asked[] = $interaction;

                return $interaction->verifiedByOwnApp ? null : $this->offer;
            }

            public function preview(string $accountId, string $token): LinkResult
            {
                return LinkResult::LinkExpired;
            }

            public function confirm(string $accountId, string $token): LinkResult
            {
                return LinkResult::LinkExpired;
            }

            public function linksOf(string $accountId): array
            {
                return [];
            }

            public function unlinkIn(string $accountId, SlackTeamId $team): LinkResult
            {
                return LinkResult::Unlinked;
            }
        };
        $api     = new RecordingApi();
        $handler = new StubHandler();
        $anon    = new StubHandler();
        $replies = new ReplyDispatcher($api);
        $clock   = new MockClock('@' . Fake::NOW);
        $router  = new InteractionRouter(
            new ServiceLocator(['acme.assign' => static fn (): StubHandler => $handler, 'acme.ping' => static fn (): StubHandler => $anon]),
            ['acme.assign' => true, 'acme.ping' => false],
            new ButtonValueCodec(ButtonValueCodec::deriveKey(Fake::KERNEL_SECRET), [], $clock),
            new LegacyValuePolicy(null, [], null, $clock),
            new ActorResolver(new FakeIdentityLinks()),
            $replies,
            links: $links,
        );

        $response = $router->dispatch($this->interaction('acme.assign'));
        self::assertSame(403, $response->getStatusCode());
        self::assertSame(InteractionRouter::LINK_REQUIRED, $response->getContent());
        self::assertSame([], $handler->seen);
        $replies->flush();
        self::assertSame(['chat.postEphemeral'], $api->methods());
        self::assertSame($offer, $api->calls[0][1][2], 'the ephemeral answer is the link offer');

        // No offer (own-app click): the plain refusal.
        $router->dispatch($this->interaction('acme.assign', true));
        $replies->flush();
        $refusal = $api->calls[1][1][2];
        self::assertInstanceOf(SlackMessage::class, $refusal);
        self::assertNotSame($offer, $refusal);

        self::assertSame(200, $router->dispatch($this->interaction('acme.ping'))->getStatusCode(), 'an anonymous-safe handler runs');
        self::assertCount(1, $anon->seen);
        self::assertCount(2, $links->asked, 'anonymous-safe handlers never ask for a link');
    }

    private function interaction(string $action, bool $ownApp = false): SlackInteraction
    {
        $team = SlackTeamId::fromString(Fake::TEAM);

        return new SlackInteraction(new SlackInstallationKey('default', $team), $action, [], $team, SlackUserId::fromString(Fake::USER), null, SlackChannelId::fromString(Fake::CHANNEL), '1.0', 'id', verifiedByOwnApp: $ownApp);
    }

    private function link(string $account, string $team, string $user): SlackLink
    {
        return new SlackLink($account, SlackTeamId::fromString($team), SlackUserId::fromString($user), new DateTimeImmutable('@1'), new DateTimeImmutable('@1'));
    }
}
