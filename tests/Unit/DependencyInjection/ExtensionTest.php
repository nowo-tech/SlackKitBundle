<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\SlackKitBundle\Api\IncomingWebhookClient;
use Nowo\SlackKitBundle\Controller\AccountLinkController;
use Nowo\SlackKitBundle\Controller\TestConnectionController;
use Nowo\SlackKitBundle\Credentials\InMemoryInstallationStore;
use Nowo\SlackKitBundle\Credentials\InstanceSettingsInstallationStore;
use Nowo\SlackKitBundle\Credentials\SlackInstallationStoreInterface;
use Nowo\SlackKitBundle\DependencyInjection\Compiler\ActionHandlerPass;
use Nowo\SlackKitBundle\DependencyInjection\Compiler\CsrfPass;
use Nowo\SlackKitBundle\DependencyInjection\Configuration;
use Nowo\SlackKitBundle\DependencyInjection\NowoSlackKitExtension;
use Nowo\SlackKitBundle\Ingress\EventsHandler;
use Nowo\SlackKitBundle\Ingress\IngressController;
use Nowo\SlackKitBundle\Ingress\ReplayGuard;
use Nowo\SlackKitBundle\Interaction\AsSlackActionHandler;
use Nowo\SlackKitBundle\Interaction\ButtonValueCodec as ButtonValueCodecAlias;
use Nowo\SlackKitBundle\Interaction\InteractionRouter;
use Nowo\SlackKitBundle\Interaction\LegacyValuePolicy;
use Nowo\SlackKitBundle\Linking\AccountLinker;
use Nowo\SlackKitBundle\Notifier\SlackKitTransportFactory;
use Nowo\SlackKitBundle\NowoSlackKitBundle;
use Nowo\SlackKitBundle\Settings\SlackSettingsSection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\ParameterBag\EnvPlaceholderParameterBag;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Notifier\Transport\TransportFactoryInterface;
use Throwable;

use function is_array;

final class ExtensionTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), []);
        self::assertSame('en', $config['default_locale']);
        self::assertSame(['default' => ['app_id' => null, 'signing_secret' => null, 'client_id' => null, 'client_secret' => null]], $config['apps']);
        self::assertNull($config['installations']['binding_store']);
        self::assertFalse($config['installations']['oauth']['enabled']);
        self::assertSame(['chat:write', 'channels:read', 'groups:read', 'users:read'], $config['installations']['oauth']['scopes']);
        self::assertSame(600, $config['installations']['oauth']['state_ttl']);
        self::assertSame('instance_settings', $config['credentials']['store']);
        self::assertSame(300, $config['ingress']['max_skew_seconds']);
        self::assertSame('P30D', $config['interactions']['value_ttl']);
        self::assertFalse($config['linking']['enabled']);
        self::assertSame(['limit' => 3, 'interval' => '1 hour'], $config['linking']['confirmation_per_target']);
        self::assertSame('cache.app', $config['linking']['rate_limiter_cache_pool']);
        self::assertTrue(Configuration::isDuration('PT24H'));
        self::assertFalse(Configuration::isDuration('P'));
        self::assertFalse(Configuration::isDuration(5));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function invalidTrees(): iterable
    {
        yield 'skew over 300' => [['ingress' => ['max_skew_seconds' => 301]]];
        yield 'ttl' => [['interactions' => ['value_ttl' => '30 days']]];
        yield 'claim ttl' => [['linking' => ['claim_ttl' => 'x']]];
        yield 'confirmation ttl' => [['linking' => ['confirmation_ttl' => 'x']]];
        yield 'app name' => [['default_app' => 'Bad App']];
        yield 'limiter pool @' => [['linking' => ['rate_limiter_cache_pool' => '@']]];
        yield 'limiter pool not a string' => [['linking' => ['rate_limiter_cache_pool' => 42]]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidTrees')]
    public function testTreeRefusals(array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new Configuration(), [$config]);
    }

    public function testDefaultWiringWithInstanceSettings(): void
    {
        $container = $this->load([]);
        self::assertSame(InstanceSettingsInstallationStore::class, (string) $container->getAlias(SlackInstallationStoreInterface::class));
        self::assertTrue($container->hasDefinition(SlackSettingsSection::class));
        self::assertTrue($container->hasDefinition(TestConnectionController::class));
        self::assertTrue($container->hasDefinition(IngressController::class));
        self::assertFalse($container->hasDefinition(AccountLinker::class));
        self::assertFalse($container->hasDefinition(SlackKitTransportFactory::class));
        self::assertTrue($container->hasDefinition(NowoSlackKitExtension::RETRY_MIDDLEWARE));
        self::assertSame([], $container->getParameter('nowo_slack_kit.interactions.legacy_action_map'));
    }

    public function testMemoryStoreIsRefusedInProduction(): void
    {
        // Allowed in debug and in the test env.
        foreach ([[true, 'prod'], [false, 'test'], [true, 'dev']] as [$debug, $env]) {
            $container = $this->load(['credentials' => ['store' => 'memory'], 'ingress' => ['enabled' => false]], params: ['kernel.debug' => $debug, 'kernel.environment' => $env]);
            self::assertSame(InMemoryInstallationStore::class, (string) $container->getAlias(SlackInstallationStoreInterface::class));
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('never in production');
        $this->load(['credentials' => ['store' => 'memory'], 'ingress' => ['enabled' => false]], params: ['kernel.debug' => false, 'kernel.environment' => 'prod']);
    }

    public function testMemoryStoreAndServiceStore(): void
    {
        $memory = $this->load(['credentials' => ['store' => 'memory'], 'notifier' => ['enabled' => true], 'ingress' => ['enabled' => false]]);
        self::assertSame(InMemoryInstallationStore::class, (string) $memory->getAlias(SlackInstallationStoreInterface::class));
        self::assertFalse($memory->hasDefinition(SlackSettingsSection::class));
        self::assertFalse($memory->hasDefinition(IngressController::class));
        self::assertTrue($memory->hasDefinition(SlackKitTransportFactory::class));

        $service = $this->load(['credentials' => ['store' => 'app.slack_store'], 'ingress' => ['events_enabled' => true, 'async' => true], 'linking' => ['enabled' => true, 'identity_store' => 'app.ids', 'account_resolver' => 'app.accounts']]);
        self::assertSame('app.slack_store', (string) $service->getAlias(SlackInstallationStoreInterface::class));
        self::assertTrue($service->getDefinition(EventsHandler::class)->hasTag('messenger.message_handler'));
        self::assertTrue($service->hasDefinition(AccountLinker::class));
        self::assertTrue($service->hasDefinition(AccountLinkController::class));
        self::assertTrue($service->hasDefinition('nowo_slack_kit.linking.confirmation_per_target'));
        self::assertSame('cache.app', (string) $service->getDefinition('nowo_slack_kit.linking.confirmation_per_target.storage')->getArgument(0));
    }

    public function testLinkingLimiterCachePool(): void
    {
        $container = $this->load(['linking' => ['enabled' => true, 'identity_store' => 'app.ids', 'account_resolver' => 'app.accounts', 'rate_limiter_cache_pool' => '@cache.rate_limiter']]);
        foreach (['claim_per_account', 'email_lookup_per_account', 'confirmation_per_account', 'confirmation_per_target'] as $name) {
            self::assertSame('cache.rate_limiter', (string) $container->getDefinition('nowo_slack_kit.linking.' . $name . '.storage')->getArgument(0));
        }
        self::assertSame('cache.app', (string) $container->getDefinition('nowo_slack_kit.test_connection.rate_limiter.storage')->getArgument(0));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: class-string<Throwable>, 2?: array{0?: string, 1?: string, 2?: string}}>
     */
    public static function loadRefusals(): iterable
    {
        yield 'literal signing secret' => [['apps' => ['default' => ['signing_secret' => 'literalsecretliteralsecret000000']]], InvalidConfigurationException::class];
        yield 'literal previous secret' => [['interactions' => ['previous_secrets' => ['old-literal']]], InvalidConfigurationException::class];
        yield 'default app missing' => [['default_app' => 'ops'], InvalidConfigurationException::class];
        yield 'env bool switch' => [['linking' => ['enabled' => '%env(bool:X)%']], InvalidConfigurationException::class];
        yield 'linking without services' => [['linking' => ['enabled' => true]], InvalidConfigurationException::class];
        yield 'legacy without cutoff' => [['interactions' => ['legacy_value_key' => 'a', 'legacy_action_map' => ['resolve' => 'x.y']]], InvalidConfigurationException::class];
        yield 'legacy bad cutoff' => [['interactions' => ['legacy_value_key' => 'a', 'legacy_until' => 'not a date', 'legacy_action_map' => ['r' => 'x']]], InvalidConfigurationException::class];
        yield 'legacy relative cutoff (FS-L4)' => [['interactions' => ['legacy_value_key' => 'a', 'legacy_until' => '+30 days', 'legacy_action_map' => ['r' => 'x']]], InvalidConfigurationException::class];
        yield 'legacy cutoff without zone' => [['interactions' => ['legacy_until' => '2026-12-05T00:00:00']], InvalidConfigurationException::class];
        yield 'legacy cutoff in another zone' => [['interactions' => ['legacy_until' => '2026-12-05T00:00:00+01:00']], InvalidConfigurationException::class];
        yield 'legacy map to link confirm (FS-I4)' => [['interactions' => ['legacy_value_key' => 'a', 'legacy_until' => '2026-12-05T00:00:00Z', 'legacy_action_map' => ['link_confirm' => 'nowo_slack_kit.link.confirm']]], InvalidConfigurationException::class];
        yield 'instance settings missing' => [[], LogicException::class, ['Nope\\Missing']];
        yield 'async without messenger' => [['ingress' => ['async' => true]], LogicException::class, [1 => 'Nope\\Missing']];
        yield 'notifier missing' => [['notifier' => ['enabled' => true]], LogicException::class, [2 => 'Nope\\Missing']];
    }

    /**
     * @param array<string, mixed> $config
     * @param class-string<Throwable> $exception
     * @param array<int, string> $missing
     */
    #[DataProvider('loadRefusals')]
    public function testLoadRefusals(array $config, string $exception, array $missing = []): void
    {
        $this->expectException($exception);
        $this->load($config, $missing);
    }

    public function testSecretsFromTheEnvironmentAreAccepted(): void
    {
        $container = $this->load(['apps' => ['default' => ['signing_secret' => '%env(SLACK_SIGNING_SECRET)%']], 'interactions' => ['previous_secrets' => ['%env(OLD_APP_SECRET)%'], 'legacy_value_key' => 'a', 'legacy_until' => '2026-12-05T00:00:00Z', 'legacy_action_map' => ['resolve' => 'beacon.issue.resolve']]]);
        self::assertTrue($container->hasDefinition(NowoSlackKitExtension::BUTTON_KEY . '.previous.0'));
        self::assertSame(['resolve' => 'beacon.issue.resolve'], $container->getParameter('nowo_slack_kit.interactions.legacy_action_map'));

        $fromEnv = $this->load(['interactions' => ['legacy_value_key' => 'a', 'legacy_until' => '%env(SLACK_LEGACY_UNTIL)%', 'legacy_action_map' => ['resolve' => 'beacon.issue.resolve']]]);
        self::assertTrue($fromEnv->hasDefinition(LegacyValuePolicy::class), 'legacy_until may come from SLACK_LEGACY_UNTIL');
    }

    public function testHardenedWiring(): void
    {
        $container = $this->load(['rate_limit' => ['max_attempts' => 5], 'ingress' => ['resolver_actors' => true], 'interactions' => ['legacy_until' => '2026-12-05T00:00:00+00:00']]);
        self::assertSame([5], $container->getDefinition(NowoSlackKitExtension::RETRY_MIDDLEWARE)->getArguments());
        $webhook = $container->getDefinition(NowoSlackKitExtension::WEBHOOK_HTTP_CLIENT);
        self::assertSame([HttpClient::class, 'create'], $webhook->getFactory());
        self::assertSame([], $webhook->getTags(), 'not traced by the profiler, no logger');
        self::assertSame(NowoSlackKitExtension::WEBHOOK_HTTP_CLIENT, (string) $container->getDefinition(IncomingWebhookClient::class)->getArgument(0));
        self::assertSame('lock.factory', (string) $container->getDefinition(ReplayGuard::class)->getArgument(0));
        $ingress = $container->getDefinition(IngressController::class)->getArguments();
        self::assertTrue(end($ingress));
        self::assertSame([ButtonValueCodecAlias::class, 'deriveCheckedKey'], $container->getDefinition(NowoSlackKitExtension::BUTTON_KEY)->getFactory());
    }

    public function testAttributeAutoconfigurationAndDisabledTestConnection(): void
    {
        $container = $this->load(['test_connection' => ['enabled' => false]]);
        self::assertFalse($container->hasDefinition(TestConnectionController::class));
        self::assertTrue($container->hasDefinition(SlackSettingsSection::class));

        $configurator = $container->getAttributeAutoconfigurators()[AsSlackActionHandler::class][0];
        $definition   = new ChildDefinition('parent');
        $configurator($definition, new AsSlackActionHandler('app.do', false));
        self::assertSame([['action' => 'app.do', 'requires_actor' => false]], $definition->getTag(NowoSlackKitBundle::HANDLER_TAG));
    }

    public function testEnvironmentPlaceholdersAreRefusedForSwitches(): void
    {
        $container = new ContainerBuilder(new EnvPlaceholderParameterBag(['kernel.secret' => 'test-secret-not-real']));
        $bag       = $container->getParameterBag();
        self::assertInstanceOf(EnvPlaceholderParameterBag::class, $bag);
        $config = $bag->resolveValue(['ingress' => ['enabled' => '%env(bool:SLACK_INGRESS)%']]);
        BaseNode::setPlaceholderUniquePrefix($bag->getEnvPlaceholderUniquePrefix());

        try {
            (new NowoSlackKitExtension())->load([is_array($config) ? $config : []], $container);
            self::fail('accepted an env switch');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('ingress.enabled must be a literal boolean', $e->getMessage());
        } finally {
            BaseNode::resetPlaceholders();
        }
    }

    public function testLiteralSecretWithoutAnEnvBagIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"apps.default.signing_secret"');
        (new NowoSlackKitExtension())->load([['apps' => ['default' => ['signing_secret' => '%env(SLACK_SIGNING_SECRET)%']]]], new ContainerBuilder(new ParameterBag()));
    }

    public function testTwigPathIsPrepended(): void
    {
        $container = new ContainerBuilder();
        (new NowoSlackKitExtension())->prepend($container);
        self::assertSame([], $container->getExtensionConfig('twig'));
        $container->registerExtension(new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'twig';
            }
        });
        (new NowoSlackKitExtension())->prepend($container);
        self::assertSame('NowoSlackKitBundle', array_values($container->getExtensionConfig('twig')[0]['paths'])[0]);
    }

    public function testActionHandlerPass(): void
    {
        $container = $this->load([]);
        $container->setDefinition('h1', (new Definition(stdClass::class))->addTag(NowoSlackKitBundle::HANDLER_TAG, ['action' => 'a.one']));
        $container->setDefinition('h2', (new Definition(stdClass::class))->addTag(NowoSlackKitBundle::HANDLER_TAG, ['action' => 'a.two', 'requires_actor' => false]));
        (new ActionHandlerPass())->process($container);
        self::assertSame(['a.one' => true, 'a.two' => false], $container->getDefinition(InteractionRouter::class)->getArgument('$requiresActor'));

        foreach ([
            ['h3', ['action' => 'a.one']],
            ['h4', ['action' => 'Bad']],
        ] as [$id, $tag]) {
            $copy = $this->load([]);
            $copy->setDefinition('h1', (new Definition(stdClass::class))->addTag(NowoSlackKitBundle::HANDLER_TAG, ['action' => 'a.one']));
            $copy->setDefinition($id, (new Definition(stdClass::class))->addTag(NowoSlackKitBundle::HANDLER_TAG, $tag));
            try {
                (new ActionHandlerPass())->process($copy);
                self::fail('accepted ' . $id);
            } catch (LogicException) {
            }
        }

        $legacy = $this->load(['interactions' => ['legacy_value_key' => 'a', 'legacy_until' => '2026-12-05T00:00:00Z', 'legacy_action_map' => ['resolve' => 'beacon.issue.resolve']]]);
        $this->expectException(LogicException::class);
        (new ActionHandlerPass())->process($legacy);
    }

    public function testActionHandlerPassSkipsWithoutRouterAndCsrfPass(): void
    {
        (new ActionHandlerPass())->process(new ContainerBuilder());
        (new CsrfPass())->process(new ContainerBuilder());

        $ok = $this->load([]);
        $ok->register('security.csrf.token_manager', stdClass::class);
        $ok->register('form.type_extension.csrf', stdClass::class);
        (new CsrfPass())->process($ok);

        $disabled = $this->load([]);
        $disabled->register('security.csrf.token_manager', stdClass::class);
        $disabled->register('form.type_extension.csrf', stdClass::class);
        $disabled->setParameter('form.type_extension.csrf.enabled', false);
        $this->expectException(LogicException::class);
        (new CsrfPass())->process($disabled);
    }

    public function testBundle(): void
    {
        $bundle = new NowoSlackKitBundle();
        self::assertInstanceOf(NowoSlackKitExtension::class, $bundle->getContainerExtension());
        self::assertSame($bundle->getContainerExtension(), $bundle->getContainerExtension());
        self::assertStringEndsWith('/src', $bundle->getPath());
        $container = new ContainerBuilder();
        $bundle->build($container);
        $passes = array_map(static fn (object $p): string => $p::class, $container->getCompilerPassConfig()->getBeforeOptimizationPasses());
        self::assertContains(ActionHandlerPass::class, $passes);
        self::assertContains(CsrfPass::class, $passes);
        self::assertSame('nowo_slack_kit', (new NowoSlackKitExtension())->getAlias());
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, string> $missing
     * @param array<string, mixed> $params kernel parameters (kernel.debug, kernel.environment)
     */
    private function load(array $config, array $missing = [], array $params = []): ContainerBuilder
    {
        $container = new ContainerBuilder(new EnvPlaceholderParameterBag(['kernel.secret' => 'test-secret-not-real'] + $params));
        $extension = new NowoSlackKitExtension(
            $missing[0] ?? InstanceSettingsInterface::class,
            $missing[1] ?? MessageBusInterface::class,
            $missing[2] ?? TransportFactoryInterface::class,
        );
        $container->registerExtension($extension);
        $resolved = $container->getParameterBag()->resolveValue($config);
        $extension->load([is_array($resolved) ? $resolved : []], $container);

        return $container;
    }
}
