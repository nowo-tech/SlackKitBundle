<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Integration\Race;

use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * A lock factory that keeps every lock it creates until the process ends. A `flock` claim lives only as long as its
 * lock object (the file handle closes with it), while the bundle drops its single-use claims at once because expiring
 * stores keep them; keeping the objects lets a race process on a shared `FlockStore` hold its claim while the others
 * try, like an expiring store does.
 */
final class RetainingLockFactory extends LockFactory
{
    /** @var list<SharedLockInterface> */
    private array $kept = []; // @phpstan-ignore property.onlyWritten (held only to keep the locks alive)

    public function createLockFromKey(Key $key, ?float $ttl = 300.0, bool $autoRelease = true): SharedLockInterface
    {
        return $this->kept[] = parent::createLockFromKey($key, $ttl, $autoRelease);
    }
}
