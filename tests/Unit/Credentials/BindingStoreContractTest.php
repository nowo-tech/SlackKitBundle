<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Credentials;

use Nowo\SlackKitBundle\Credentials\InMemoryInstallationBindingStore;
use Nowo\SlackKitBundle\Credentials\SlackInstallationBindingStoreInterface;
use Nowo\SlackKitBundle\Test\SlackInstallationBindingStoreContractTestCase;

/**
 * The shipped binding contract run against the in-memory store.
 */
final class BindingStoreContractTest extends SlackInstallationBindingStoreContractTestCase
{
    protected function createEmptyStore(): SlackInstallationBindingStoreInterface
    {
        return new InMemoryInstallationBindingStore();
    }
}
