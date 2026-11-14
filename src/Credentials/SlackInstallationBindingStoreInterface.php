<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Credentials;

use Nowo\SlackKitBundle\Test\SlackInstallationBindingStoreContractTestCase;

/**
 * Where bindings between installations and owner scopes live (spec 237 FR-001; `installations.binding_store`).
 * Implementations MUST compare scopes and installation keys exactly (binary collation on MySQL / MariaDB) and MUST
 * make {@see self::bind()} and {@see self::unbind()} idempotent. Validate an implementation with
 * {@see SlackInstallationBindingStoreContractTestCase}.
 */
interface SlackInstallationBindingStoreInterface
{
    /**
     * Binds the installation to the scope; binding twice keeps one binding.
     */
    public function bind(SlackInstallationBinding $binding): void;

    /**
     * Removes the binding, if any.
     */
    public function unbind(SlackInstallationKey $installation, SlackOwnerScope $scope): void;

    /**
     * Every binding of the installation (who is affected by its revocation).
     *
     * @return list<SlackInstallationBinding>
     */
    public function bindingsOf(SlackInstallationKey $installation): array;

    /**
     * Every binding of the scope (the installations it may use).
     *
     * @return list<SlackInstallationBinding>
     */
    public function bindingsFor(SlackOwnerScope $scope): array;
}
