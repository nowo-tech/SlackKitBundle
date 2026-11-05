<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\DependencyInjection;

use Doctrine\DBAL\Connection;
use LogicException;
use Nowo\SlackKitBundle\DependencyInjection\Compiler\ReplayLockPass;
use Nowo\SlackKitBundle\Ingress\ReplayGuard;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\CombinedStore;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Lock\Store\PdoStore;
use Symfony\Component\Lock\Store\PostgreSqlStore;
use Symfony\Component\Lock\Store\StoreFactory;
use Symfony\Component\Lock\Store\ZookeeperStore;

use function is_string;

/**
 * Audit FS-L1 / FSD-L1: outside debug and test, only expiring shared lock stores build.
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
        yield 'class off the allowlist in prod' => [new Definition(stdClass::class), false, 'prod', true];
        yield 'pdo class in prod' => [new Definition(PdoStore::class), false, 'prod', false];
        yield 'postgres advisory class in prod' => [new Definition(PostgreSqlStore::class), false, 'prod', true];
        yield 'zookeeper class in prod' => [new Definition(ZookeeperStore::class), false, 'prod', true];
        yield 'unknown class (factory) in prod' => [(new Definition())->setFactory(['App\\Locks', 'store']), false, 'prod', false];
        yield 'pgsql+advisory: in prod (FSD-L1)' => ['pgsql+advisory:host=db.example.test', false, 'prod', true];
        yield 'pgsql+advisory:// in prod' => ['pgsql+advisory://u@db.example.test/app', false, 'prod', true];
        yield 'postgresql+advisory:// in prod' => ['postgresql+advisory://u@db.example.test/app', false, 'prod', true];
        yield 'zookeeper in prod' => ['zookeeper://zk.example.test:2181', false, 'prod', true];
        yield 'unknown DSN in prod' => ['custom://x', false, 'prod', true];
        yield 'dbal url in prod' => ['postgresql://u@db.example.test/app', false, 'prod', false];
        yield 'pdo dsn in prod' => ['pgsql:host=db.example.test', false, 'prod', false];
        yield 'memcached in prod' => ['memcached://m.example.test', false, 'prod', false];
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

    /**
     * @return iterable<string, array{0: list<string>, 1: bool}>
     */
    public static function combined(): iterable
    {
        yield 'all shared' => [['redis://a.example.test', 'redis://b.example.test'], false];
        yield 'one local member' => [['redis://a.example.test', 'flock'], true];
        yield 'one advisory member' => [['redis://a.example.test', 'pgsql+advisory:host=db'], true];
        yield 'nested combined with a local member' => [['redis://a.example.test', 'combined:flock'], true];
        yield 'nested combined, all shared' => [['redis://a.example.test', 'combined:redis://c.example.test'], false];
        yield 'one env member' => [['redis://a.example.test', '%env(LOCK_DSN)%'], false];
        yield 'no member' => [[], true];
    }

    /**
     * @param list<string> $members
     */
    #[DataProvider('combined')]
    public function testCombinedStoreMembersAreCheckedRecursively(array $members, bool $refused): void
    {
        $container = $this->container('redis://unused.example.test', false, 'prod');
        $container->setDefinition('lock.store.combined.abstract', (new Definition(CombinedStore::class, [null, null]))->setAbstract(true));
        $refs = [];
        foreach ($members as $i => $member) {
            $id = 'member.' . $i;
            if (str_starts_with($member, 'combined:')) {
                $container->setDefinition($id . '.inner', $this->dsnStore($container, substr($member, 9)));
                $container->setDefinition($id, (new ChildDefinition('lock.store.combined.abstract'))->replaceArgument(0, [new Reference($id . '.inner')]));
            } else {
                $container->setDefinition($id, $this->dsnStore($container, $member));
            }
            $refs[] = new Reference($id);
        }
        $container->setDefinition('replay.store', (new ChildDefinition('lock.store.combined.abstract'))->replaceArgument(0, $refs));
        if ($refused) {
            $this->expectException(LogicException::class);
        }
        (new ReplayLockPass())->process($container);
        self::assertSame('replay.store', (string) $container->getDefinition(ReplayGuard::class)->getArgument(2));
    }

    public function testCombinedStoreWithAMissingMemberIsRefused(): void
    {
        $container = $this->container('redis://unused.example.test', false, 'prod');
        $container->setDefinition('replay.store', new Definition(CombinedStore::class, [[new Reference('missing.store')], null]));
        $this->expectException(LogicException::class);
        (new ReplayLockPass())->process($container);
    }

    /**
     * @return iterable<string, array{0: Definition|null, 1: bool}>
     */
    public static function connections(): iterable
    {
        yield 'pdo' => [new Definition(PDO::class), false];
        yield 'dbal connection' => [new Definition(Connection::class), false];
        yield 'zookeeper' => [new Definition('Zookeeper'), true];
        yield 'factory-built (runtime check)' => [(new Definition())->setFactory(['App\\Connections', 'make']), false];
        yield 'child of a connection' => [new ChildDefinition('conn.parent'), false];
        yield 'missing service' => [null, false];
    }

    #[DataProvider('connections')]
    public function testConnectionServices(?Definition $connection, bool $refused): void
    {
        $container = $this->container('redis://unused.example.test', false, 'prod');
        $container->setDefinition('conn.parent', new Definition(PDO::class));
        if ($connection instanceof Definition) {
            $container->setDefinition('conn', $connection);
        }
        $container->getDefinition('replay.store')->setArguments([new Reference('conn')]);
        if ($refused) {
            $this->expectException(LogicException::class);
        }
        (new ReplayLockPass())->process($container);
        self::assertSame('replay.store', (string) $container->getDefinition(ReplayGuard::class)->getArgument(2));
    }

    public function testMissingFactoryFails(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(ReplayGuard::class, new Definition(ReplayGuard::class, [new Reference('lock.factory'), 300, null, false]));
        $this->expectException(LogicException::class);
        (new ReplayLockPass())->process($container);
    }

    private function dsnStore(ContainerBuilder $container, string $dsn): Definition
    {
        return (new Definition(PersistingStoreInterface::class))->setFactory([StoreFactory::class, 'createStore'])->setArguments([(string) $container->getParameterBag()->resolveValue($dsn)]);
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
