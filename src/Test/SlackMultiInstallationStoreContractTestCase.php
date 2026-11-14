<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Test;

use DateTimeImmutable;
use Nowo\SlackKitBundle\Credentials\SlackInstallation;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Credentials\SlackInstallationStoreInterface;
use Nowo\SlackKitBundle\Id\SlackEnterpriseId;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Security\SlackSecret;
use PHPUnit\Framework\TestCase;

/**
 * The contract of an installation store that keeps several workspaces (spec 237: "Add to Slack" and own apps). Extend
 * it in your test suite for the store you configure as `credentials.store` with `installations.oauth`, and return an
 * EMPTY store. Requires phpunit/phpunit (a dev dependency of the host). Also extend
 * {@see SlackInstallationStoreContractTestCase} for the base contract.
 */
abstract class SlackMultiInstallationStoreContractTestCase extends TestCase
{
    /** First workspace (fake). */
    public const TEAM = 'T00000000';

    /** Second workspace (fake). */
    public const OTHER_TEAM = 'T00000001';

    /** Enterprise of the first workspace (fake). */
    public const ENTERPRISE = 'E00000000';

    /** Bot token of the first workspace (fake, not a scanner shape). */
    public const BOT_TOKEN = 'xoxb-test-not-a-real-token';

    /** Bot token of the second workspace (fake). */
    public const OTHER_BOT_TOKEN = 'xoxb-test-not-a-real-token-two';

    /** Own-app signing secret of the second workspace (fake). */
    public const OWN_SIGNING_SECRET = 'ownappsigningsecretnotreal000000';

    /**
     * An empty store under test.
     */
    abstract protected function createEmptyStore(): SlackInstallationStoreInterface;

    /**
     * Everything the store wrote to its backend as one string, to prove tokens and secrets are not stored in clear;
     * null when the backend cannot be inspected.
     */
    protected function rawStorage(): ?string
    {
        return null;
    }

    /**
     * Each workspace is found only by its own key, with its own token, and the OAuth fields round-trip.
     */
    public function testWorkspacesAreIsolated(): void
    {
        $store = $this->seeded();
        $first = $store->find($this->key(self::TEAM));
        $other = $store->find($this->key(self::OTHER_TEAM));
        self::assertInstanceOf(SlackInstallation::class, $first);
        self::assertInstanceOf(SlackInstallation::class, $other);
        self::assertSame(self::BOT_TOKEN, $first->botToken->reveal());
        self::assertSame(self::OTHER_BOT_TOKEN, $other->botToken->reveal());
        self::assertSame(self::TEAM, $first->teamId?->value);
        self::assertSame(self::OTHER_TEAM, $other->teamId?->value);
        self::assertSame(self::ENTERPRISE, $first->enterpriseId?->value);
        self::assertSame('A00000000', $first->appId);
        self::assertSame('Fake team', $first->teamName);
        self::assertSame(['chat:write', 'users:read'], $first->scopes);
        self::assertNull($first->signingSecret);
        self::assertInstanceOf(SlackSecret::class, $other->signingSecret);
        self::assertSame(SlackSecret::SIGNING_SECRET, $other->signingSecret->kind);
        self::assertSame(self::OWN_SIGNING_SECRET, $other->signingSecret->reveal());
        self::assertNull($store->find(new SlackInstallationKey('other_app', SlackTeamId::fromString(self::TEAM))));
        self::assertNull($store->find($this->key('T00000009')));
    }

    /**
     * Saving the same workspace again updates it (a re-install), and revoking one workspace leaves the other usable.
     */
    public function testReinstallUpdatesAndRevocationIsPerWorkspace(): void
    {
        $store = $this->seeded();
        $store->save($this->installation(self::TEAM, self::OTHER_BOT_TOKEN, ['chat:write']));
        self::assertSame(self::OTHER_BOT_TOKEN, $store->find($this->key(self::TEAM))?->botToken->reveal());

        $store->markRevoked($this->key(self::TEAM), new DateTimeImmutable('@1'));
        $store->markRevoked($this->key(self::TEAM), new DateTimeImmutable('@2'));
        $revoked = $store->find($this->key(self::TEAM));
        self::assertTrue(!$revoked instanceof SlackInstallation || $revoked->isRevoked());
        self::assertFalse($store->find($this->key(self::OTHER_TEAM))?->isRevoked() ?? true);

        $store->save($this->installation(self::TEAM, self::BOT_TOKEN, ['chat:write']));
        self::assertFalse($store->find($this->key(self::TEAM))?->isRevoked() ?? true, 'A re-install after an uninstall is active again.');
    }

    /**
     * Tokens and secrets are not written in clear (when the backend can be inspected).
     */
    public function testNoPlaintextAtRest(): void
    {
        $this->seeded();
        $raw = $this->rawStorage();
        if ($raw === null) {
            $this->addToAssertionCount(1); // The backend cannot be inspected: nothing more to prove here.

            return;
        }
        self::assertStringNotContainsString(self::BOT_TOKEN, $raw);
        self::assertStringNotContainsString(self::OWN_SIGNING_SECRET, $raw);
    }

    private function seeded(): SlackInstallationStoreInterface
    {
        $store = $this->createEmptyStore();
        $store->save($this->installation(self::TEAM, self::BOT_TOKEN, ['chat:write', 'users:read'], SlackEnterpriseId::fromString(self::ENTERPRISE)));
        $store->save($this->installation(self::OTHER_TEAM, self::OTHER_BOT_TOKEN, ['chat:write'], null, SlackSecret::signingSecret(self::OWN_SIGNING_SECRET)));

        return $store;
    }

    private function key(string $team): SlackInstallationKey
    {
        return new SlackInstallationKey(SlackInstallationKey::DEFAULT_APP, SlackTeamId::fromString($team));
    }

    /**
     * @param list<string> $scopes
     */
    private function installation(string $team, string $token, array $scopes, ?SlackEnterpriseId $enterprise = null, ?SlackSecret $own = null): SlackInstallation
    {
        $teamId = SlackTeamId::fromString($team);

        return new SlackInstallation(
            new SlackInstallationKey(SlackInstallationKey::DEFAULT_APP, $teamId, $enterprise),
            SlackSecret::botToken($token),
            $teamId,
            false,
            'U0000000B',
            null,
            $enterprise,
            'A00000000',
            'Fake team',
            $scopes,
            SlackUserId::fromString('U00000001'),
            new DateTimeImmutable('@1793836800'),
            null,
            null,
            null,
            $own,
        );
    }
}
