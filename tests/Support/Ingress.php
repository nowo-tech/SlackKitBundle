<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\SlackKitBundle\Credentials\InMemoryInstallationStore;
use Nowo\SlackKitBundle\Credentials\SlackAppRegistry;
use Nowo\SlackKitBundle\Ingress\EventsHandler;
use Nowo\SlackKitBundle\Ingress\IngressController;
use Nowo\SlackKitBundle\Ingress\ReplayGuard;
use Nowo\SlackKitBundle\Ingress\SignatureVerifier;
use Nowo\SlackKitBundle\Ingress\SigningSecretResolverInterface;
use Nowo\SlackKitBundle\Interaction\ActorResolver;
use Nowo\SlackKitBundle\Interaction\ButtonValueCodec;
use Nowo\SlackKitBundle\Interaction\InteractionRouter;
use Nowo\SlackKitBundle\Interaction\LegacyValuePolicy;
use Nowo\SlackKitBundle\Interaction\ReplyDispatcher;
use stdClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A hand-built ingress with fake credentials (no kernel).
 */
final class Ingress
{
    public MockClock $clock;
    public InMemoryInstallationStore $store;
    public RecordingApi $api;
    public RecordingDispatcher $events;
    public RecordingLogger $logger;
    public FakeIdentityStore $identities;
    public StubHandler $handler;
    public StubHandler $anonymousHandler;
    public ReplyDispatcher $replies;
    public ButtonValueCodec $codec;
    public InteractionRouter $router;
    public IngressController $controller;

    /**
     * @param array<string, array{app_id: string|null, signing_secret: string|null}> $apps
     * @param array<string, string> $legacyMap
     */
    public function __construct(
        array $apps = ['default' => ['app_id' => Fake::APP_ID, 'signing_secret' => null]],
        ?SigningSecretResolverInterface $resolver = null,
        ?MessageBusInterface $bus = null,
        ?string $legacyUntil = '@1796428800',
        array $legacyMap = ['resolve' => 'beacon.issue.resolve'],
        int $maxBody = 65_536,
        bool $withTeam = true,
        bool $resolverActors = false,
    ) {
        $this->clock            = new MockClock('@' . Fake::NOW);
        $this->store            = Fake::store($withTeam);
        $this->api              = new RecordingApi();
        $this->events           = new RecordingDispatcher();
        $this->logger           = new RecordingLogger();
        $this->identities       = new FakeIdentityStore();
        $this->handler          = new StubHandler();
        $this->anonymousHandler = new StubHandler();
        $this->replies          = new ReplyDispatcher($this->api, $this->logger);
        $this->codec            = new ButtonValueCodec(ButtonValueCodec::deriveKey(Fake::KERNEL_SECRET), [], $this->clock);
        $this->router           = new InteractionRouter(
            new ServiceLocator(['beacon.issue.resolve' => fn (): StubHandler => $this->handler, 'demo.ping' => fn (): StubHandler => $this->anonymousHandler, 'demo.broken' => static fn (): stdClass => new stdClass()]),
            ['beacon.issue.resolve' => true, 'demo.ping' => false, 'demo.broken' => false],
            $this->codec,
            new LegacyValuePolicy($legacyUntil !== null ? 'a' : null, $legacyMap, $legacyUntil !== null ? new DateTimeImmutable($legacyUntil) : null, $this->clock),
            new ActorResolver($this->identities),
            $this->replies,
            $this->events,
            null,
            'en',
            $this->logger,
        );
        $this->controller = new IngressController(
            new SignatureVerifier($this->clock),
            new ReplayGuard(new LockFactory(new InMemoryStore())),
            new SlackAppRegistry('default', $apps, $this->store),
            $this->store,
            $this->router,
            new EventsHandler($this->store, $this->events, $this->clock),
            $this->events,
            $resolver,
            $bus,
            'async',
            $maxBody,
            2,
            $this->logger,
            $resolverActors,
        );
    }
}
