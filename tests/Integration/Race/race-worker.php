<?php

declare(strict_types=1);

/*
 * One "server process" of the lock race tests (LockRaceTest). It builds its own lock factory on the given store,
 * waits for the shared start instant, makes one claim and prints "1" (won) or "0" (refused), then keeps its locks for
 * <hold-ms> so that a flock claim (released with its lock object or the process) still holds while the others try.
 *
 * Usage: php race-worker.php <sig|evt|state> <flock|pdo> <store> <start-at> <hold-ms> <value> [<session-file>]
 *  - store: a lock directory (flock; the same one for every process = a shared store, one per process = a local
 *    store per server) or a SQLite file (pdo, an expiring store like the production DBAL/PDO stores).
 *  - value: the signature (sig), the event_id (evt) or the install `state` (state).
 *  - session-file: the pending installs as JSON, i.e. the session data every concurrent request read (state).
 */

use Nowo\SlackKitBundle\Ingress\ReplayGuard;
use Nowo\SlackKitBundle\Install\InstallStateStore;
use Nowo\SlackKitBundle\Tests\Integration\Race\LockRaceTest;
use Nowo\SlackKitBundle\Tests\Integration\Race\RetainingLockFactory;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\PdoStore;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $kind, $storeKind, $store, $startAt, $holdMs, $value] = $argv;
$sessionFile                                             = $argv[7] ?? '';

// A flock claim ends with its lock object: keep the objects (see RetainingLockFactory). The PDO store keeps claims itself.
$locks = $storeKind === 'pdo' ? new LockFactory(new PdoStore(LockRaceTest::pdo($store))) : new RetainingLockFactory(new FlockStore($store));

while (microtime(true) < (float) $startAt) {
    usleep(500); // @phpstan-ignore frankenphp.hardening.noBlockingSleep (a CLI race process, not a worker)
}

if ($kind === 'state') {
    $session = new Session(new MockArraySessionStorage());
    $session->set(InstallStateStore::SESSION_KEY, json_decode((string) file_get_contents($sessionFile), true));
    $won = (new InstallStateStore(InstallStateStore::deriveKey(LockRaceTest::KERNEL_SECRET), $locks, new NativeClock()))->consume($session, $value, 'admin') !== null;
} elseif ($kind === 'evt') {
    $won = (new ReplayGuard($locks))->claimEvent($value) instanceof LockInterface;
} else {
    $won = (new ReplayGuard($locks))->claimSignature($value);
}

echo $won ? '1' : '0';
usleep((int) $holdMs * 1000); // @phpstan-ignore frankenphp.hardening.noBlockingSleep (a CLI race process, not a worker)
