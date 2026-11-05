<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Support;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * PSR-14 dispatcher that keeps the dispatched events.
 */
final class RecordingDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public function of(string $class): array
    {
        return array_values(array_filter($this->events, static fn (object $e): bool => $e instanceof $class));
    }
}
