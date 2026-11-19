<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Support;

use RuntimeException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

use function str_contains;

/**
 * A lock store that is down for the resources containing one of the given markers (save and/or delete throw, like a
 * Redis or database store that cannot be reached); every other resource goes to an in-memory store.
 */
final class FailingLockStore implements PersistingStoreInterface
{
    private InMemoryStore $inner;

    /**
     * @param list<string> $saveFails resource markers whose save() throws
     * @param list<string> $deleteFails resource markers whose delete() throws
     */
    public function __construct(private readonly array $saveFails = [''], private readonly array $deleteFails = [])
    {
        $this->inner = new InMemoryStore();
    }

    public function save(Key $key): void
    {
        $this->fail($key, $this->saveFails);
        $this->inner->save($key);
    }

    public function delete(Key $key): void
    {
        $this->fail($key, $this->deleteFails);
        $this->inner->delete($key);
    }

    public function exists(Key $key): bool
    {
        return $this->inner->exists($key);
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
        $this->fail($key, $this->saveFails);
        $this->inner->putOffExpiration($key, $ttl);
    }

    /**
     * @param list<string> $markers
     */
    private function fail(Key $key, array $markers): void
    {
        foreach ($markers as $marker) {
            if (str_contains((string) $key, $marker)) {
                throw new RuntimeException('Lock store unreachable.');
            }
        }
    }
}
