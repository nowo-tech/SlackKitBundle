<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Linking;

use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Test\SlackIdentityLinksContractTestCase;

/**
 * Several Slack identities per account, one per workspace (spec 237 FR-010, 1.4). Implement it instead of
 * {@see SlackIdentityStoreInterface} to enable linking from a click (`linking.from_click`).
 *
 * Contract (checked by {@see SlackIdentityLinksContractTestCase}):
 * - a (workspace, member) pair belongs to one account at most; an account holds one link per workspace;
 * - {@see self::saveLink()} creates or replaces the account's link **in the link's workspace only**, and **refuses**
 *   (throws {@see SlackIdentityConflictException}) a (workspace, member) pair that another account holds: it never
 *   replaces another account's link (audit R2-M1; a unique key on (workspace, member) does it). Releasing a pair is an
 *   explicit {@see self::removeLinkIn()} by the caller, which then dispatches `SlackLinkReleasedEvent`;
 * - {@see self::findLinkByAccount()} and {@see self::removeLink()} act on the account's link in the default
 *   installation's workspace (the 1.2 single-link API), {@see self::removeLinkIn()} on one workspace;
 * - ids compare exactly (binary collation on MySQL / MariaDB).
 */
interface SlackIdentityLinksInterface extends SlackIdentityStoreInterface
{
    /**
     * Every link of the account, one per workspace.
     *
     * @return list<SlackLink>
     */
    public function linksOf(string $accountId): array;

    /**
     * Removes the account's link in that workspace, if any.
     */
    public function removeLinkIn(string $accountId, SlackTeamId $team): void;

    /**
     * How the account is named to the linked member in Slack ("Linked to <label>"): a display name, never an e-mail.
     */
    public function accountLabel(string $accountId): string;
}
