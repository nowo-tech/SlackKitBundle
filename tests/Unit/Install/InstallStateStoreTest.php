<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Install;

use Nowo\SlackKitBundle\Credentials\SlackOwnerScope;
use Nowo\SlackKitBundle\Install\InstallStateStore;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Spec 237 FR-003: the install state is signed, bound to the session and the account, valid 10 minutes and single-use.
 */
final class InstallStateStoreTest extends TestCase
{
    private MockClock $clock;
    private InstallStateStore $states;

    protected function setUp(): void
    {
        $this->clock  = new MockClock('@' . Fake::NOW);
        $this->states = new InstallStateStore(InstallStateStore::deriveKey(Fake::KERNEL_SECRET), new LockFactory(new InMemoryStore()), $this->clock);
    }

    public function testAStateIsConsumedOnceBySameSessionAndAccount(): void
    {
        $session = $this->session();
        $state   = $this->states->issue($session, 'default', new SlackOwnerScope('organization', 'org-1'), 'admin');

        $pending = $this->states->consume($session, $state, 'admin');
        self::assertSame('default', $pending['app'] ?? null);
        self::assertSame('organization:org-1', $pending['scope']->key());
        self::assertNull($this->states->consume($session, $state, 'admin'), 'single-use');
    }

    public function testRefusals(): void
    {
        $scope   = new SlackOwnerScope('organization', 'org-1');
        $session = $this->session();

        $state = $this->states->issue($session, 'default', $scope, 'admin');
        self::assertNull($this->states->consume($this->session(), $state, 'admin'), 'another session');

        $state = $this->states->issue($session, 'default', $scope, 'admin');
        self::assertNull($this->states->consume($session, $state, 'viewer'), 'another account');
        self::assertNull($this->states->consume($session, $state, 'admin'), 'a refused attempt consumed the state');

        $state = $this->states->issue($session, 'default', $scope, 'admin');
        $this->clock->sleep(600);
        self::assertNull($this->states->consume($session, $state, 'admin'), 'expired after 10 minutes');

        $state   = $this->states->issue($session, 'default', $scope, 'admin');
        [$nonce] = explode('.', $state);
        self::assertNull($this->states->consume($session, $nonce . '.forged', 'admin'), 'forged MAC');
        self::assertNull($this->states->consume($session, 'a.b.c', 'admin'));
        self::assertNull($this->states->consume($session, '!!.x', 'admin'));
        $other = new InstallStateStore(InstallStateStore::deriveKey('another-kernel-secret-not-real'), new LockFactory(new InMemoryStore()), $this->clock);
        self::assertNull($other->consume($session, $state, 'admin'), 'a state signed by another key');
        self::assertNotNull($this->states->consume($session, $state, 'admin'));
    }

    public function testASecondProcessCannotReuseAState(): void
    {
        $locks   = new LockFactory(new InMemoryStore());
        $a       = new InstallStateStore(InstallStateStore::deriveKey(Fake::KERNEL_SECRET), $locks, $this->clock);
        $session = $this->session();
        $state   = $a->issue($session, 'default', new SlackOwnerScope('organization', 'org-1'), 'admin');
        $copy    = $session->get(InstallStateStore::SESSION_KEY);

        self::assertNotNull($a->consume($session, $state, 'admin'));
        $session->set(InstallStateStore::SESSION_KEY, $copy); // Another process still holds the old session data.
        self::assertNull($a->consume($session, $state, 'admin'), 'the lock claim refuses the second use');
    }

    public function testPendingInstallsArePrunedAndCapped(): void
    {
        $session = $this->session();
        $session->set(InstallStateStore::SESSION_KEY, ['junk' => 'x', 'bad' => ['app' => 'default'], 7 => []]);
        $states = [];
        for ($i = 0; $i < 7; ++$i) {
            $states[] = $this->states->issue($session, 'default', new SlackOwnerScope('organization', 'org-' . $i), 'admin');
            $this->clock->sleep(1);
        }
        $stored = $session->get(InstallStateStore::SESSION_KEY);
        self::assertIsArray($stored);
        self::assertCount(InstallStateStore::MAX_PENDING, $stored);
        self::assertNull($this->states->consume($session, $states[0], 'admin'), 'the oldest was dropped');
        $newest = $this->states->consume($session, $states[6], 'admin');
        self::assertNotNull($newest);
        self::assertSame('organization:org-6', $newest['scope']->key());

        $this->clock->sleep(700);
        $this->states->issue($session, 'default', new SlackOwnerScope('organization', 'org-x'), 'admin');
        self::assertCount(1, (array) $session->get(InstallStateStore::SESSION_KEY), 'expired ones are pruned');
    }

    public function testAStoredScopeThatNoLongerValidatesIsRefused(): void
    {
        $session = $this->session();
        $state   = $this->states->issue($session, 'default', new SlackOwnerScope('organization', 'org-1'), 'admin');
        $records = (array) $session->get(InstallStateStore::SESSION_KEY);
        foreach ($records as $id => $record) {
            $records[$id] = ['type' => 'Bad Type'] + (array) $record;
        }
        $session->set(InstallStateStore::SESSION_KEY, $records);

        self::assertNull($this->states->consume($session, $state, 'admin'));
    }

    private function session(): Session
    {
        return new Session(new MockArraySessionStorage());
    }
}
