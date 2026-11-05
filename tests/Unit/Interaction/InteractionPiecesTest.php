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
use Nowo\SlackKitBundle\Interaction\AsSlackActionHandler;
use Nowo\SlackKitBundle\Interaction\LegacyValuePolicy;
use Nowo\SlackKitBundle\Interaction\ReplyDispatcher;
use Nowo\SlackKitBundle\Interaction\SlackInteraction;
use Nowo\SlackKitBundle\Interaction\SlackInteractionReply;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use Nowo\SlackKitBundle\Tests\Support\RecordingApi;
use Nowo\SlackKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class InteractionPiecesTest extends TestCase
{
    public function testReplyFactories(): void
    {
        self::assertSame([200, ''], [SlackInteractionReply::done()->status, SlackInteractionReply::done()->body]);
        self::assertSame(404, SlackInteractionReply::gone()->status);
        self::assertSame(403, SlackInteractionReply::noAccess()->status);
        self::assertSame('Forbidden', SlackInteractionReply::forbidden()->body);
        $reply = SlackInteractionReply::gone()->withEphemeral(SlackMessage::plain('e'))->replaceOriginal(SlackMessage::plain('r'));
        self::assertNotNull($reply->ephemeral);
        self::assertNotNull($reply->replacement);
        $deleted = $reply->deleteOriginal();
        self::assertTrue($deleted->deleteOriginal);
        self::assertNull($deleted->replacement);
        self::assertFalse($reply->replaceOriginal(SlackMessage::plain('x'))->deleteOriginal);
        $attribute = new AsSlackActionHandler('a.b', false);
        self::assertSame(['a.b', false], [$attribute->action, $attribute->requiresActor]);
    }

    public function testReplyDispatcher(): void
    {
        $api     = new RecordingApi();
        $logger  = new RecordingLogger();
        $replies = new ReplyDispatcher($api, $logger);

        $replies->schedule($this->interaction(), SlackInteractionReply::done());
        self::assertSame(0, $replies->count(), 'nothing to call');
        $replies->schedule($this->interaction(resolver: true), SlackInteractionReply::done()->withEphemeral(SlackMessage::plain('x')));
        self::assertSame(0, $replies->count(), 'foreign workspace');

        $replies->schedule($this->interaction(), SlackInteractionReply::done()->withEphemeral(SlackMessage::plain('x'))->deleteOriginal());
        $replies->schedule($this->interaction(channel: null), SlackInteractionReply::done()->deleteOriginal());
        $replies->schedule($this->interaction(ts: null, user: null), SlackInteractionReply::done()->withEphemeral(SlackMessage::plain('x')));
        self::assertSame(3, $replies->count());
        $replies->flush();
        self::assertSame(['chat.postEphemeral', 'chat.delete'], $api->methods());
        self::assertSame(0, $replies->count());

        $api->fail = 'channel_not_found';
        $replies->schedule($this->interaction(), SlackInteractionReply::done()->replaceOriginal(SlackMessage::plain('x')));
        $replies->flush();
        self::assertSame('channel_not_found', $logger->records[0]['context']['error']);

        $replies->schedule($this->interaction(), SlackInteractionReply::done()->deleteOriginal());
        $replies->reset();
        self::assertSame(0, $replies->count());
    }

    public function testLegacyPolicy(): void
    {
        $clock  = new MockClock('@' . Fake::NOW);
        $policy = new LegacyValuePolicy('a', ['resolve' => 'beacon.issue.resolve'], new DateTimeImmutable('@' . (Fake::NOW + 10)), $clock);
        self::assertTrue($policy->isActive());
        self::assertSame(['beacon.issue.resolve', ['a' => 'resolve', 'i' => 'x']], $policy->resolve('{"a":"resolve","i":"x"}'));
        self::assertNull($policy->resolve('{"a":"assign"}'));
        self::assertNull($policy->resolve('{"a":1}'));
        self::assertNull($policy->resolve('"resolve"'));
        self::assertNull($policy->resolve('{bad'));
        self::assertNull($policy->resolve('nsk1.{"a":"resolve"}'));
        self::assertNull($policy->resolve('{"a":"resolve","x":"' . str_repeat('x', 2000) . '"}'));
        $clock->sleep(10);
        self::assertFalse($policy->isActive(), 'exclusive cutoff');
        self::assertNull($policy->resolve('{"a":"resolve"}'));
        self::assertFalse((new LegacyValuePolicy(null, [], null, $clock))->isActive());
    }

    public function testActorResolverWithoutStore(): void
    {
        self::assertNull((new ActorResolver())->resolve(SlackTeamId::fromString(Fake::TEAM), SlackUserId::fromString(Fake::USER)));
    }

    private function interaction(bool $resolver = false, ?string $channel = Fake::DM, ?string $ts = '1.0', ?string $user = Fake::USER): SlackInteraction
    {
        return new SlackInteraction(
            SlackInstallationKey::default(),
            'a.b',
            [],
            SlackTeamId::fromString(Fake::TEAM),
            $user !== null ? SlackUserId::fromString($user) : null,
            null,
            $channel !== null ? SlackChannelId::fromString($channel) : null,
            $ts,
            'id',
            false,
            $resolver,
        );
    }
}
