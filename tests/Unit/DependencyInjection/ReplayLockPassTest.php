<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\SlackKitBundle\DependencyInjection\Compiler\ReplayLockPass;
use Nowo\SlackKitBundle\Ingress\ReplayGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Lock\Store\StoreFactory;

use function is_string;

/**
 * Audit FS-L1: a local replay lock store fails the build outside debug and test.
 */
final class ReplayLockPassTest extends TestCase
{
    /**
     * @return iterable<string, array{0: Definition|string, 1: bool, 2: string, 3: bool}>
     */
    public static function stores(): iterable
    {
        yield 'flock in prod' => ['flock', false, 'prod', true];
        yield 'semaphore in prod' => ['semaphore', false, 'prod', true];
        yield 'in-memory in prod' => ['in-memory', false, 'prod', true];
        yield 'flock path in prod' => ['flock:///var/lock', false, 'prod', true];
        yield 'redis in prod' => ['redis://redis:6379', false, 'prod', false];
        yield 'flock in debug' => ['flock', true, 'prod', false];
        yield 'flock in test' => ['flock', false, 'test', false];
        yield 'in-memory class in prod' => [new Definition(InMemoryStore::class), false, 'prod', true];
        yield 'other class in prod' => [new Definition(stdClass::class), false, 'prod', false];
    }

    #[DataProvider('stores')]
    public function testLocalStores(string|Definition $store, bool $debug, string $env, bool $refused): void
    {
        $container = $this->container($store, $debug, $env);
        if ($refused) {
            $this->expectException(LogicException::class);
        }
        (new ReplayLockPass())->process($container);
        self::assertSame('replay.store', (string) $container->getDefinition(ReplayGuard::class)->getArgument(2));
        self::assertSame(!$debug && $env !== 'test', $container->getDefinition(ReplayGuard::class)->getArgument(3), 'runtime check outside debug and test');
    }

    public function testEnvironmentDsnIsLeftToTheRuntimeCheck(): void
    {
        $container = $this->container('%env(LOCK_DSN)%', false, 'prod');
        (new ReplayLockPass())->process($container);
        self::assertSame('replay.store', (string) $container->getDefinition(ReplayGuard::class)->getArgument(2));
    }

    public function testNonStringDsnAndForeignFactories(): void
    {
        $container = $this->container(new Definition(PersistingStoreInterface::class), false, 'prod');
        $container->getDefinition('replay.store')->setFactory([StoreFactory::class, 'createStore'])->setArguments([new Reference('redis.client')]);
        (new ReplayLockPass())->process($container);

        $custom = $this->container('flock', false, 'prod');
        $custom->setDefinition('lock.factory', new Definition(LockFactory::class, [[]]));
        (new ReplayLockPass())->process($custom);
        self::assertNull($custom->getDefinition(ReplayGuard::class)->getArgument(2), 'unknown store: nothing to check');

        (new ReplayLockPass())->process(new ContainerBuilder());
    }

    public function testMissingFactoryFails(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(ReplayGuard::class, new Definition(ReplayGuard::class, [new Reference('lock.factory'), 300, null, false]));
        $this->expectException(LogicException::class);
        (new ReplayLockPass())->process($container);
    }

    private function container(string|Definition $store, bool $debug, string $env): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', $debug);
        $container->setParameter('kernel.environment', $env);
        if (is_string($store)) {
            $store = (string) $container->getParameterBag()->resolveValue($store);
        }
        $container->setDefinition('replay.store', $store instanceof Definition ? $store : (new Definition(PersistingStoreInterface::class))->setFactory([StoreFactory::class, 'createStore'])->setArguments([$store]));
        $container->setDefinition('lock.factory.abstract', (new Definition(LockFactory::class, [null]))->setAbstract(true));
        $container->setDefinition('lock.default.factory', (new ChildDefinition('lock.factory.abstract'))->replaceArgument(0, new Reference('replay.store')));
        $container->setAlias('lock.factory', 'lock.default.factory');
        $container->setDefinition(ReplayGuard::class, new Definition(ReplayGuard::class, [new Reference('lock.factory'), 300, null, false]));

        return $container;
    }
}
