<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Credentials;

use DateTimeImmutable;
use Nowo\SlackKitBundle\Security\SlackSecret;

/**
 * Where installations and signing secrets live (contract §7.2). Implementations MUST encrypt tokens and secrets at
 * rest with a key that is not the database's, MUST return {@see SlackSecret} objects (never strings), MUST scope
 * reads to the given key and MUST make {@see self::markRevoked()} idempotent.
 */
interface SlackInstallationStoreInterface
{
    /**
     * The installation, or null when it is not installed (a revoked installation may be returned with `revokedAt`).
     */
    public function find(SlackInstallationKey $key): ?SlackInstallation;

    /**
     * The signing secret of an app, or null when none is stored.
     */
    public function findSigningSecret(string $app): ?SlackSecret;

    /**
     * Saves an installation (Test connection fills the workspace; OAuth in 1.1).
     */
    public function save(SlackInstallation $installation): void;

    /**
     * Marks the installation revoked (uninstall, `tokens_revoked`). Idempotent.
     */
    public function markRevoked(SlackInstallationKey $key, DateTimeImmutable $at): void;
}
