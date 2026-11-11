<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\DependencyInjection;

use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function is_string;
use function preg_match;
use function trim;

/**
 * `nowo_slack_kit` configuration tree (contract §14, REQ-SF-006: strict, safe defaults).
 */
final class Configuration implements ConfigurationInterface
{
    /** Extension alias. */
    public const ALIAS = 'nowo_slack_kit';

    /**
     * Builds the tree.
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder(self::ALIAS);
        $root = $tree->getRootNode();

        $root
            ->children()
                ->scalarNode('default_locale')->defaultValue('en')->cannotBeEmpty()->info('Locale of Slack-side texts when the account has none.')->end()
                ->arrayNode('api')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->floatNode('timeout')->defaultValue(5.0)->min(1.0)->max(30.0)->info('Idle timeout of Slack calls (REQ-RUNTIME-001).')->end()
                        ->floatNode('max_duration')->defaultValue(10.0)->min(1.0)->max(30.0)->info('Total duration cap of a Slack call.')->end()
                        ->integerNode('max_response_bytes')->defaultValue(1_048_576)->min(65_536)->max(8_388_608)->end()
                    ->end()
                ->end()
                ->scalarNode('default_app')->defaultValue(SlackInstallationKey::DEFAULT_APP)
                    ->validate()->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match(SlackInstallationKey::APP_PATTERN, $v) !== 1)->thenInvalid('default_app must match ^[a-z0-9_]{1,32}$.')->end()
                ->end()
                ->arrayNode('apps')
                    ->info('Slack apps by name (REQ-CFG-001). Secrets only as %env()% placeholders.')
                    ->useAttributeAsKey('name')
                    ->defaultValue([SlackInstallationKey::DEFAULT_APP => ['app_id' => null, 'signing_secret' => null]])
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('app_id')->defaultNull()->info('When set, api_app_id of every request must match.')->end()
                            ->scalarNode('signing_secret')->defaultNull()->info('%env(SLACK_SIGNING_SECRET)% or null (read from the installation store).')->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('credentials')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('store')->defaultValue('instance_settings')->cannotBeEmpty()->info('instance_settings | memory (tests and demos only, never in production: refused outside kernel.debug and the test env) | <service id implementing SlackInstallationStoreInterface>.')->end()
                        ->scalarNode('settings_section')->defaultValue('slack')->cannotBeEmpty()->end()
                    ->end()
                ->end()
                ->arrayNode('ingress')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('prefix')->defaultValue('/slack')->cannotBeEmpty()->end()
                        ->scalarNode('interactions_path')->defaultNull()->info('Full path override, e.g. /hooks/slack/interactions.')->end()
                        ->scalarNode('events_path')->defaultNull()->end()
                        ->booleanNode('events_enabled')->defaultFalse()->end()
                        ->integerNode('max_body_bytes')->defaultValue(1_048_576)->min(16_384)->max(4_194_304)->end()
                        ->integerNode('max_skew_seconds')->defaultValue(300)->min(30)->max(300)->end()
                        ->scalarNode('replay_lock_factory')->defaultValue('lock.factory')->cannotBeEmpty()->info('symfony/lock LockFactory service of the atomic replay memory; use a Redis or DBAL store in production (local stores fail the build outside debug and test).')->end()
                        ->integerNode('max_secret_candidates')->defaultValue(2)->min(1)->max(5)->end()
                        ->scalarNode('signing_secret_resolver')->defaultNull()->info('Service id implementing SigningSecretResolverInterface.')->end()
                        ->booleanNode('resolver_actors')->defaultFalse()->info('Resolve actors for clicks verified with a host secret bound to the click workspace (ResolvedSigningSecret). Trusts every holder of every such secret.')->end()
                        ->booleanNode('async')->defaultFalse()->info('Dispatch Events API callbacks to Messenger (requires symfony/messenger).')->end()
                        ->scalarNode('transport')->defaultValue('async')->cannotBeEmpty()->end()
                    ->end()
                ->end()
                ->arrayNode('interactions')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('value_ttl')->defaultValue('P30D')->info('Lifetime of signed button values (ISO-8601 duration).')
                            ->validate()->ifTrue(static fn (mixed $v): bool => !self::isDuration($v))->thenInvalid('value_ttl must be an ISO-8601 duration such as P30D.')->end()
                        ->end()
                        ->arrayNode('previous_secrets')->info('Earlier kernel.secret values (%env()% only): verify-only keys after a rotation.')->scalarPrototype()->end()->end()
                        ->scalarNode('legacy_value_key')->defaultNull()->info('Key of the action name in legacy unsigned JSON values (Beacon: a).')->end()
                        ->arrayNode('legacy_action_map')->useAttributeAsKey('name')->scalarPrototype()->end()->end()
                        ->scalarNode('legacy_until')->defaultNull()->info('Strict ISO-8601 UTC instant (2026-12-05T00:00:00Z) or %env()%; legacy values are refused from then on. Anything else disables legacy mode.')->end()
                    ->end()
                ->end()
                ->arrayNode('linking')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->scalarNode('identity_store')->defaultNull()->info('Service id implementing SlackIdentityStoreInterface.')->end()
                        ->scalarNode('account_resolver')->defaultNull()->info('Service id implementing SlackAccountResolverInterface.')->end()
                        ->scalarNode('claim_ttl')->defaultValue('PT24H')
                            ->validate()->ifTrue(static fn (mixed $v): bool => !self::isDuration($v))->thenInvalid('claim_ttl must be an ISO-8601 duration.')->end()
                        ->end()
                        ->scalarNode('confirmation_ttl')->defaultValue('PT24H')
                            ->validate()->ifTrue(static fn (mixed $v): bool => !self::isDuration($v))->thenInvalid('confirmation_ttl must be an ISO-8601 duration.')->end()
                        ->end()
                        ->booleanNode('email_lookup')->defaultFalse()->end()
                        ->scalarNode('return_route')->defaultNull()->info('Route the account link actions redirect to (null: /).')->end()
                        ->scalarNode('rate_limiter_cache_pool')->defaultValue('cache.app')->cannotBeEmpty()
                            ->info('Cache pool service id that stores the linking limiter counters (for example cache.rate_limiter, or a persistent pool in tests).')
                            ->validate()->ifTrue(static fn (mixed $v): bool => !is_string($v) || ltrim($v, '@') === '')->thenInvalid('rate_limiter_cache_pool must be a cache pool service id.')->end()
                        ->end()
                        ->append($this->limit('claim_per_account', 10, '1 hour'))
                        ->append($this->limit('email_lookup_per_account', 5, '1 hour'))
                        ->append($this->limit('confirmation_per_account', 3, '1 hour'))
                        ->append($this->limit('confirmation_per_target', 3, '1 hour'))
                    ->end()
                ->end()
                ->arrayNode('test_connection')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->append($this->limit('rate_limit', 5, '1 minute'))
                    ->end()
                ->end()
                ->arrayNode('rate_limit')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_retry_after')->defaultValue(300)->min(1)->max(3600)->end()
                        ->integerNode('max_attempts')->defaultValue(3)->min(1)->max(20)->info('Deliveries of a rate-limited Messenger message before the retry middleware gives up.')->end()
                    ->end()
                ->end()
                ->arrayNode('notifier')
                    ->addDefaultsIfNotSet()
                    ->children()->booleanNode('enabled')->defaultFalse()->end()->end()
                ->end()
                ->arrayNode('ui')
                    ->addDefaultsIfNotSet()
                    ->children()->scalarNode('prefix')->defaultValue('/slack-kit')->cannotBeEmpty()->end()->end()
                ->end()
            ->end();

        return $tree;
    }

    /**
     * Whether the value is an ISO-8601 duration.
     */
    public static function isDuration(mixed $value): bool
    {
        return is_string($value) && preg_match('/^P(?!$)(\d+Y)?(\d+M)?(\d+W)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+S)?)?$/', trim($value)) === 1;
    }

    private function limit(string $name, int $limit, string $interval): ArrayNodeDefinition
    {
        $node = (new TreeBuilder($name))->getRootNode();
        $node->addDefaultsIfNotSet()
            ->children()
                ->integerNode('limit')->defaultValue($limit)->min(1)->end()
                ->scalarNode('interval')->defaultValue($interval)->cannotBeEmpty()->end()
            ->end();

        return $node;
    }
}
