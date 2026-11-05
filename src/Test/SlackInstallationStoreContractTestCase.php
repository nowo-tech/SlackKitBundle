<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Test;

use DateTimeImmutable;
use Nowo\SlackKitBundle\Credentials\SlackInstallation;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Credentials\SlackInstallationStoreInterface;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Security\SlackSecret;
use PHPUnit\Framework\TestCase;

/**
 * The installation store contract (contract §7.2, audit FS-I12) for host stores: extend it in your test suite and
 * return a store that holds exactly one installation, `(default app, {@see self::TEAM})`, with the bot token
 * {@see self::BOT_TOKEN} and the signing secret {@see self::SIGNING_SECRET} for the default app. Requires
 * phpunit/phpunit (a dev dependency of the host).
 */
abstract class SlackInstallationStoreContractTestCase extends TestCase
{
    /** Workspace of the seeded installation (fake). */
    public const TEAM = 'T00000000';

    /** Another workspace (fake). */
    public const OTHER_TEAM = 'T00000001';

    /** Seeded bot token (fake, not a scanner shape). */
    public const BOT_TOKEN = 'xoxb-test-not-a-real-token';

    /** Seeded signing secret of the default app (fake). */
    public const SIGNING_SECRET = 'testsigningsecretnotreal00000000';

    /**
     * The store under test, seeded as described in the class comment.
     */
    abstract protected function createStore(): SlackInstallationStoreInterface;

    /**
     * Everything the store wrote to its backend (rows, files, cache entries) as one string, to prove the token and
     * secret are not stored in clear; null when the backend cannot be inspected.
     */
    protected function rawStorage(): ?string
    {
        return null;
    }

    /**
     * `find()` returns secret objects, never strings.
     */
    public function testFindReturnsSecretObjects(): void
    {
        $store        = $this->createStore();
        $installation = $store->find(new SlackInstallationKey(SlackInstallationKey::DEFAULT_APP, SlackTeamId::fromString(self::TEAM)));
        self::assertInstanceOf(SlackInstallation::class, $installation);
        self::assertSame(SlackSecret::BOT_TOKEN, $installation->botToken->kind);
        self::assertSame(self::BOT_TOKEN, $installation->botToken->reveal());
        self::assertSame(self::TEAM, $installation->teamId?->value);
        $secret = $store->findSigningSecret(SlackInstallationKey::DEFAULT_APP);
        self::assertInstanceOf(SlackSecret::class, $secret);
        self::assertSame(self::SIGNING_SECRET, $secret->reveal());
    }

    /**
     * A key returns only its own installation (no "first match").
     */
    public function testReadsAreScoped(): void
    {
        $store = $this->createStore();
        self::assertNull($store->find(new SlackInstallationKey(SlackInstallationKey::DEFAULT_APP, SlackTeamId::fromString(self::OTHER_TEAM))));
        self::assertNull($store->find(new SlackInstallationKey('other_app', SlackTeamId::fromString(self::TEAM))));
        self::assertNull($store->findSigningSecret('other_app'));
    }

    /**
     * `markRevoked()` is idempotent and a revoked installation is never usable.
     */
    public function testMarkRevokedIsIdempotent(): void
    {
        $store = $this->createStore();
        $key   = new SlackInstallationKey(SlackInstallationKey::DEFAULT_APP, SlackTeamId::fromString(self::TEAM));
        $store->markRevoked($key, new DateTimeImmutable('@1'));
        $store->markRevoked($key, new DateTimeImmutable('@2'));
        $store->markRevoked(new SlackInstallationKey('other_app', SlackTeamId::fromString(self::OTHER_TEAM)), new DateTimeImmutable('@3'));

        $installation = $store->find($key);
        self::assertTrue(!$installation instanceof SlackInstallation || $installation->isRevoked());
    }

    /**
     * Tokens and secrets are not written in clear (when the backend can be inspected).
     */
    public function testNoPlaintextAtRest(): void
    {
        $this->createStore();
        $raw = $this->rawStorage();
        if ($raw === null) {
            $this->addToAssertionCount(1); // The backend cannot be inspected: nothing more to prove here.

            return;
        }
        self::assertStringNotContainsString(self::BOT_TOKEN, $raw);
        self::assertStringNotContainsString(self::SIGNING_SECRET, $raw);
    }
}
