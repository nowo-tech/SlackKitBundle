<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\SlackKitBundle\Api\ResponseUrlClient;
use Nowo\SlackKitBundle\Api\ResponseUrlClientInterface;
use Nowo\SlackKitBundle\Credentials\InMemoryInstallationBindingStore;
use Nowo\SlackKitBundle\Credentials\InMemoryInstallationStore;
use Nowo\SlackKitBundle\Credentials\InMemoryInstallationStoreBoundaryListener;
use Nowo\SlackKitBundle\Credentials\SlackInstallationBindingStoreInterface;
use Nowo\SlackKitBundle\DependencyInjection\NowoSlackKitExtension;
use Nowo\SlackKitBundle\Ingress\EventsHandler;
use Nowo\SlackKitBundle\Ingress\IngressController;
use Nowo\SlackKitBundle\Install\InstallController;
use Nowo\SlackKitBundle\Install\SlackInstallAuthorizerInterface;
use Nowo\SlackKitBundle\Install\SlackOAuthClient;
use Nowo\SlackKitBundle\Interaction\ActorResolver;
use Nowo\SlackKitBundle\Interaction\ReplyDispatcher;
use Nowo\SlackKitBundle\Routing\SlackKitRouteLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\EnvPlaceholderParameterBag;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Notifier\Transport\TransportFactoryInterface;
use Throwable;

use function is_array;

/**
 * Spec 237: `installations` wiring — standard hosts change nothing; DDD hosts plug their own stores and authoriser.
 */
final class InstallationsWiringTest extends TestCase
{
    private const OAUTH = [
        'apps'          => ['default' => ['app_id' => 'A00000000', 'client_id' => '1111111111.2222222222', 'client_secret' => '%env(SLACK_CLIENT_SECRET)%']],
        'credentials'   => ['store' => 'app.slack_store'],
        'installations' => ['binding_store' => 'app.bindings', 'oauth' => ['enabled' => true, 'authorizer' => 'app.authorizer', 'account_resolver' => 'app.accounts', 'return_route' => 'app_integrations']],
    ];

    public function testStandardHostsKeepTheirWiring(): void
    {
        $container = $this->load([]);
        self::assertFalse($container->has(SlackInstallationBindingStoreInterface::class));
        self::assertFalse($container->hasDefinition(InstallController::class));
        self::assertNull($container->getDefinition(EventsHandler::class)->getArgument(3));
        self::assertFalse($container->getDefinition(SlackKitRouteLoader::class)->getArgument(5));
        self::assertFalse($container->hasDefinition(InMemoryInstallationStoreBoundaryListener::class));
        self::assertSame(ResponseUrlClient::class, (string) $container->getAlias(ResponseUrlClientInterface::class));
        self::assertTrue($container->getAlias(ResponseUrlClientInterface::class)->isPublic());
        self::assertSame(NowoSlackKitExtension::WEBHOOK_HTTP_CLIENT, (string) $container->getDefinition(ResponseUrlClient::class)->getArgument(0), 'the response URL is a credential: logger-free client');
        self::assertSame(ResponseUrlClientInterface::class, (string) $container->getDefinition(ReplyDispatcher::class)->getArgument(2));
        self::assertFalse($container->getDefinition(IngressController::class)->getArgument(14), 'own-app clicks get no actor by default (PZ-M1)');
        self::assertNull($container->getDefinition(ActorResolver::class)->getArgument(1));
    }

    public function testOwnAppActorsAndTheActorFilterAreOptIn(): void
    {
        $container = $this->load(['installations' => ['own_app_actors' => true, 'actor_filter' => 'app.actor_filter']]);
        self::assertTrue($container->getDefinition(IngressController::class)->getArgument(14));
        self::assertSame('app.actor_filter', (string) $container->getDefinition(ActorResolver::class)->getArgument(1));
    }

    public function testDddHostPlugsItsStoresAndAuthoriser(): void
    {
        $container = $this->load(self::OAUTH);

        self::assertSame('app.bindings', (string) $container->getAlias(SlackInstallationBindingStoreInterface::class));
        self::assertSame('app.authorizer', (string) $container->getAlias(SlackInstallAuthorizerInterface::class));
        self::assertSame(SlackInstallationBindingStoreInterface::class, (string) $container->getDefinition(EventsHandler::class)->getArgument(3));
        self::assertTrue($container->getDefinition(SlackKitRouteLoader::class)->getArgument(5));
        self::assertSame(NowoSlackKitExtension::WEBHOOK_HTTP_CLIENT, (string) $container->getDefinition(SlackOAuthClient::class)->getArgument(0), 'the OAuth answer holds the token: logger-free client');
        $controller = $container->getDefinition(InstallController::class);
        self::assertSame('app.authorizer', (string) $controller->getArgument(1));
        self::assertSame('app.accounts', (string) $controller->getArgument(2));
        self::assertSame(['chat:write', 'channels:read', 'groups:read', 'users:read'], $controller->getArgument(10));
        self::assertSame('app_integrations', $controller->getArgument(12));

        $config                                               = self::OAUTH;
        $config['installations']['oauth']['account_resolver'] = null;
        $config['linking']                                    = ['enabled' => true, 'identity_store' => 'app.ids', 'account_resolver' => 'app.link_accounts'];
        $viaLinking                                           = $this->load($config);
        self::assertSame('app.link_accounts', (string) $viaLinking->getDefinition(InstallController::class)->getArgument(2));
    }

    public function testMemoryBindingStoreIsResetAtTheBoundaries(): void
    {
        $container = $this->load(['credentials' => ['store' => 'memory'], 'installations' => ['binding_store' => 'memory']]);
        self::assertSame(InMemoryInstallationBindingStore::class, (string) $container->getAlias(SlackInstallationBindingStoreInterface::class));
        self::assertTrue($container->getDefinition(InMemoryInstallationBindingStore::class)->hasTag('kernel.reset'));
        $stores = $container->getDefinition(InMemoryInstallationStoreBoundaryListener::class)->getArgument(0);
        self::assertInstanceOf(IteratorArgument::class, $stores);
        self::assertSame([InMemoryInstallationStore::class, InMemoryInstallationBindingStore::class], array_map(static fn (Reference $r): string => (string) $r, $stores->getValues()));

        $onlyBindings = $this->load(['installations' => ['binding_store' => 'memory']]);
        self::assertTrue($onlyBindings->hasDefinition(InMemoryInstallationStoreBoundaryListener::class));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('never in production');
        $this->load(['installations' => ['binding_store' => 'memory']], ['kernel.debug' => false, 'kernel.environment' => 'prod']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: class-string<Throwable>}>
     */
    public static function refusals(): iterable
    {
        $oauth = self::OAUTH;
        yield 'instance settings store' => [['credentials' => ['store' => 'instance_settings']] + $oauth, InvalidConfigurationException::class];
        yield 'no binding store' => [['installations' => ['binding_store' => null] + $oauth['installations']] + $oauth, InvalidConfigurationException::class];
        yield 'no authoriser' => [['installations' => ['oauth' => ['enabled' => true, 'account_resolver' => 'app.accounts']] + $oauth['installations']] + $oauth, InvalidConfigurationException::class];
        yield 'no account resolver' => [['installations' => ['oauth' => ['enabled' => true, 'authorizer' => 'app.authorizer']] + $oauth['installations']] + $oauth, InvalidConfigurationException::class];
        yield 'empty scopes' => [['installations' => ['oauth' => ['scopes' => []] + $oauth['installations']['oauth']] + $oauth['installations']] + $oauth, InvalidConfigurationException::class];
        yield 'literal client secret' => [['apps' => ['default' => ['client_secret' => 'literalclientsecretliteral000000']]], InvalidConfigurationException::class];
        yield 'own-app actors env switch' => [['installations' => ['own_app_actors' => '%env(bool:SLACK_OWN_APP_ACTORS)%']], InvalidConfigurationException::class];
        yield 'env switch' => [['installations' => ['oauth' => ['enabled' => '%env(bool:SLACK_OAUTH)%']]], InvalidConfigurationException::class];
        yield 'bad scope' => [['installations' => ['oauth' => ['scopes' => ['Chat Write']]]], InvalidConfigurationException::class];
        yield 'state ttl over 10 minutes' => [['installations' => ['oauth' => ['state_ttl' => 601]]], InvalidConfigurationException::class];
    }

    /**
     * @param array<string, mixed> $config
     * @param class-string<Throwable> $exception
     */
    #[DataProvider('refusals')]
    public function testRefusals(array $config, string $exception): void
    {
        $this->expectException($exception);
        $this->load($config);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $params
     */
    private function load(array $config, array $params = []): ContainerBuilder
    {
        $container = new ContainerBuilder(new EnvPlaceholderParameterBag(['kernel.secret' => 'test-secret-not-real'] + $params));
        $extension = new NowoSlackKitExtension(InstanceSettingsInterface::class, MessageBusInterface::class, TransportFactoryInterface::class);
        $container->registerExtension($extension);
        $resolved = $container->getParameterBag()->resolveValue($config);
        $extension->load([is_array($resolved) ? $resolved : []], $container);

        return $container;
    }
}
