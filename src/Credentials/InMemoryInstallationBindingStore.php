<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Credentials;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Bindings kept in memory (tests and demos only, with `credentials.store: memory`). Restored to the initial bindings
 * at every request, message and console boundary, like {@see InMemoryInstallationStore}.
 */
final class InMemoryInstallationBindingStore implements SlackInstallationBindingStoreInterface, ResetInterface
{
    /** @var array<string, SlackInstallationBinding> */
    private array $bindings;

    /**
     * @param list<SlackInstallationBinding> $initial bindings restored by {@see self::reset()}
     */
    public function __construct(
        private readonly array $initial = [],
    ) {
        $this->reset();
    }

    /**
     * Back to the initial bindings.
     */
    public function reset(): void
    {
        $this->bindings = [];
        foreach ($this->initial as $binding) {
            $this->bind($binding);
        }
    }

    /**
     * Stores the binding once.
     */
    public function bind(SlackInstallationBinding $binding): void
    {
        // @igor-ignore - In-memory store for tests and demos; reset() restores the initial state at every boundary.
        $this->bindings[$binding->installation->workspaceKey() . '|' . $binding->scope->key()] ??= $binding;
    }

    /**
     * Removes the binding.
     */
    public function unbind(SlackInstallationKey $installation, SlackOwnerScope $scope): void
    {
        unset($this->bindings[$installation->workspaceKey() . '|' . $scope->key()]);
    }

    /**
     * Bindings of the installation.
     *
     * @return list<SlackInstallationBinding>
     */
    public function bindingsOf(SlackInstallationKey $installation): array
    {
        $out = [];
        foreach ($this->bindings as $binding) {
            if ($binding->installation->sameWorkspace($installation)) {
                $out[] = $binding;
            }
        }

        return $out;
    }

    /**
     * Bindings of the scope.
     *
     * @return list<SlackInstallationBinding>
     */
    public function bindingsFor(SlackOwnerScope $scope): array
    {
        $out = [];
        foreach ($this->bindings as $binding) {
            if ($binding->scope->equals($scope)) {
                $out[] = $binding;
            }
        }

        return $out;
    }
}
