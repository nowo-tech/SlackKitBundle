<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Test;

use DateTimeImmutable;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Linking\SlackIdentityLinksInterface;
use Nowo\SlackKitBundle\Linking\SlackLink;
use PHPUnit\Framework\TestCase;

/**
 * The contract of an identity store with several identities per account (1.4, spec 237 FR-010): extend it in your
 * test suite and return an EMPTY store whose accounts `account-1` and `account-2` exist and are active. Requires
 * phpunit/phpunit (a dev dependency of the host).
 */
abstract class SlackIdentityLinksContractTestCase extends TestCase
{
    /**
     * An empty store under test (accounts `account-1` and `account-2` exist and are active).
     */
    abstract protected function createEmptyStore(): SlackIdentityLinksInterface;

    /**
     * One identity per workspace per account; saving in one workspace never touches another; reads compare exactly.
     */
    public function testOneIdentityPerWorkspace(): void
    {
        $store = $this->createEmptyStore();
        $store->saveLink($this->link('account-1', 'T00000000', 'U00000001'));
        $store->saveLink($this->link('account-1', 'T00000001', 'U00000009'));

        self::assertCount(2, $store->linksOf('account-1'));
        self::assertSame([], $store->linksOf('account-2'));
        self::assertSame([], $store->linksOf('ACCOUNT-1'), 'account ids compare exactly');
        self::assertSame('account-1', $store->findLinkBySlackUser(SlackTeamId::fromString('T00000001'), SlackUserId::fromString('U00000009'))?->accountId);
        self::assertNull($store->findLinkBySlackUser(SlackTeamId::fromString('T00000000'), SlackUserId::fromString('U00000009')), 'a member id is per workspace');

        $store->saveLink($this->link('account-1', 'T00000001', 'U00000008'));
        $ids = [];
        foreach ($store->linksOf('account-1') as $link) {
            $ids[$link->team->value] = $link->slackUserId->value;
        }
        self::assertSame(['T00000000' => 'U00000001', 'T00000001' => 'U00000008'], $ids, 'saving replaces the link of that workspace only');
        self::assertNull($store->findLinkBySlackUser(SlackTeamId::fromString('T00000001'), SlackUserId::fromString('U00000009')));
    }

    /**
     * Removing one workspace's identity keeps the others; the label is not empty.
     */
    public function testRemoveLinkInOneWorkspace(): void
    {
        $store = $this->createEmptyStore();
        $store->saveLink($this->link('account-1', 'T00000000', 'U00000001'));
        $store->saveLink($this->link('account-1', 'T00000001', 'U00000009'));
        $store->saveLink($this->link('account-2', 'T00000001', 'U00000002'));

        $store->removeLinkIn('account-1', SlackTeamId::fromString('T00000001'));
        $store->removeLinkIn('account-1', SlackTeamId::fromString('T00000001'));

        self::assertCount(1, $store->linksOf('account-1'));
        self::assertSame('T00000000', $store->linksOf('account-1')[0]->team->value);
        self::assertCount(1, $store->linksOf('account-2'), 'another account keeps its identity in that workspace');
        self::assertNotSame('', $store->accountLabel('account-1'));
    }

    private function link(string $account, string $team, string $user): SlackLink
    {
        $at = new DateTimeImmutable('@1793836800');

        return new SlackLink($account, SlackTeamId::fromString($team), SlackUserId::fromString($user), $at, $at, SlackLink::SOURCE_CLICK);
    }
}
