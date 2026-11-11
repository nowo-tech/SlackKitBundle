<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Integration\Functional;

use Nowo\SlackKitBundle\Credentials\InMemoryInstallationStore;
use Nowo\SlackKitBundle\Credentials\InMemoryInstallationStoreBoundaryListener;
use Nowo\SlackKitBundle\Credentials\SlackInstallation;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Credentials\SlackInstallationStoreInterface;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Nowo\SlackKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\SlackKitBundle\Tests\Integration\Kernel\SlackKitTestKernel;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use Nowo\SlackKitBundle\Tests\Support\RecordingLogger;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Worker;

use function crc32;
use function gc_collect_cycles;
use function is_array;
use function json_decode;
use function memory_get_usage;

/**
 * REQ-RUNTIME-002 worker test for `credentials.store: memory`: one kernel kept alive, requests driven through
 * `http_kernel` directly, so the services resetter never runs between them. Two workspaces alternate; each request
 * installs the app for its own workspace and must never see the other workspace's installation (a workspace-less
 * lookup returns the app's first installation, bot token included).
 */
final class MemoryStoreWorkerTest extends KernelTestCase
{
    /** Requests per workspace (2 × 500 = 1,000 requests in one kernel). */
    private const ROUNDS = 500;

    public function testWorkspacesNeverSeeEachOthersInstallationWithoutTheResetter(): void
    {
        $kernel     = $this->memoryKernel();
        $httpKernel = $this->container($kernel)->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $httpKernel);
        $logger = $this->container($kernel)->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);
        $teams    = [Fake::TEAM, Fake::OTHER_TEAM];
        $baseline = 0;

        for ($i = 0; $i < 2 * self::ROUNDS; ++$i) {
            $request  = Request::create('/test/store-probe', 'GET', ['team' => $teams[$i % 2]]);
            $response = $httpKernel->handle($request);
            if ($httpKernel instanceof TerminableInterface) {
                $httpKernel->terminate($request, $response);
            }

            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            $body = json_decode((string) $response->getContent(), true);
            self::assertTrue(is_array($body));
            self::assertSame($teams[$i % 2], $body['saved']);
            self::assertNull($body['seen'], "Request {$i}: no installation of a previous request is visible.");
            // The test kernel's logger records every line (request matched, …): drop them, not the bundle's state.
            $logger->records = [];

            if ($i === 20) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }

        gc_collect_cycles();
        self::assertLessThan($baseline + 2 * 1024 * 1024, memory_get_usage(), 'Memory stays flat over 1,000 requests.');
    }

    public function testSubRequestKeepsTheMainRequestUnit(): void
    {
        $kernel   = $this->memoryKernel();
        $boundary = $this->container($kernel)->get(InMemoryInstallationStoreBoundaryListener::class);
        self::assertInstanceOf(InMemoryInstallationStoreBoundaryListener::class, $boundary);
        $this->install($kernel, Fake::TEAM);

        $boundary->onKernelRequest(new RequestEvent($kernel, Request::create('/fragment'), HttpKernelInterface::SUB_REQUEST));
        self::assertNotNull($this->lookup($kernel));

        $boundary->onKernelRequest(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST));
        self::assertNull($this->lookup($kernel));
    }

    public function testMessengerBoundariesClearAndNestedCommandsDoNot(): void
    {
        $kernel     = $this->memoryKernel();
        $dispatcher = $this->dispatcher($kernel);
        $envelope   = new Envelope(new stdClass());

        foreach ([true, false] as $handled) {
            $this->install($kernel, Fake::TEAM);
            $dispatcher->dispatch(new WorkerMessageReceivedEvent($envelope, 'async'));
            self::assertNull($this->lookup($kernel), 'A message starts clean.');

            $this->install($kernel, Fake::OTHER_TEAM);
            $this->runNestedCommand($dispatcher);
            self::assertNotNull($this->lookup($kernel), 'A nested command inside a message keeps the message unit.');

            $dispatcher->dispatch($handled
                ? new WorkerMessageHandledEvent($envelope, 'async')
                : new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('failed')));
            self::assertNull($this->lookup($kernel), 'The message unit ends clean.');
        }

        $dispatcher->dispatch(new WorkerMessageReceivedEvent($envelope, 'async'));
        $dispatcher->dispatch(new WorkerStoppedEvent($this->createStub(Worker::class)));
        $this->install($kernel, Fake::TEAM);
        $this->runNestedCommand($dispatcher);
        self::assertNull($this->lookup($kernel), 'After the worker stops, the outermost command clears again.');
    }

    public function testOnlyTheOutermostCommandClears(): void
    {
        $kernel                     = $this->memoryKernel();
        $dispatcher                 = $this->dispatcher($kernel);
        [$command, $input, $output] = $this->command();

        $dispatcher->dispatch(new ConsoleCommandEvent($command, $input, $output), ConsoleEvents::COMMAND);
        $this->install($kernel, Fake::TEAM);
        $this->runNestedCommand($dispatcher);
        self::assertNotNull($this->lookup($kernel), 'The nested command keeps the outer unit.');

        $dispatcher->dispatch(new ConsoleTerminateEvent($command, $input, $output, Command::SUCCESS), ConsoleEvents::TERMINATE);
        self::assertNull($this->lookup($kernel), 'The outermost command ends clean.');

        // An unbalanced terminate never drives the depth below zero.
        $dispatcher->dispatch(new ConsoleTerminateEvent($command, $input, $output, Command::SUCCESS), ConsoleEvents::TERMINATE);
        $this->install($kernel, Fake::TEAM);
        $this->runNestedCommand($dispatcher);
        self::assertNull($this->lookup($kernel));
    }

    public function testCommandRunInsideARequestKeepsTheRequestUnit(): void
    {
        $kernel     = $this->memoryKernel();
        $dispatcher = $this->dispatcher($kernel);
        $request    = Request::create('/test/store-probe');

        $dispatcher->dispatch(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST), KernelEvents::REQUEST);
        // A controller runs a command in-process after installing the app for this request's workspace.
        $this->install($kernel, Fake::TEAM);
        $this->runNestedCommand($dispatcher);
        self::assertNotNull($this->lookup($kernel), 'A command inside a request keeps the request unit.');

        $dispatcher->dispatch(new TerminateEvent($kernel, $request, new Response()), KernelEvents::TERMINATE);
        self::assertNull($this->lookup($kernel), 'The request unit ends clean.');

        $this->install($kernel, Fake::TEAM);
        $this->runNestedCommand($dispatcher);
        self::assertNull($this->lookup($kernel), 'Outside any request, the outermost command clears again.');
    }

    public function testBoundaryNeverBuildsTheStore(): void
    {
        $kernel    = $this->memoryKernel();
        $container = $this->container($kernel);
        $boundary  = $container->get(InMemoryInstallationStoreBoundaryListener::class);
        self::assertInstanceOf(InMemoryInstallationStoreBoundaryListener::class, $boundary);

        $boundary->clear();
        self::assertFalse($container->initialized(InMemoryInstallationStore::class));
    }

    public function testOtherStoresGetNoBoundaryListener(): void
    {
        self::assertFalse($this->container($this->bootKernel())->has(InMemoryInstallationStoreBoundaryListener::class));
    }

    /**
     * One kernel per test. Each gets its own `api.max_response_bytes`, so two kernels built in the same second never
     * share a dumped container class (the class of an earlier test points into a cache dir removed by tearDown()).
     */
    private function memoryKernel(): SlackKitTestKernel
    {
        return $this->bootKernel(['credentials' => ['store' => 'memory'], 'api' => ['max_response_bytes' => 1_048_576 + crc32($this->name()) % 4096]]);
    }

    private function store(SlackKitTestKernel $kernel): SlackInstallationStoreInterface
    {
        $store = $this->container($kernel)->get(SlackInstallationStoreInterface::class);
        self::assertInstanceOf(InMemoryInstallationStore::class, $store);

        return $store;
    }

    private function install(SlackKitTestKernel $kernel, string $team): void
    {
        $teamId = SlackTeamId::fromString($team);
        $this->store($kernel)->save(new SlackInstallation(new SlackInstallationKey(SlackInstallationKey::DEFAULT_APP, $teamId), SlackSecret::botToken(Fake::TOKEN), $teamId));
    }

    private function lookup(SlackKitTestKernel $kernel): ?SlackInstallation
    {
        return $this->store($kernel)->find(SlackInstallationKey::default());
    }

    private function runNestedCommand(EventDispatcherInterface $dispatcher): void
    {
        [$command, $input, $output] = $this->command();
        $dispatcher->dispatch(new ConsoleCommandEvent($command, $input, $output), ConsoleEvents::COMMAND);
        $dispatcher->dispatch(new ConsoleTerminateEvent($command, $input, $output, Command::SUCCESS), ConsoleEvents::TERMINATE);
    }

    /**
     * @return array{Command, ArrayInput, NullOutput}
     */
    private function command(): array
    {
        return [new Command('app:test'), new ArrayInput([]), new NullOutput()];
    }

    private function dispatcher(SlackKitTestKernel $kernel): EventDispatcherInterface
    {
        $dispatcher = $this->container($kernel)->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
