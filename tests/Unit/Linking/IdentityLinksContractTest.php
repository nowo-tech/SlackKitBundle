<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Linking;

use Nowo\SlackKitBundle\Linking\SlackIdentityLinksInterface;
use Nowo\SlackKitBundle\Test\SlackIdentityLinksContractTestCase;
use Nowo\SlackKitBundle\Tests\Support\FakeIdentityLinks;

/**
 * The shipped identity contract run against the test host store.
 */
final class IdentityLinksContractTest extends SlackIdentityLinksContractTestCase
{
    protected function createEmptyStore(): SlackIdentityLinksInterface
    {
        return new FakeIdentityLinks();
    }
}
