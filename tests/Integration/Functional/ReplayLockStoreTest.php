<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Integration\Functional;

use LogicException;
use Nowo\SlackKitBundle\Ingress\ReplayGuard;
use Nowo\SlackKitBundle\Tests\Integration\Kernel\KernelTestCase;

/**
 * Audit FS-L1 in a real kernel: outside debug and test, a local lock store (FrameworkBundle's default `flock`) fails
 * the container build; a shared store (Doctrine DBAL) builds.
 */
final class ReplayLockStoreTest extends KernelTestCase
{
    public function testLocalStoreFailsTheProductionBuild(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('replay lock store is local');
        $this->bootKernel([], 'prod');
    }

    public function testSharedStoreBuilds(): void
    {
        $kernel = $this->bootKernel([], 'prod', ['lock' => 'doctrine.dbal.default_connection']);
        self::assertTrue($kernel->getContainer()->has('test.service_container'));
        self::assertTrue($this->container($kernel)->has(ReplayGuard::class));
    }
}
