<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Credentials;

use DateTimeImmutable;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Installations kept in memory (tests and demos with fake credentials only). Not for production: nothing survives
 * the process. Lookups without a workspace return the app's first installation.
 */
final class InMemoryInstallationStore implements SlackInstallationStoreInterface, ResetInterface
{
    /** @var array<string, SlackInstallation> */
    private array $installations = [];

    /** @var array<string, SlackSecret> */
    private array $signingSecrets = [];

    /**
     * @param array<string, SlackSecret> $initialSigningSecrets signing secret per app, restored by {@see self::reset()}
     * @param list<SlackInstallation> $initial installations restored by {@see self::reset()}
     */
    public function __construct(
        private readonly array $initialSigningSecrets = [],
        private readonly array $initial = [],
    ) {
        $this->reset();
    }

    /**
     * Back to the initial installations and signing secrets.
     */
    public function reset(): void
    {
        $this->installations  = [];
        $this->signingSecrets = $this->initialSigningSecrets;
        foreach ($this->initial as $installation) {
            $this->save($installation);
        }
    }

    /**
     * The installation of the key; without a workspace, the app's first one.
     */
    public function find(SlackInstallationKey $key): ?SlackInstallation
    {
        if (isset($this->installations[$key->key()])) {
            return $this->installations[$key->key()];
        }
        if (!$key->team instanceof SlackTeamId) {
            foreach ($this->installations as $installation) {
                if ($installation->key->app === $key->app) {
                    return $installation;
                }
            }
        }

        return null;
    }

    /**
     * The app's signing secret.
     */
    public function findSigningSecret(string $app): ?SlackSecret
    {
        return $this->signingSecrets[$app] ?? null;
    }

    /**
     * Stores the installation under its key (and its workspace).
     */
    public function save(SlackInstallation $installation): void
    {
        $key = $installation->teamId instanceof SlackTeamId ? new SlackInstallationKey($installation->key->app, $installation->teamId) : $installation->key;
        if ($installation->teamId instanceof SlackTeamId) {
            // The workspace is now known: the app's workspace-less entry (filled by *Test connection*) is replaced.
            unset($this->installations[$installation->key->app]);
        }
        // @igor-ignore - In-memory store for tests and demos; reset() restores the initial state between requests.
        $this->installations[$key->key()] = new SlackInstallation($key, $installation->botToken, $installation->teamId, $installation->allowEmailLookup, $installation->botUserId, $installation->revokedAt);
    }

    /**
     * Sets `revokedAt` once.
     */
    public function markRevoked(SlackInstallationKey $key, DateTimeImmutable $at): void
    {
        $installation = $this->find($key);
        if (!$installation instanceof SlackInstallation || $installation->isRevoked()) {
            return;
        }
        $this->save(new SlackInstallation($installation->key, $installation->botToken, $installation->teamId, $installation->allowEmailLookup, $installation->botUserId, $at));
    }

    /**
     * Sets the signing secret of an app.
     */
    public function setSigningSecret(string $app, SlackSecret $secret): void
    {
        $this->signingSecrets[$app] = $secret;
    }
}
