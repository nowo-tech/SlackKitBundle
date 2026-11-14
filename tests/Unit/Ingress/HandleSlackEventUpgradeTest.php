<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Ingress;

use Nowo\SlackKitBundle\Event\SlackEventReceivedEvent;
use Nowo\SlackKitBundle\Ingress\EventsHandler;
use Nowo\SlackKitBundle\Ingress\HandleSlackEvent;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use Nowo\SlackKitBundle\Tests\Support\RecordingDispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

use function addslashes;
use function serialize;
use function str_replace;
use function stripslashes;
use function unserialize;

/**
 * Audit PZ-L9: a `HandleSlackEvent` queued by 1.2 (two properties) is still handled by 1.3 after an upgrade, through
 * PHP `unserialize()` and through Messenger's default `PhpSerializer` (the bundle ships no serializer of its own).
 */
final class HandleSlackEventUpgradeTest extends TestCase
{
    /** `serialize()` of the 1.2.1 class (generated with the v1.2.1 source). */
    private const V12 = 'O:44:"Nowo\SlackKitBundle\Ingress\HandleSlackEvent":2:{s:3:"app";s:7:"default";s:8:"envelope";a:3:{s:4:"type";s:14:"event_callback";s:7:"team_id";s:9:"T00000001";s:5:"event";a:1:{s:4:"type";s:11:"app_mention";}}}';

    public function testAMessageQueuedBy12IsHandled(): void
    {
        $message = unserialize(self::V12);
        self::assertInstanceOf(HandleSlackEvent::class, $message);
        self::assertSame('default', $message->app);
        self::assertFalse($message->verifiedByOwnApp, 'a 1.2 message was never verified by an own app');

        $events = new RecordingDispatcher();
        (new EventsHandler(Fake::store(), $events, new MockClock('@' . Fake::NOW)))($message);
        $received = $events->of(SlackEventReceivedEvent::class);
        self::assertCount(1, $received);
        self::assertSame('app_mention', $received[0]->type);
        self::assertFalse($received[0]->verifiedByOwnApp);
    }

    public function testAMessengerEnvelopeQueuedBy12IsDecoded(): void
    {
        $serializer = new PhpSerializer();
        $current    = new HandleSlackEvent('default', ['type' => 'event_callback', 'team_id' => 'T00000001', 'event' => ['type' => 'app_mention']]);
        $body       = stripslashes($serializer->encode(new Envelope($current))['body']);
        $old        = str_replace(serialize($current), self::V12, $body);
        self::assertNotSame($body, $old, 'the envelope now holds the 1.2 message');

        $message = $serializer->decode(['body' => addslashes($old)])->getMessage();
        self::assertInstanceOf(HandleSlackEvent::class, $message);
        self::assertFalse($message->verifiedByOwnApp);
        self::assertSame('T00000001', $message->envelope['team_id']);
    }

    public function testA13MessageKeepsItsFlagThroughSerialisation(): void
    {
        $message = unserialize(serialize(new HandleSlackEvent('default', ['team_id' => 'T00000001'], true)));
        self::assertInstanceOf(HandleSlackEvent::class, $message);
        self::assertTrue($message->verifiedByOwnApp);
        self::assertSame(['team_id' => 'T00000001'], $message->envelope);
    }
}
