<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Test;

use DateTimeImmutable;
use Nowo\SlackKitBundle\Credentials\SlackInstallationBinding;
use Nowo\SlackKitBundle\Credentials\SlackInstallationBindingStoreInterface;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Credentials\SlackOwnerScope;
use Nowo\SlackKitBundle\Id\SlackEnterpriseId;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use PHPUnit\Framework\TestCase;

/**
 * The binding store contract (spec 237 FR-001, `installations.binding_store`): extend it in your test suite and return
 * an EMPTY store. Requires phpunit/phpunit (a dev dependency of the host).
 */
abstract class SlackInstallationBindingStoreContractTestCase extends TestCase
{
    /**
     * An empty store under test.
     */
    abstract protected function createEmptyStore(): SlackInstallationBindingStoreInterface;

    /**
     * Bindings are listed per installation and per scope, once, and compared exactly.
     */
    public function testBindingsArePerInstallationAndScope(): void
    {
        $store = $this->createEmptyStore();
        $a     = $this->key('T00000000');
        $b     = $this->key('T00000001');
        $org1  = new SlackOwnerScope('organization', 'org-1');
        $org2  = new SlackOwnerScope('organization', 'org-2');

        $store->bind(new SlackInstallationBinding($a, $org1, new DateTimeImmutable('@1793836800'), 'account-1'));
        $store->bind(new SlackInstallationBinding($a, $org1, new DateTimeImmutable('@1793836801'), 'account-1'));
        $store->bind(new SlackInstallationBinding($a, $org2));
        $store->bind(new SlackInstallationBinding($b, $org2));

        self::assertCount(2, $store->bindingsOf($a), 'Binding twice keeps one binding.');
        self::assertCount(1, $store->bindingsOf($b));
        self::assertCount(1, $store->bindingsFor($org1));
        self::assertCount(2, $store->bindingsFor($org2));
        self::assertSame([], $store->bindingsFor(new SlackOwnerScope('organization', 'ORG-1')), 'Scope ids compare exactly (case-sensitive).');
        self::assertSame([], $store->bindingsFor(new SlackOwnerScope('personal', 'org-1')), 'The scope type is part of the scope.');
        self::assertSame([], $store->bindingsOf(new SlackInstallationKey('other_app', SlackTeamId::fromString('T00000000'))));
        foreach ($store->bindingsFor($org1) as $binding) {
            self::assertTrue($binding->installation->sameWorkspace($a));
            self::assertTrue($binding->scope->equals($org1));
        }
    }

    /**
     * A key with the enterprise finds the bindings made without it (workspace ids are unique) and unbinding is
     * idempotent.
     */
    public function testEnterpriseAndUnbind(): void
    {
        $store = $this->createEmptyStore();
        $plain = $this->key('T00000000');
        $grid  = new SlackInstallationKey(SlackInstallationKey::DEFAULT_APP, SlackTeamId::fromString('T00000000'), SlackEnterpriseId::fromString('E00000000'));
        $org   = new SlackOwnerScope('organization', 'org-1');
        $store->bind(new SlackInstallationBinding($plain, $org));

        self::assertCount(1, $store->bindingsOf($grid));
        $store->unbind($grid, $org);
        $store->unbind($grid, $org);
        self::assertSame([], $store->bindingsOf($plain));
        self::assertSame([], $store->bindingsFor($org));
    }

    private function key(string $team): SlackInstallationKey
    {
        return new SlackInstallationKey(SlackInstallationKey::DEFAULT_APP, SlackTeamId::fromString($team));
    }
}
