<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Credentials;

use Nowo\SlackKitBundle\Credentials\InMemoryInstallationStore;
use Nowo\SlackKitBundle\Credentials\SlackInstallationStoreInterface;
use Nowo\SlackKitBundle\Test\SlackMultiInstallationStoreContractTestCase;

/**
 * The shipped multi-installation contract run against the in-memory store.
 */
final class MultiStoreContractTest extends SlackMultiInstallationStoreContractTestCase
{
    protected function createEmptyStore(): SlackInstallationStoreInterface
    {
        return new InMemoryInstallationStore();
    }
}
