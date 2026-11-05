<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Ingress;

use LogicException;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Event\SlackEventReceivedEvent;
use Nowo\SlackKitBundle\Event\SlackInstallationRevokedEvent;
use Nowo\SlackKitBundle\Ingress\EventsHandler;
use Nowo\SlackKitBundle\Ingress\HandleSlackEvent;
use Nowo\SlackKitBundle\Ingress\ReplayGuard;
use Nowo\SlackKitBundle\Ingress\SignatureVerifier;
use Nowo\SlackKitBundle\Ingress\UnverifiedPayloadPeek;
use Nowo\SlackKitBundle\Interaction\ButtonValueCodec;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use Nowo\SlackKitBundle\Tests\Support\RecordingDispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\CombinedStore;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Lock\Store\PdoStore;
use Symfony\Component\Lock\Store\PostgreSqlStore;
use Symfony\Component\Lock\Strategy\UnanimousStrategy;

final class IngressPiecesTest extends TestCase
{
    public function testSignatureVerifier(): void
    {
        $clock    = new MockClock('@' . Fake::NOW);
        $verifier = new SignatureVerifier($clock);
        $secret   = SlackSecret::signingSecret(Fake::SIGNING_SECRET);
        $body     = 'payload=%7B%7D';
        $sig      = SignatureVerifier::sign($secret, (string) Fake::NOW, $body);

        self::assertMatchesRegularExpression('/^v0=[a-f0-9]{64}$/', $sig);
        self::assertSame('v0=' . hash_hmac('sha256', 'v0:' . Fake::NOW . ':' . $body, Fake::SIGNING_SECRET), $sig);
        self::assertTrue($verifier->headersAreWellFormed((string) Fake::NOW, $sig));
        self::assertFalse($verifier->headersAreWellFormed('12a', $sig));
        self::assertFalse($verifier->headersAreWellFormed('1234567890123', $sig));
        self::assertFalse($verifier->headersAreWellFormed((string) Fake::NOW, 'v1=' . substr($sig, 3)));
        self::assertFalse($verifier->headersAreWellFormed((string) Fake::NOW, strtoupper($sig)));
        self::assertTrue($verifier->matches($secret, (string) Fake::NOW, $sig, $body));
        self::assertFalse($verifier->matches($secret, (string) Fake::NOW, $sig, $body . ' '));
        self::assertFalse($verifier->matches(SlackSecret::signingSecret(Fake::OTHER_SECRET), (string) Fake::NOW, $sig, $body));
        self::assertTrue($verifier->isWithinWindow((string) (Fake::NOW - 299)));
        self::assertTrue($verifier->isWithinWindow((string) (Fake::NOW + 299)));
        self::assertFalse($verifier->isWithinWindow((string) (Fake::NOW - 301)));
        self::assertFalse($verifier->isWithinWindow((string) (Fake::NOW + 301)));
        self::assertFalse((new SignatureVerifier($clock, 60))->isWithinWindow((string) (Fake::NOW - 61)));
    }

    public function testReplayGuard(): void
    {
        $guard = new ReplayGuard(new LockFactory(new InMemoryStore()), 300);
        self::assertTrue($guard->claimSignature('v0=1'));
        self::assertFalse($guard->claimSignature('v0=1'), 'same signature, whatever the app (FS-I2)');
        $event = $guard->claimEvent('Ev00000001');
        self::assertNotNull($event);
        self::assertNull($guard->claimEvent('Ev00000001'));
        $event->release();
        self::assertNotNull($guard->claimEvent('Ev00000001'), 'released after a failed handler (FS-I5)');
    }

    public function testReplayGuardRefusesStoresOffTheAllowlistWhenASharedOneIsRequired(): void
    {
        $store = new InMemoryStore();
        self::assertFalse(ReplayGuard::isSharedStore($store));
        self::assertFalse(ReplayGuard::isSharedStore(null));
        self::assertTrue(ReplayGuard::isSharedStore(new PdoStore('sqlite::memory:')));
        $pdo = new PdoStore('sqlite::memory:');
        self::assertTrue(ReplayGuard::isSharedStore(new CombinedStore([$pdo, $pdo], new UnanimousStrategy())));
        self::assertFalse(ReplayGuard::isSharedStore(new CombinedStore([$pdo, $store], new UnanimousStrategy())), 'a local member');
        self::assertFalse(ReplayGuard::isSharedStore(new CombinedStore([], new UnanimousStrategy())));
        self::assertFalse(ReplayGuard::isSharedStore(new PostgreSqlStore('pgsql:host=db.example.test')), 'session-scoped advisory locks (FSD-L1)');

        foreach (['redis://r.example.test', 'rediss://r', 'memcached://m', 'mongodb://m/db?collection=l', 'pgsql:host=db', 'mysql://u@db/x', 'postgresql://u@db/x', 'sqlite:/var/x.db', 'dynamodb://default/lock'] as $dsn) {
            self::assertTrue(ReplayGuard::isSharedDsn($dsn), $dsn);
        }
        foreach (['flock', 'flock:///tmp', 'semaphore', 'in-memory', 'null', 'pgsql+advisory:host=db', 'pgsql+advisory://u@db/x', 'postgresql+advisory://u@db/x', 'zookeeper://z:2181', 'custom://x'] as $dsn) {
            self::assertFalse(ReplayGuard::isSharedDsn($dsn), $dsn);
        }

        self::assertTrue((new ReplayGuard(new LockFactory($store), 300, $store, false))->claimSignature('v0=2'));
        self::assertTrue((new ReplayGuard(new LockFactory($store), 300, null, true))->claimSignature('v0=3'), 'unknown store: no runtime refusal');

        $this->expectException(LogicException::class);
        (new ReplayGuard(new LockFactory($store), 300, $store, true))->claimSignature('v0=4');
    }

    public function testUnverifiedPeek(): void
    {
        $legacy = new UnverifiedPayloadPeek(Fake::form(Fake::blockActions('x', '{"a":"resolve","d":"00000000-0000-4000-8000-000000000001"}')));
        self::assertSame('00000000-0000-4000-8000-000000000001', $legacy->actionDataString('d', '/^[0-9a-f-]{36}$/'));
        self::assertNull($legacy->actionDataString('d', '/^x$/'));
        self::assertNull($legacy->actionDataString('missing', '/.*/'));

        $codec  = new ButtonValueCodec(ButtonValueCodec::deriveKey(Fake::KERNEL_SECRET), [], new MockClock('@' . Fake::NOW));
        $signed = new UnverifiedPayloadPeek(Fake::form(Fake::blockActions('a', $codec->sign('a', ['d' => 'dest-1']))));
        self::assertSame('dest-1', $signed->actionDataString('d', '/^dest-\d$/'));

        foreach (['', 'payload=%7B', 'payload=1', Fake::form(['actions' => [['value' => 1]]]), Fake::form(Fake::blockActions('a', 'nsk1.@@@')), Fake::form(Fake::blockActions('a', '{bad'))] as $body) {
            self::assertNull((new UnverifiedPayloadPeek($body))->actionDataString('d', '/.*/'));
        }
    }

    public function testEventsHandlerRevokesAndDispatches(): void
    {
        $store   = Fake::store();
        $events  = new RecordingDispatcher();
        $handler = new EventsHandler($store, $events, new MockClock('@' . Fake::NOW));

        $handler(new HandleSlackEvent('default', ['team_id' => Fake::TEAM, 'event_id' => 'Ev1', 'event_time' => 5, 'event' => ['type' => 'message']]));
        self::assertCount(1, $events->of(SlackEventReceivedEvent::class));
        self::assertSame('message', $events->of(SlackEventReceivedEvent::class)[0]->type);
        self::assertSame(5, $events->of(SlackEventReceivedEvent::class)[0]->eventTime);

        $handler->handle('default', ['team_id' => Fake::TEAM, 'event' => ['type' => 'tokens_revoked', 'tokens' => ['oauth' => ['U1']]]]);
        self::assertCount(0, $events->of(SlackInstallationRevokedEvent::class), 'user tokens only');
        $handler->handle('default', ['team_id' => Fake::TEAM, 'event' => ['type' => 'tokens_revoked', 'tokens' => ['bot' => ['B1']]]]);
        self::assertCount(1, $events->of(SlackInstallationRevokedEvent::class));
        self::assertTrue($store->find(SlackInstallationKey::default())?->isRevoked());

        $handler->handle('default', ['event' => 'x']);
        self::assertSame('', $events->of(SlackEventReceivedEvent::class)[3]->type);
        self::assertSame('', $events->of(SlackEventReceivedEvent::class)[3]->eventId);
    }
}
