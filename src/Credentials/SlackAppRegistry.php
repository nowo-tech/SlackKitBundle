<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Credentials;

use InvalidArgumentException;
use Nowo\SlackKitBundle\Security\SlackSecret;

use function array_key_exists;
use function array_keys;
use function is_string;

/**
 * The configured apps (`nowo_slack_kit.apps`): optional app id and signing secret (environment only), and since 1.3
 * the OAuth client id and client secret (environment only) of an app distributed with "Add to Slack". A signing
 * secret missing from the configuration is read from the installation store.
 */
final readonly class SlackAppRegistry
{
    /**
     * @param string $defaultApp `default_app`
     * @param array<string, array{app_id: string|null, signing_secret: string|null, client_id?: string|null, client_secret?: string|null}> $apps processed `apps`
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
     * The app's OAuth client id, or null.
     */
    public function clientId(string $app): ?string
    {
        $id = $this->apps[$app]['client_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The app's OAuth client secret, or null when it is missing or malformed.
     */
    public function clientSecret(string $app): ?SlackSecret
    {
        $secret = $this->apps[$app]['client_secret'] ?? null;
        if (!is_string($secret) || $secret === '') {
            return null;
        }
        try {
            return SlackSecret::clientSecret($secret);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Whether "Add to Slack" can run for the app: app id, client id and client secret are set.
     */
    public function canInstall(string $app): bool
    {
        return $this->appId($app) !== null && $this->clientId($app) !== null && $this->clientSecret($app) instanceof SlackSecret;
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
