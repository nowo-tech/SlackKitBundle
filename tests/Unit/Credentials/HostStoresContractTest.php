<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Credentials;

use Nowo\SlackKitBundle\Credentials\SlackInstallationStoreInterface;
use Nowo\SlackKitBundle\Test\SlackMultiInstallationStoreContractTestCase;
use Nowo\SlackKitBundle\Tests\Support\HostInstallationStore;

/**
 * The DDD-host test store (used by the functional tests) honours the multi-installation contract too.
 */
final class HostStoresContractTest extends SlackMultiInstallationStoreContractTestCase
{
    private HostInstallationStore $store;

    protected function createEmptyStore(): SlackInstallationStoreInterface
    {
        return $this->store = new HostInstallationStore();
    }

    protected function rawStorage(): string
    {
        // Stands for the host's encrypted rows: what is "at rest" is the workspace ids only.
        return implode('|', array_keys($this->store->rows));
    }
}
