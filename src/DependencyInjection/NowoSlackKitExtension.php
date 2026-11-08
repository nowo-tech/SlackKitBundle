<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\DependencyInjection;

use DateInterval;
use DateTimeImmutable;
use LogicException;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\SlackKitBundle\Api\ApiPolicy;
use Nowo\SlackKitBundle\Api\ClientMemory;
use Nowo\SlackKitBundle\Api\IncomingWebhookClient;
use Nowo\SlackKitBundle\Api\SlackApiClient;
use Nowo\SlackKitBundle\Api\SlackApiClientInterface;
use Nowo\SlackKitBundle\Controller\AccountLinkController;
use Nowo\SlackKitBundle\Controller\TestConnectionController;
use Nowo\SlackKitBundle\Credentials\InMemoryInstallationStore;
use Nowo\SlackKitBundle\Credentials\InstanceSettingsInstallationStore;
use Nowo\SlackKitBundle\Credentials\SlackAppRegistry;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Credentials\SlackInstallationStoreInterface;
use Nowo\SlackKitBundle\Ingress\EventsHandler;
use Nowo\SlackKitBundle\Ingress\HandleSlackEvent;
use Nowo\SlackKitBundle\Ingress\IngressController;
use Nowo\SlackKitBundle\Ingress\ReplayGuard;
use Nowo\SlackKitBundle\Ingress\SignatureVerifier;
use Nowo\SlackKitBundle\Interaction\ActorResolver;
use Nowo\SlackKitBundle\Interaction\AsSlackActionHandler;
use Nowo\SlackKitBundle\Interaction\ButtonValueCodec;
use Nowo\SlackKitBundle\Interaction\InteractionRouter;
use Nowo\SlackKitBundle\Interaction\LegacyValuePolicy;
use Nowo\SlackKitBundle\Interaction\ReplyDispatcher;
use Nowo\SlackKitBundle\Linking\AccountLinker;
use Nowo\SlackKitBundle\Linking\ConfirmLinkHandler;
use Nowo\SlackKitBundle\Linking\SlackAccountResolverInterface;
use Nowo\SlackKitBundle\Linking\SlackIdentityStoreInterface;
use Nowo\SlackKitBundle\Messenger\RateLimitRetryMiddleware;
use Nowo\SlackKitBundle\Notifier\SlackKitTransportFactory;
use Nowo\SlackKitBundle\NowoSlackKitBundle;
use Nowo\SlackKitBundle\Routing\SlackKitRouteLoader;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Nowo\SlackKitBundle\Settings\SlackSettingsNoticeProviderInterface;
use Nowo\SlackKitBundle\Settings\SlackSettingsSection;
use Nowo\SlackKitBundle\Twig\SlackKitTwigExtension;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\ParameterBag\EnvPlaceholderParameterBag;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Notifier\Transport\TransportFactoryInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function array_values;
use function in_array;
use function interface_exists;
use function is_bool;
use function is_string;
use function sprintf;
use function str_contains;
use function trim;

/**
 * Loads `nowo_slack_kit`: credentials store, Web API client, signed ingress, button-value codec, interactions router,
 * account linking, settings section, routes, Twig, and the optional Notifier transport and Messenger middleware.
 */
final class NowoSlackKitExtension extends Extension implements PrependExtensionInterface
{
    /** Service id of the rate-limit retry middleware. */
    public const RETRY_MIDDLEWARE = 'nowo_slack_kit.messenger.rate_limit_retry';

    /** Service id of the logger-free HttpClient of incoming webhooks. */
    public const WEBHOOK_HTTP_CLIENT = 'nowo_slack_kit.webhook_http_client';

    /** Service id of the button key. */
    public const BUTTON_KEY = 'nowo_slack_kit.button_key';

    /**
     * @param string $instanceSettingsInterface InstanceSettings interface (overridable in tests)
     * @param string $messageBusInterface Messenger bus interface (overridable in tests)
     * @param string $notifierFactoryInterface Notifier transport factory interface (overridable in tests)
     */
    public function __construct(
        private readonly string $instanceSettingsInterface = InstanceSettingsInterface::class,
        private readonly string $messageBusInterface = MessageBusInterface::class,
        private readonly string $notifierFactoryInterface = TransportFactoryInterface::class,
    ) {
    }

    /**
     * `nowo_slack_kit`.
     */
    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /**
     * Registers the `@NowoSlackKitBundle` Twig namespace (REQ-TWIG-002).
     */
    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('twig')) {
            $container->prependExtensionConfig('twig', ['paths' => [__DIR__ . '/../Resources/views' => 'NowoSlackKitBundle']]);
        }
    }

    /**
     * Registers the services from the processed configuration.
     *
     * @param array<array<string, mixed>> $configs raw configuration blocks
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{default_locale: string, api: array{timeout: float, max_duration: float, max_response_bytes: int}, default_app: string, apps: array<string, array{app_id: string|null, signing_secret: string|null}>, credentials: array{store: string, settings_section: string}, ingress: array{enabled: bool, prefix: string, interactions_path: string|null, events_path: string|null, events_enabled: bool, max_body_bytes: int, max_skew_seconds: int, replay_lock_factory: string, max_secret_candidates: int, signing_secret_resolver: string|null, resolver_actors: bool, async: bool, transport: string}, interactions: array{value_ttl: string, previous_secrets: list<string>, legacy_value_key: string|null, legacy_action_map: array<string, string>, legacy_until: string|null}, linking: array{enabled: bool, identity_store: string|null, account_resolver: string|null, claim_ttl: string, confirmation_ttl: string, email_lookup: bool, return_route: string|null, rate_limiter_cache_pool: string, claim_per_account: array{limit: int, interval: string}, email_lookup_per_account: array{limit: int, interval: string}, confirmation_per_account: array{limit: int, interval: string}, confirmation_per_target: array{limit: int, interval: string}}, test_connection: array{enabled: bool, rate_limit: array{limit: int, interval: string}}, rate_limit: array{max_retry_after: int, max_attempts: int}, notifier: array{enabled: bool}, ui: array{prefix: string}} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);
        $this->assertLiteralBooleans($config);
        $this->assertSecretsFromEnvironment($container, $config);
        if (!isset($config['apps'][$config['default_app']])) {
            throw new InvalidConfigurationException(sprintf('nowo_slack_kit.default_app "%s" is not a key of nowo_slack_kit.apps.', $config['default_app']));
        }

        $container->registerAttributeForAutoconfiguration(AsSlackActionHandler::class, static function (ChildDefinition $definition, AsSlackActionHandler $attribute): void {
            $definition->addTag(NowoSlackKitBundle::HANDLER_TAG, ['action' => $attribute->action, 'requires_actor' => $attribute->requiresActor]);
        });
        $container->registerForAutoconfiguration(SlackSettingsNoticeProviderInterface::class)->addTag(NowoSlackKitBundle::NOTICE_TAG);

        $instanceSettings = $this->registerStore($container, $config['credentials'], $config['default_app']);
        $this->registerClient($container, $config);
        $this->registerInteractions($container, $config);
        $ingress = $this->registerIngress($container, $config);
        $this->registerLinking($container, $config);
        $testConnection = $instanceSettings && $config['test_connection']['enabled'];
        $this->registerSettings($container, $config, $instanceSettings, $testConnection, $ingress['enabled'] ? IngressController::INTERACTIONS_ROUTE : null);

        $container->setDefinition(SlackKitRouteLoader::class, (new Definition(SlackKitRouteLoader::class, [
            $ingress,
            $config['ui']['prefix'],
            $testConnection,
            $config['linking']['enabled'],
        ]))->addTag('routing.loader'));

        $container->setDefinition(SlackKitTwigExtension::class, (new Definition(SlackKitTwigExtension::class, [
            new Reference('router'),
            $config['linking']['enabled'] ? new Reference(AccountLinker::class) : null,
            $config['linking']['enabled'] ? new Reference((string) $config['linking']['account_resolver']) : null,
            new Reference('form.factory', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference(CsrfOnlyFormFactory::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->addTag('twig.extension'));

        if ($config['notifier']['enabled']) {
            if (!interface_exists($this->notifierFactoryInterface)) {
                throw new LogicException('NowoSlackKitBundle: nowo_slack_kit.notifier.enabled requires symfony/notifier.');
            }
            $container->setDefinition(SlackKitTransportFactory::class, (new Definition(SlackKitTransportFactory::class, [new Reference(SlackApiClientInterface::class), $config['default_app']]))
                ->addTag('chatter.transport_factory'));
        }
        if (interface_exists($this->messageBusInterface)) {
            $container->setDefinition(self::RETRY_MIDDLEWARE, new Definition(RateLimitRetryMiddleware::class, [$config['rate_limit']['max_attempts']]));
        }
    }

    /**
     * @param array{store: string, settings_section: string} $credentials
     *
     * @return bool whether the InstanceSettings store is used
     */
    private function registerStore(ContainerBuilder $container, array $credentials, string $defaultApp): bool
    {
        $store = $credentials['store'];
        if ($store === 'instance_settings') {
            if (!interface_exists($this->instanceSettingsInterface)) {
                throw new LogicException('NowoSlackKitBundle: credentials.store: instance_settings requires nowo-tech/instance-settings-bundle (or set credentials.store to your SlackInstallationStoreInterface service).');
            }
            $container->setDefinition(InstanceSettingsInstallationStore::class, new Definition(InstanceSettingsInstallationStore::class, [
                new Reference(InstanceSettingsInterface::class),
                $credentials['settings_section'],
                $defaultApp,
            ]));
            $container->setAlias(SlackInstallationStoreInterface::class, InstanceSettingsInstallationStore::class)->setPublic(true);

            return true;
        }
        if ($store === 'memory') {
            $container->setDefinition(InMemoryInstallationStore::class, (new Definition(InMemoryInstallationStore::class))->addTag('kernel.reset', ['method' => 'reset']));
            $container->setAlias(SlackInstallationStoreInterface::class, InMemoryInstallationStore::class)->setPublic(true);

            return false;
        }
        $container->setAlias(SlackInstallationStoreInterface::class, $store)->setPublic(true);

        return false;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerClient(ContainerBuilder $container, array $config): void
    {
        /* @var array{default_app: string, apps: array<string, array{app_id: string|null, signing_secret: string|null}>, api: array{timeout: float, max_duration: float, max_response_bytes: int}, rate_limit: array{max_retry_after: int, max_attempts: int}} $config */
        $container->setDefinition(SlackAppRegistry::class, new Definition(SlackAppRegistry::class, [
            $config['default_app'],
            $config['apps'],
            new Reference(SlackInstallationStoreInterface::class),
        ]));
        $container->setDefinition(ApiPolicy::class, new Definition(ApiPolicy::class, [
            $config['api']['timeout'],
            $config['api']['max_duration'],
            $config['api']['max_response_bytes'],
            $config['rate_limit']['max_retry_after'],
        ]));
        $container->setDefinition(ClientMemory::class, new Definition(ClientMemory::class, [new Reference('cache.app')]));
        $container->setDefinition(SlackApiClient::class, (new Definition(SlackApiClient::class, [
            new Reference('http_client'),
            new Reference(SlackInstallationStoreInterface::class),
            new Reference(ApiPolicy::class),
            new Reference(ClientMemory::class),
            new Reference('clock'),
            new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->addTag('monolog.logger', ['channel' => 'slack']));
        $container->setAlias(SlackApiClientInterface::class, SlackApiClient::class)->setPublic(true);
        // Audit FS-L2: the webhook URL is a bearer secret; its client has no logger and is not traced by the profiler.
        $container->setDefinition(self::WEBHOOK_HTTP_CLIENT, (new Definition(HttpClientInterface::class))->setFactory([HttpClient::class, 'create']));
        $container->setDefinition(IncomingWebhookClient::class, (new Definition(IncomingWebhookClient::class, [
            new Reference(self::WEBHOOK_HTTP_CLIENT),
            new Reference(ApiPolicy::class),
        ]))->setPublic(true));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerInteractions(ContainerBuilder $container, array $config): void
    {
        /** @var array{default_locale: string, interactions: array{value_ttl: string, previous_secrets: list<string>, legacy_value_key: string|null, legacy_action_map: array<string, string>, legacy_until: string|null}, linking: array{enabled: bool, identity_store: string|null}} $config */
        $interactions = $config['interactions'];
        $container->setDefinition(self::BUTTON_KEY, (new Definition())->setClass(SlackSecret::class)->setFactory([ButtonValueCodec::class, 'deriveCheckedKey'])->setArguments(['%kernel.secret%', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE)]));
        $previous = [];
        foreach (array_values($interactions['previous_secrets']) as $i => $secret) {
            $id = self::BUTTON_KEY . '.previous.' . $i;
            $container->setDefinition($id, (new Definition())->setClass(SlackSecret::class)->setFactory([ButtonValueCodec::class, 'deriveKey'])->setArguments([$secret]));
            $previous[] = new Reference($id);
        }
        $container->setDefinition(ButtonValueCodec::class, (new Definition(ButtonValueCodec::class, [
            new Reference(self::BUTTON_KEY),
            $previous,
            new Reference('clock'),
            $this->seconds($interactions['value_ttl']),
        ]))->setPublic(true));

        $until = null;
        if ($interactions['legacy_until'] !== null) {
            // Audit FS-L4: a strict UTC instant only (a relative string would roll forward on every boot). An
            // %env()% value is parsed at runtime by the same rule; anything else there turns legacy mode off.
            if (!$this->isEnvPlaceholder($container, $interactions['legacy_until']) && !LegacyValuePolicy::parseUntil($interactions['legacy_until']) instanceof DateTimeImmutable) {
                throw new InvalidConfigurationException('nowo_slack_kit.interactions.legacy_until must be a UTC instant such as 2026-12-05T00:00:00Z.');
            }
            $until = $interactions['legacy_until'];
        }
        if (in_array(AccountLinker::CONFIRM_ACTION, $interactions['legacy_action_map'], true)) {
            // Audit FS-I4: confirmations are bound to the claim time; unsigned ones are never accepted.
            throw new InvalidConfigurationException('nowo_slack_kit.interactions.legacy_action_map cannot map to nowo_slack_kit.link.confirm.');
        }
        $key = $interactions['legacy_value_key'];
        if ($key !== null && ($until === null || $interactions['legacy_action_map'] === [])) {
            throw new InvalidConfigurationException('nowo_slack_kit.interactions.legacy_value_key needs legacy_until and legacy_action_map (legacy mode is always time-boxed).');
        }
        $container->setParameter('nowo_slack_kit.interactions.legacy_action_map', $key !== null ? $interactions['legacy_action_map'] : []);
        $container->setDefinition(LegacyValuePolicy::class, new Definition(LegacyValuePolicy::class, [
            $key,
            $interactions['legacy_action_map'],
            $until,
            new Reference('clock'),
        ]));

        $identities = $config['linking']['enabled'] && is_string($config['linking']['identity_store']) ? new Reference($config['linking']['identity_store']) : null;
        $container->setDefinition(ActorResolver::class, new Definition(ActorResolver::class, [$identities]));
        $container->setDefinition(ReplyDispatcher::class, (new Definition(ReplyDispatcher::class, [
            new Reference(SlackApiClientInterface::class),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))
            ->addTag('monolog.logger', ['channel' => 'slack'])
            ->addTag('kernel.reset', ['method' => 'reset'])
            ->addTag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'flush'])
            ->addTag('kernel.event_listener', ['event' => 'console.terminate', 'method' => 'flush']));
        $container->setDefinition(InteractionRouter::class, (new Definition(InteractionRouter::class, [
            new ServiceLocatorArgument([]),
            [],
            new Reference(ButtonValueCodec::class),
            new Reference(LegacyValuePolicy::class),
            new Reference(ActorResolver::class),
            new Reference(ReplyDispatcher::class),
            new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $config['default_locale'],
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->addTag('monolog.logger', ['channel' => 'slack']));
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{enabled: bool, interactions: string, events: string|null}
     */
    private function registerIngress(ContainerBuilder $container, array $config): array
    {
        /** @var array{ingress: array{enabled: bool, prefix: string, interactions_path: string|null, events_path: string|null, events_enabled: bool, max_body_bytes: int, max_skew_seconds: int, replay_lock_factory: string, max_secret_candidates: int, signing_secret_resolver: string|null, resolver_actors: bool, async: bool, transport: string}} $config */
        $ingress = $config['ingress'];
        $prefix  = '/' . trim($ingress['prefix'], '/');
        $paths   = [
            'enabled'      => $ingress['enabled'],
            'interactions' => $ingress['interactions_path'] ?? $prefix . '/interactions',
            'events'       => $ingress['events_enabled'] ? ($ingress['events_path'] ?? $prefix . '/events') : null,
        ];
        if (!$ingress['enabled']) {
            return $paths;
        }
        if ($ingress['async'] && !interface_exists($this->messageBusInterface)) {
            throw new LogicException('NowoSlackKitBundle: nowo_slack_kit.ingress.async requires symfony/messenger.');
        }

        $container->setDefinition(SignatureVerifier::class, new Definition(SignatureVerifier::class, [new Reference('clock'), $ingress['max_skew_seconds']]));
        $container->setDefinition(ReplayGuard::class, new Definition(ReplayGuard::class, [new Reference($ingress['replay_lock_factory']), $ingress['max_skew_seconds'], null, false]));
        $events = (new Definition(EventsHandler::class, [
            new Reference(SlackInstallationStoreInterface::class),
            new Reference('event_dispatcher'),
            new Reference('clock'),
        ]));
        if ($ingress['async']) {
            $events->addTag('messenger.message_handler', ['handles' => HandleSlackEvent::class]);
        }
        $container->setDefinition(EventsHandler::class, $events);
        $container->setDefinition(IngressController::class, (new Definition(IngressController::class, [
            new Reference(SignatureVerifier::class),
            new Reference(ReplayGuard::class),
            new Reference(SlackAppRegistry::class),
            new Reference(SlackInstallationStoreInterface::class),
            new Reference(InteractionRouter::class),
            new Reference(EventsHandler::class),
            new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            is_string($ingress['signing_secret_resolver']) ? new Reference($ingress['signing_secret_resolver']) : null,
            $ingress['async'] ? new Reference('messenger.default_bus') : null,
            $ingress['transport'],
            $ingress['max_body_bytes'],
            $ingress['max_secret_candidates'],
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $ingress['resolver_actors'],
        ]))
            ->addTag('monolog.logger', ['channel' => 'slack'])
            ->setAutoconfigured(false)
            ->setPublic(true)
            ->addTag('controller.service_arguments'));

        return $paths;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerLinking(ContainerBuilder $container, array $config): void
    {
        /** @var array{default_locale: string, default_app: string, linking: array{enabled: bool, identity_store: string|null, account_resolver: string|null, claim_ttl: string, confirmation_ttl: string, email_lookup: bool, return_route: string|null, rate_limiter_cache_pool: string, claim_per_account: array{limit: int, interval: string}, email_lookup_per_account: array{limit: int, interval: string}, confirmation_per_account: array{limit: int, interval: string}, confirmation_per_target: array{limit: int, interval: string}}} $config */
        $linking = $config['linking'];
        if (!$linking['enabled']) {
            return;
        }
        if (!is_string($linking['identity_store']) || !is_string($linking['account_resolver'])) {
            throw new InvalidConfigurationException('nowo_slack_kit.linking.enabled needs linking.identity_store (SlackIdentityStoreInterface) and linking.account_resolver (SlackAccountResolverInterface) service ids.');
        }
        $container->setAlias(SlackIdentityStoreInterface::class, $linking['identity_store']);
        $container->setAlias(SlackAccountResolverInterface::class, $linking['account_resolver']);

        $limiters = [];
        foreach (['claim_per_account', 'email_lookup_per_account', 'confirmation_per_account', 'confirmation_per_target'] as $name) {
            $limiters[$name] = $this->limiter($container, 'nowo_slack_kit.linking.' . $name, $linking[$name], ltrim($linking['rate_limiter_cache_pool'], '@'));
        }
        $container->setDefinition(AccountLinker::class, (new Definition(AccountLinker::class, [
            new Reference($linking['identity_store']),
            new Reference(SlackInstallationStoreInterface::class),
            new Reference(SlackApiClientInterface::class),
            new Reference(ButtonValueCodec::class),
            new Reference('clock'),
            $limiters,
            $this->seconds($linking['claim_ttl']),
            $this->seconds($linking['confirmation_ttl']),
            $linking['email_lookup'],
            new Definition(SlackInstallationKey::class, [$config['default_app']]),
            new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $config['default_locale'],
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference(LegacyValuePolicy::class),
        ]))->setPublic(true)->addTag('monolog.logger', ['channel' => 'slack']));
        $container->setDefinition(ConfirmLinkHandler::class, (new Definition(ConfirmLinkHandler::class, [new Reference(AccountLinker::class)]))
            ->addTag(NowoSlackKitBundle::HANDLER_TAG, ['action' => AccountLinker::CONFIRM_ACTION, 'requires_actor' => false]));
        $container->setDefinition(AccountLinkController::class, (new Definition(AccountLinkController::class, [
            new Reference(AccountLinker::class),
            new Reference($linking['account_resolver']),
            new Reference('form.factory'),
            new Reference(CsrfOnlyFormFactory::class),
            new Reference('router'),
            $linking['return_route'],
            new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->setAutoconfigured(false)->setPublic(true)->addTag('controller.service_arguments'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerSettings(ContainerBuilder $container, array $config, bool $instanceSettings, bool $testConnection, ?string $interactivityRoute): void
    {
        /* @var array{credentials: array{settings_section: string}, test_connection: array{rate_limit: array{limit: int, interval: string}}} $config */
        if (!$instanceSettings) {
            return;
        }
        $section = $config['credentials']['settings_section'];
        $container->setDefinition(SlackSettingsSection::class, (new Definition(SlackSettingsSection::class, [
            $section,
            new Reference(SlackInstallationStoreInterface::class),
            new TaggedIteratorArgument(NowoSlackKitBundle::NOTICE_TAG),
            new Reference('router', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference(CsrfOnlyFormFactory::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $testConnection,
            $interactivityRoute,
        ]))->addTag(NowoInstanceSettingsBundle::SECTION_TAG));
        if (!$testConnection) {
            return;
        }
        $container->setDefinition(TestConnectionController::class, (new Definition(TestConnectionController::class, [
            new Reference(InstanceSettingsAccessCheckerInterface::class),
            new Reference(CsrfOnlyFormFactory::class),
            new Reference(SlackApiClientInterface::class),
            new Reference(SlackInstallationStoreInterface::class),
            $this->limiter($container, 'nowo_slack_kit.test_connection.rate_limiter', $config['test_connection']['rate_limit']),
            new Reference('router'),
            $section,
            new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->setAutoconfigured(false)->setPublic(true)->addTag('controller.service_arguments'));
    }

    /**
     * @param array{limit: int, interval: string} $budget
     * @param string $cachePool cache pool service id behind the limiter storage
     */
    private function limiter(ContainerBuilder $container, string $id, array $budget, string $cachePool = 'cache.app'): Reference
    {
        $container->setDefinition($id . '.storage', new Definition(CacheStorage::class, [new Reference($cachePool)]));
        $container->setDefinition($id, new Definition(RateLimiterFactory::class, [
            ['id' => $id, 'policy' => 'fixed_window', 'limit' => $budget['limit'], 'interval' => $budget['interval']],
            new Reference($id . '.storage'),
        ]));

        return new Reference($id);
    }

    /**
     * Seconds of an ISO-8601 duration (months as 30 days, years as 365).
     */
    private function seconds(string $duration): int
    {
        $i = new DateInterval(trim($duration));

        return (($i->y * 365 + $i->m * 30 + (int) $i->d) * 24 + $i->h) * 3600 + $i->i * 60 + $i->s;
    }

    /**
     * Security switches must be literal booleans (an `%env(bool:…)%` placeholder reads as true while building).
     *
     * @param array<string, mixed> $config
     */
    private function assertLiteralBooleans(array $config): void
    {
        /** @var array<string, array<string, mixed>> $config */
        $switches = [
            'ingress.enabled'         => $config['ingress']['enabled'] ?? null,
            'ingress.events_enabled'  => $config['ingress']['events_enabled'] ?? null,
            'ingress.async'           => $config['ingress']['async'] ?? null,
            'linking.enabled'         => $config['linking']['enabled'] ?? null,
            'linking.email_lookup'    => $config['linking']['email_lookup'] ?? null,
            'test_connection.enabled' => $config['test_connection']['enabled'] ?? null,
            'notifier.enabled'        => $config['notifier']['enabled'] ?? null,
        ];
        foreach ($switches as $path => $value) {
            if (!is_bool($value)) {
                throw new InvalidConfigurationException(sprintf('nowo_slack_kit.%s must be a literal boolean (true or false); environment placeholders are not supported for security switches.', $path));
            }
        }
    }

    /**
     * Secrets in the configuration must be `%env()%` placeholders: a literal would end up in VCS.
     *
     * @param array<string, mixed> $config
     */
    private function assertSecretsFromEnvironment(ContainerBuilder $container, array $config): void
    {
        /** @var array{apps: array<string, array{signing_secret: string|null}>, interactions: array{previous_secrets: list<string>}} $config */
        $values = [];
        foreach ($config['apps'] as $name => $app) {
            $values['apps.' . $name . '.signing_secret'] = $app['signing_secret'];
        }
        foreach ($config['interactions']['previous_secrets'] as $i => $secret) {
            $values['interactions.previous_secrets.' . $i] = $secret;
        }
        foreach ($values as $path => $value) {
            if ($value === null) {
                continue;
            }
            if (!$this->isEnvPlaceholder($container, $value)) {
                throw new InvalidConfigurationException(sprintf('nowo_slack_kit: the secret at "%s" must come from the environment (%%env(...)%%), never a literal in configuration.', $path));
            }
        }
    }

    /**
     * Whether the value contains an `%env()%` placeholder of this container.
     */
    private function isEnvPlaceholder(ContainerBuilder $container, string $value): bool
    {
        $bag = $container->getParameterBag();
        if (!$bag instanceof EnvPlaceholderParameterBag) {
            return false;
        }
        foreach ($bag->getEnvPlaceholders() as $list) {
            foreach ($list as $placeholder) {
                if (is_string($placeholder) && str_contains($value, $placeholder)) {
                    return true;
                }
            }
        }

        return false;
    }
}
