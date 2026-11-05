<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Credentials;

use Nowo\SlackKitBundle\Security\SlackSecret;

use function array_key_exists;
use function array_keys;
use function is_string;

/**
 * The configured apps (`nowo_slack_kit.apps`): optional app id and signing secret (environment only). A signing
 * secret missing from the configuration is read from the installation store.
 */
final readonly class SlackAppRegistry
{
    /**
     * @param string $defaultApp `default_app`
     * @param array<string, array{app_id: string|null, signing_secret: string|null}> $apps processed `apps`
     * @param SlackInstallationStoreInterface $store installation store
     */
    public function __construct(
        public string $defaultApp,
        private array $apps,
        private SlackInstallationStoreInterface $store,
    ) {
    }

    /**
     * Whether the app is configured.
     */
    public function has(string $app): bool
    {
        return array_key_exists($app, $this->apps);
    }

    /**
     * Configured app names.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->apps);
    }

    /**
     * The app id that `api_app_id` must match, or null.
     */
    public function appId(string $app): ?string
    {
        $id = $this->apps[$app]['app_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The app's signing secret: configuration first, then the store.
     */
    public function signingSecret(string $app): ?SlackSecret
    {
        if (!$this->has($app)) {
            return null;
        }

        return SlackSecret::trySigningSecret($this->apps[$app]['signing_secret'] ?? null) ?? $this->store->findSigningSecret($app);
    }
}
