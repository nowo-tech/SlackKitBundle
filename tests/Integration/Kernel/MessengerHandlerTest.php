<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Integration\Kernel;

use Nowo\SlackKitBundle\Ingress\EventsHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_keys;
use function array_values;
use function str_starts_with;

/**
 * Compiled-kernel check of the `#[AsMessageHandler]` registration (REQ-MSG-001): with `ingress.async: true` a real
 * kernel tags {@see EventsHandler} with `messenger.message_handler` exactly once; with it off no handler is registered.
 */
final class MessengerHandlerTest extends KernelTestCase
{
    public function testTheEventsHandlerIsTaggedExactlyOnceWithAsyncIngress(): void
    {
        $found = $this->handlersAfterCompilation(['ingress' => ['async' => true]]);

        self::assertSame(1, $found['count'], 'the events handler is registered exactly once in messenger.message_handler');
        self::assertSame(1, $found['total'], 'no other handler is registered by the bundle');
        self::assertCount(1, $found['tags'], 'one tag entry on the handler definition');
    }

    public function testNoHandlerWhenAsyncIsOff(): void
    {
        $found = $this->handlersAfterCompilation(['ingress' => ['async' => false]]);

        self::assertSame(0, $found['count']);
        self::assertSame(0, $found['total']);
    }

    public function testNoHandlerByDefault(): void
    {
        $found = $this->handlersAfterCompilation([]);

        self::assertSame(0, $found['count']);
        self::assertSame(0, $found['total']);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{count: int, total: int, tags: list<array<string, mixed>>}
     */
    private function handlersAfterCompilation(array $config): array
    {
        $result = ['count' => 0, 'total' => 0, 'tags' => []];
        $build  = static function (ContainerBuilder $container) use (&$result): void {
            $container->addCompilerPass(new class($result) implements CompilerPassInterface {
                /** @param array{count: int, total: int, tags: list<array<string, mixed>>} $result */
                public function __construct(private array &$result)
                {
                }

                public function process(ContainerBuilder $container): void
                {
                    foreach (array_keys($container->findTaggedServiceIds('messenger.message_handler')) as $id) {
                        if (str_starts_with($id, 'Nowo\\SlackKitBundle\\')) {
                            ++$this->result['total'];
                        }
                        if ($id === EventsHandler::class) {
                            ++$this->result['count'];
                            $this->result['tags'] = array_values($container->getDefinition($id)->getTag('messenger.message_handler'));
                        }
                    }
                }
            }, PassConfig::TYPE_BEFORE_REMOVING);
        };

        $this->bootKernel($config, 'test', ['messenger' => ['enabled' => true]], $build);

        return $result;
    }
}
