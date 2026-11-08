<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\DependencyInjection\Compiler;

use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

use function is_a;
use function is_string;
use function sprintf;

/**
 * Audit IL-L2: the linking rate limiters must keep their counters. A pool backed by NullAdapter never keeps a window,
 * and an ArrayAdapter only lasts one request (or one worker), so either one silently switches the claim, e-mail lookup
 * and confirmation limits off. NullAdapter fails the compile everywhere; ArrayAdapter outside debug and test.
 * Pools whose class cannot be resolved (built by a factory) are not judged.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class LinkingLimiterPoolPass implements CompilerPassInterface
{
    /** Storage services the extension registers for the linking limiters. */
    public const STORAGES = [
        'nowo_slack_kit.linking.claim_per_account.storage',
        'nowo_slack_kit.linking.email_lookup_per_account.storage',
        'nowo_slack_kit.linking.confirmation_per_account.storage',
        'nowo_slack_kit.linking.confirmation_per_target.storage',
    ];

    /**
     * Checks the cache pool behind each linking limiter storage.
     */
    public function process(ContainerBuilder $container): void
    {
        $production = (!$container->hasParameter('kernel.debug') || $container->getParameter('kernel.debug') !== true)
            && (!$container->hasParameter('kernel.environment') || $container->getParameter('kernel.environment') !== 'test');

        foreach (self::STORAGES as $storage) {
            if (!$container->hasDefinition($storage)) {
                continue;
            }
            $pool = $container->getDefinition($storage)->getArgument(0);
            if (!$pool instanceof Reference || !$container->has((string) $pool)) {
                continue;
            }
            $class = $this->classOf($container, $container->findDefinition((string) $pool));
            if ($class === null) {
                continue;
            }
            if (is_a($class, NullAdapter::class, true)) {
                throw new InvalidArgumentException(sprintf('nowo_slack_kit.linking.rate_limiter_cache_pool: "%s" is a NullAdapter pool, which keeps no counter and switches the linking rate limits off. Use a persistent pool (cache.app, cache.rate_limiter, Redis).', $pool));
            }
            if ($production && is_a($class, ArrayAdapter::class, true)) {
                throw new InvalidArgumentException(sprintf('nowo_slack_kit.linking.rate_limiter_cache_pool: "%s" is an ArrayAdapter pool, whose counters last one request (or one worker), so the linking rate limits do not hold. Use a persistent pool outside debug and test.', $pool));
            }
        }
    }

    /**
     * The class of a pool definition, following ChildDefinition parents (framework cache pools) and parameters.
     */
    private function classOf(ContainerBuilder $container, Definition $definition): ?string
    {
        $class = $definition->getClass();
        while ($class === null && $definition instanceof ChildDefinition && $container->has($definition->getParent())) {
            $definition = $container->findDefinition($definition->getParent());
            $class      = $definition->getClass();
        }
        $class = $container->getParameterBag()->resolveValue($class);

        return is_string($class) ? $class : null;
    }
}
