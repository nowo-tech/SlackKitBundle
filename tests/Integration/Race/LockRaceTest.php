<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Integration\Race;

use Nowo\SlackKitBundle\Credentials\SlackOwnerScope;
use Nowo\SlackKitBundle\Install\InstallStateStore;
use PDO;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Lock\Store\PdoStore;
use Symfony\Component\Process\Process;

use function array_map;
use function array_sum;
use function bin2hex;
use function file_put_contents;
use function implode;
use function is_dir;
use function json_encode;
use function microtime;
use function mkdir;
use function random_bytes;
use function sprintf;
use function sys_get_temp_dir;

use const PHP_BINARY;

/**
 * REQ-RUNTIME-003: real races between OS processes (one PHP process per request or server), each with its own lock
 * factory on one store, on the Slack request-signature replay check, the Events API `event_id` claim and the "Add to
 * Slack" state claim. With a shared store exactly one process wins; with a local store per server every server wins
 * once, which is the multi-server risk documented in docs/CONCURRENCY.md.
 */
#[CoversNothing]
final class LockRaceTest extends TestCase
{
    /** Kernel secret of the race processes (test value, not a real secret). */
    public const KERNEL_SECRET = 'race-test-kernel-secret-not-real-0123456789';

    /** How long each process keeps its claim after trying (ms), so flock claims outlive every attempt. */
    private const HOLD_MS = 1000;

    /** Hard limit per process (s): a hung process fails the test instead of holding the machine. */
    private const PROCESS_TIMEOUT = 30.0;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/slack-kit-race-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
        (new PdoStore(self::pdo($this->db())))->createTable();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    /**
     * A PDO connection to the shared SQLite lock database; the busy timeout makes concurrent writers wait instead of
     * failing with "database is locked".
     */
    public static function pdo(string $file): PDO
    {
        return new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sharedStores(): iterable
    {
        foreach (['sig', 'evt', 'state'] as $kind) {
            yield $kind . ' on a shared flock store' => [$kind, 'flock'];
            yield $kind . ' on a shared expiring PDO store' => [$kind, 'pdo'];
        }
    }

    #[DataProvider('sharedStores')]
    public function testASharedStoreLetsExactlyOneOfFourProcessesWin(string $kind, string $store): void
    {
        $results = $this->race($kind, $store, 4, fn (int $i): string => $store === 'pdo' ? $this->db() : $this->lockDir('shared'));

        self::assertSame(1, array_sum($results), sprintf('Exactly one process may win the %s claim: %s', $kind, implode(',', $results)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function kinds(): iterable
    {
        yield 'signature' => ['sig'];
        yield 'install state' => ['state'];
    }

    #[DataProvider('kinds')]
    public function testALocalStorePerServerDoesNotPreventTheRace(string $kind): void
    {
        $results = $this->race($kind, 'flock', 2, fn (int $i): string => $this->lockDir('server-' . $i));

        self::assertSame([1, 1], $results, 'A local lock store per server cannot make a claim single-use across servers.');
    }

    public function testAnExpiringStoreRefusesAReplayAfterTheWinnerExited(): void
    {
        [$value] = $this->value('sig');

        self::assertSame('1', $this->finish($this->start('sig', 'pdo', $this->db(), 0.0, 0, $value)));
        self::assertSame('0', $this->finish($this->start('sig', 'pdo', $this->db(), 0.0, 0, $value)), 'the signature claim outlives the process');
    }

    /**
     * Starts the processes at the same instant on the same claim.
     *
     * @param callable(int): string $storeFor the store of each process
     *
     * @return list<int> 1 won / 0 refused, per process
     */
    private function race(string $kind, string $store, int $processes, callable $storeFor): array
    {
        [$value, $sessionFile] = $this->value($kind);
        $startAt               = microtime(true) + 2.0;
        $running               = [];
        for ($i = 0; $i < $processes; ++$i) {
            $running[] = $this->start($kind, $store, $storeFor($i), $startAt, self::HOLD_MS, $value, $sessionFile);
        }

        return array_map(fn (Process $process): int => (int) $this->finish($process), $running);
    }

    /**
     * The claimed value, and for a state the session data every request read.
     *
     * @return array{string, string}
     */
    private function value(string $kind): array
    {
        if ($kind !== 'state') {
            return [$kind === 'evt' ? 'Ev' . bin2hex(random_bytes(6)) : 'v0=' . bin2hex(random_bytes(32)), ''];
        }
        $session = new Session(new MockArraySessionStorage());
        $states  = new InstallStateStore(InstallStateStore::deriveKey(self::KERNEL_SECRET), new LockFactory(new InMemoryStore()), new NativeClock());
        $state   = $states->issue($session, 'default', new SlackOwnerScope('organization', 'org-1'), 'admin');
        $file    = $this->dir . '/session.json';
        file_put_contents($file, (string) json_encode($session->get(InstallStateStore::SESSION_KEY)));

        return [$state, $file];
    }

    private function start(string $kind, string $store, string $location, float $startAt, int $holdMs, string $value, string $sessionFile = ''): Process
    {
        $process = new Process([PHP_BINARY, __DIR__ . '/race-worker.php', $kind, $store, $location, sprintf('%.6F', $startAt), (string) $holdMs, $value, $sessionFile]);
        $process->setTimeout(self::PROCESS_TIMEOUT);
        $process->start();

        return $process;
    }

    private function finish(Process $process): string
    {
        $process->wait();
        $out = $process->getOutput();

        self::assertSame(0, $process->getExitCode(), 'Race process failed: ' . $process->getErrorOutput() . $out);
        self::assertContains($out, ['0', '1'], 'Unexpected output: ' . $out . $process->getErrorOutput());

        return $out;
    }

    private function db(): string
    {
        return $this->dir . '/locks.sqlite';
    }

    private function lockDir(string $name): string
    {
        $dir = $this->dir . '/locks-' . $name;
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        return $dir;
    }
}
