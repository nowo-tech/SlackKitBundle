<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\DependencyInjection;

use Nowo\SlackKitBundle\DependencyInjection\Compiler\LinkingLimiterPoolPass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Audit IL-L2: a linking limiter pool that keeps no counter fails the build.
 */
final class LinkingLimiterPoolPassTest extends TestCase
{
    /**
     * @return iterable<string, array{0: Definition, 1: bool, 2: string, 3: bool}>
     */
    public static function pools(): iterable
    {
        yield 'null adapter in prod' => [new Definition(NullAdapter::class), false, 'prod', true];
        yield 'null adapter in test' => [new Definition(NullAdapter::class), false, 'test', true];
        yield 'null adapter in debug' => [new Definition(NullAdapter::class), true, 'dev', true];
        yield 'array adapter in prod' => [new Definition(ArrayAdapter::class), false, 'prod', true];
        yield 'array adapter in debug' => [new Definition(ArrayAdapter::class), true, 'dev', false];
        yield 'array adapter in test' => [new Definition(ArrayAdapter::class), false, 'test', false];
        yield 'filesystem in prod' => [new Definition(FilesystemAdapter::class), false, 'prod', false];
        yield 'redis in prod' => [new Definition(RedisAdapter::class), false, 'prod', false];
        yield 'framework pool on the array adapter in prod' => [new ChildDefinition('cache.adapter.array'), false, 'prod', true];
        yield 'framework pool on the null adapter in prod' => [new ChildDefinition('cache.adapter.null'), false, 'prod', true];
        yield 'class from a parameter in prod' => [new Definition('%app.pool_class%'), false, 'prod', true];
        yield 'factory-built pool in prod' => [(new Definition())->setFactory(['App\\Cache', 'pool']), false, 'prod', false];
    }

    #[DataProvider('pools')]
    public function testPools(Definition $pool, bool $debug, string $env, bool $refused): void
    {
        $container = $this->container($pool, $debug, $env);

        if ($refused) {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('nowo_slack_kit.linking.rate_limiter_cache_pool: "app.pool"');
        }
        (new LinkingLimiterPoolPass())->process($container);
        $this->addToAssertionCount(1);
    }

    public function testWithoutKernelParametersArrayPoolsCountAsProduction(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.pool', new Definition(ArrayAdapter::class));
        $container->setDefinition(LinkingLimiterPoolPass::STORAGES[2], new Definition(CacheStorage::class, [new Reference('app.pool')]));

        $this->expectException(InvalidArgumentException::class);
        (new LinkingLimiterPoolPass())->process($container);
    }

    public function testNothingToCheckWithoutLinkingOrAPoolReference(): void
    {
        (new LinkingLimiterPoolPass())->process(new ContainerBuilder());

        $container = new ContainerBuilder();
        $container->setDefinition(LinkingLimiterPoolPass::STORAGES[0], new Definition(CacheStorage::class, ['not a reference']));
        $container->setDefinition(LinkingLimiterPoolPass::STORAGES[1], new Definition(CacheStorage::class, [new Reference('missing.pool')]));
        (new LinkingLimiterPoolPass())->process($container);
        $this->addToAssertionCount(1);
    }

    private function container(Definition $pool, bool $debug, string $env): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', $debug);
        $container->setParameter('kernel.environment', $env);
        $container->setParameter('app.pool_class', ArrayAdapter::class);
        $container->setDefinition('cache.adapter.array', new Definition(ArrayAdapter::class));
        $container->setDefinition('cache.adapter.null', new Definition(NullAdapter::class));
        $container->setDefinition('app.pool', $pool);
        foreach (LinkingLimiterPoolPass::STORAGES as $storage) {
            $container->setDefinition($storage, new Definition(CacheStorage::class, [new Reference('app.pool')]));
        }

        return $container;
    }
}
