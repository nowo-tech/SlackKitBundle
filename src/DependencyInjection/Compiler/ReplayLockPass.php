<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\SlackKitBundle\Ingress\ReplayGuard;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Lock\Store\CombinedStore;
use Symfony\Component\Lock\Store\StoreFactory;

use function in_array;
use function is_array;
use function is_string;
use function is_subclass_of;
use function sprintf;

/**
 * Hands the replay lock factory's store to {@see ReplayGuard} (runtime check) and, outside debug and test, fails the
 * build unless that store is on the allowlist of expiring shared stores ({@see ReplayGuard::EXPIRING_STORES}): Redis,
 * Memcached, PDO, Doctrine DBAL, MongoDB, DynamoDB (audit FS-L1, FSD-L1). `CombinedStore` members are checked one by
 * one; session-scoped stores (`pgsql+advisory:`, `zookeeper://`) and local ones are refused. A DSN or a connection
 * service the build cannot classify (an `%env()%` value, a factory-built service) is checked at runtime instead.
 */
final class ReplayLockPass implements CompilerPassInterface
{
    /** Connection classes `StoreFactory` turns into an expiring shared store. */
    private const EXPIRING_CONNECTIONS = [
        'Redis', 'RedisArray', 'RedisCluster', 'Relay\Relay', 'Predis\ClientInterface', 'Memcached', 'PDO',
        'Doctrine\DBAL\Connection', 'MongoDB\Collection', 'AsyncAws\DynamoDb\DynamoDbClient',
    ];

    /** Combined store depth guard. */
    private const MAX_DEPTH = 5;

    /**
     * Wires the store and refuses one off the allowlist in production.
     *
     * @throws LogicException when the factory is missing, or the store is refused outside debug and test
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ReplayGuard::class)) {
            return;
        }
        $guard      = $container->getDefinition(ReplayGuard::class);
        $factoryId  = (string) $guard->getArgument(0);
        $production = $container->hasParameter('kernel.debug') && $container->getParameter('kernel.debug') === false
            && $container->hasParameter('kernel.environment') && $container->getParameter('kernel.environment') !== 'test';
        $guard->replaceArgument(3, $production);
        if (!$this->exists($container, $factoryId)) {
            throw new LogicException(sprintf('NowoSlackKitBundle: the replay lock factory "%s" does not exist; enable framework.lock or set nowo_slack_kit.ingress.replay_lock_factory.', $factoryId));
        }
        $store = $this->argument($container->findDefinition($factoryId));
        if (!$store instanceof Reference || !$this->exists($container, (string) $store)) {
            return;
        }
        $guard->replaceArgument(2, $store);
        if ($production && $this->classify($container, $container->findDefinition((string) $store), 0) === false) {
            throw new LogicException('NowoSlackKitBundle: the replay lock store is not an expiring shared store (Redis, Memcached, PDO, Doctrine DBAL, MongoDB, DynamoDB); local and session-scoped stores (flock, semaphore, in-memory, pgsql+advisory, zookeeper) are refused. Point nowo_slack_kit.ingress.replay_lock_factory at a dedicated framework.lock resource.');
        }
    }

    private function exists(ContainerBuilder $container, string $id): bool
    {
        return $container->hasDefinition($id) || $container->hasAlias($id);
    }

    private function argument(Definition $definition): mixed
    {
        $arguments = $definition->getArguments();

        return $arguments['index_0'] ?? $arguments[0] ?? null;
    }

    /**
     * @return bool|null true allowed, false refused, null unknown at build time (runtime check)
     */
    private function classify(ContainerBuilder $container, Definition $store, int $depth): ?bool
    {
        if ($store->getFactory() === [StoreFactory::class, 'createStore']) {
            return $this->classifyConnection($container, $this->argument($store));
        }
        $class = $this->classOf($container, $store);
        if ($class === CombinedStore::class) {
            return $this->classifyMembers($container, $this->argument($store), $depth);
        }
        if ($class === null) {
            return null;
        }

        return in_array($class, ReplayGuard::EXPIRING_STORES, true);
    }

    private function classifyConnection(ContainerBuilder $container, mixed $connection): ?bool
    {
        if (is_string($connection)) {
            $usedEnvs = [];
            $resolved = $container->resolveEnvPlaceholders($connection, null, $usedEnvs);
            if ($usedEnvs !== [] || !is_string($resolved)) {
                return null; // Checked at runtime by ReplayGuard.
            }

            return ReplayGuard::isSharedDsn($resolved);
        }
        if (!$connection instanceof Reference || !$this->exists($container, (string) $connection)) {
            return null;
        }
        $class = $this->classOf($container, $container->findDefinition((string) $connection));
        if ($class === null) {
            return null;
        }
        foreach (self::EXPIRING_CONNECTIONS as $allowed) {
            if ($class === $allowed || is_subclass_of($class, $allowed)) {
                return true;
            }
        }

        return false;
    }

    private function classifyMembers(ContainerBuilder $container, mixed $members, int $depth): ?bool
    {
        if (!is_array($members) || $members === [] || $depth >= self::MAX_DEPTH) {
            return false;
        }
        $result = true;
        foreach ($members as $member) {
            if (!$member instanceof Reference || !$this->exists($container, (string) $member)) {
                return false;
            }
            $verdict = $this->classify($container, $container->findDefinition((string) $member), $depth + 1);
            if ($verdict === false) {
                return false;
            }
            if ($verdict === null) {
                $result = null;
            }
        }

        return $result;
    }

    private function classOf(ContainerBuilder $container, Definition $definition): ?string
    {
        $class = $definition->getClass();
        while ($class === null && $definition instanceof ChildDefinition && $this->exists($container, $definition->getParent())) {
            $definition = $container->findDefinition($definition->getParent());
            $class      = $definition->getClass();
        }
        $resolved = is_string($class) ? $container->getParameterBag()->resolveValue($class) : null;

        return is_string($resolved) ? $resolved : null;
    }
}
