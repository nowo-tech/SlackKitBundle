<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Integration\Functional;

use LogicException;
use Nowo\SlackKitBundle\Ingress\ReplayGuard;
use Nowo\SlackKitBundle\Tests\Integration\Kernel\KernelTestCase;

/**
 * Audit FS-L1 / FSD-L1 in a real kernel: outside debug and test, a local or session-scoped lock store (FrameworkBundle's
 * default `flock`, `pgsql+advisory:`) fails the container build; a shared store (Doctrine DBAL) builds.
 */
final class ReplayLockStoreTest extends KernelTestCase
{
    public function testLocalStoreFailsTheProductionBuild(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('replay lock store is not an expiring shared store');
        $this->bootKernel([], 'prod');
    }

    public function testSessionScopedAdvisoryStoreFailsTheProductionBuild(): void
    {
        $this->expectException(LogicException::class);
        $this->bootKernel([], 'prod', ['lock' => 'pgsql+advisory:host=db.example.test;dbname=app']);
    }

    public function testDedicatedSlackKitResourceLeavesTheAppLockAlone(): void
    {
        // Audit FSD-I5: the app keeps flock for its own locks; SlackKit gets its own shared resource.
        $kernel = $this->bootKernel(['ingress' => ['replay_lock_factory' => 'lock.slack_kit.factory']], 'prod', ['lock' => ['resources' => ['default' => ['flock'], 'slack_kit' => ['doctrine.dbal.default_connection']]]]);
        self::assertTrue($this->container($kernel)->has(ReplayGuard::class));
    }

    public function testSharedStoreBuilds(): void
    {
        $kernel = $this->bootKernel([], 'prod', ['lock' => 'doctrine.dbal.default_connection']);
        self::assertTrue($kernel->getContainer()->has('test.service_container'));
        self::assertTrue($this->container($kernel)->has(ReplayGuard::class));
    }
}
