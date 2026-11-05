<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\SlackKitBundle\Ingress\ReplayGuard;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Lock\Store\StoreFactory;

use function in_array;
use function is_string;
use function sprintf;
use function str_starts_with;

/**
 * Hands the replay lock factory's store to {@see ReplayGuard} (runtime check) and fails the build when that store is
 * local to one process or host (`flock`, `semaphore`, `in-memory`, `null`, or those classes) outside debug and test
 * (audit FS-L1). A DSN from an environment variable is checked at runtime instead.
 */
final class ReplayLockPass implements CompilerPassInterface
{
    /** DSNs of local stores (FrameworkBundle `lock` resources). */
    private const LOCAL_DSNS = ['flock', 'semaphore', 'in-memory', 'null'];

    /**
     * Wires the store and refuses a local one in production.
     *
     * @throws LogicException when the store is local outside debug and test
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
        if (!$container->hasDefinition($factoryId) && !$container->hasAlias($factoryId)) {
            throw new LogicException(sprintf('NowoSlackKitBundle: the replay lock factory "%s" does not exist; enable framework.lock or set nowo_slack_kit.ingress.replay_lock_factory.', $factoryId));
        }
        $factory = $container->findDefinition($factoryId);
        $store   = $this->argument($factory);
        if (!$store instanceof Reference || (!$container->hasDefinition((string) $store) && !$container->hasAlias((string) $store))) {
            return;
        }
        $guard->replaceArgument(2, $store);
        if ($production && $this->isLocal($container, $container->findDefinition((string) $store))) {
            throw new LogicException('NowoSlackKitBundle: the replay lock store is local to one process or host (flock, semaphore, in-memory); configure framework.lock with a Redis or DBAL store (or set nowo_slack_kit.ingress.replay_lock_factory).');
        }
    }

    private function argument(Definition $definition): mixed
    {
        $arguments = $definition->getArguments();

        return $arguments['index_0'] ?? $arguments[0] ?? null;
    }

    private function isLocal(ContainerBuilder $container, Definition $store): bool
    {
        $factory = $store->getFactory();
        if ($factory === [StoreFactory::class, 'createStore']) {
            $dsn = $this->argument($store);
            if (!is_string($dsn)) {
                return false;
            }
            $usedEnvs = [];
            $resolved = $container->resolveEnvPlaceholders($dsn, null, $usedEnvs);
            if ($usedEnvs !== [] || !is_string($resolved)) {
                return false; // Checked at runtime by ReplayGuard.
            }
            foreach (self::LOCAL_DSNS as $local) {
                if ($resolved === $local || str_starts_with($resolved, $local . ':')) {
                    return true;
                }
            }

            return false;
        }

        return in_array($store->getClass(), ReplayGuard::LOCAL_STORES, true);
    }
}
