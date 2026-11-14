<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Support;

use Nowo\SlackKitBundle\Credentials\SlackInstallationBinding;
use Nowo\SlackKitBundle\Credentials\SlackInstallationBindingStoreInterface;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Credentials\SlackOwnerScope;

use function array_filter;
use function array_values;

/**
 * A DDD host's binding store (stands for the host's own table): rows survive across requests of one kernel.
 */
final class HostBindingStore implements SlackInstallationBindingStoreInterface
{
    /** @var array<string, SlackInstallationBinding> */
    public array $rows = [];

    public function bind(SlackInstallationBinding $binding): void
    {
        $this->rows[$binding->installation->workspaceKey() . '|' . $binding->scope->key()] ??= $binding;
    }

    public function unbind(SlackInstallationKey $installation, SlackOwnerScope $scope): void
    {
        unset($this->rows[$installation->workspaceKey() . '|' . $scope->key()]);
    }

    public function bindingsOf(SlackInstallationKey $installation): array
    {
        return array_values(array_filter($this->rows, static fn (SlackInstallationBinding $b): bool => $b->installation->sameWorkspace($installation)));
    }

    public function bindingsFor(SlackOwnerScope $scope): array
    {
        return array_values(array_filter($this->rows, static fn (SlackInstallationBinding $b): bool => $b->scope->equals($scope)));
    }
}
