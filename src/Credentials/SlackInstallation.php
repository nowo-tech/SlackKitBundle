<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Credentials;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Nowo\SlackKitBundle\Id\SlackEnterpriseId;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Security\SlackSecret;

use function array_values;
use function in_array;
use function preg_match;

/**
 * An app installed in one workspace: its bot token and what the bundle needs to know about it. Since 1.3 it also
 * carries what an OAuth install records (spec 237 FR-001): enterprise, Slack app id, workspace name, granted scopes,
 * installer, install time, the refresh token and expiry when token rotation is on, the revocation reason, and the
 * signing secret of a workspace's own app (FR-004). Every new field is optional, so 1.2 stores keep working.
 */
final readonly class SlackInstallation
{
    /** Slack app id format (`A…`). */
    public const APP_ID_PATTERN = '/^A[A-Z0-9]{2,30}$/';

    /** OAuth scope format (`chat:write`, `users:read.email`…). */
    public const SCOPE_PATTERN = '/^[a-z][a-z0-9_.:]{0,63}$/';

    /** @var list<string> */
    public array $scopes;

    /**
     * @param SlackInstallationKey $key which installation
     * @param SlackSecret $botToken bot token (`xoxb-`)
     * @param SlackTeamId|null $teamId workspace (required for signed ingress and linking)
     * @param bool $allowEmailLookup `users.lookupByEmail` allowed (scope `users:read.email`)
     * @param string|null $botUserId bot user id, when known
     * @param DateTimeImmutable|null $revokedAt uninstall or revocation time
     * @param SlackEnterpriseId|null $enterpriseId Enterprise Grid organisation of the workspace
     * @param string|null $appId Slack app id (`A…`) the token belongs to
     * @param string|null $teamName workspace name shown to people
     * @param list<string> $scopes granted bot scopes
     * @param SlackUserId|null $installerUserId Slack member who installed the app
     * @param DateTimeImmutable|null $installedAt install (or last re-install) time
     * @param SlackSecret|null $refreshToken refresh token (`xoxe-1-`), only with token rotation
     * @param DateTimeImmutable|null $tokenExpiresAt bot token expiry, only with token rotation
     * @param string|null $revokedReason why it was revoked (`app_uninstalled`, `tokens_revoked`, or a host reason)
     * @param SlackSecret|null $signingSecret signing secret of the workspace's own app (FR-004), null for an app of
     *                                        `nowo_slack_kit.apps`
     *
     * @throws InvalidArgumentException when a secret has the wrong kind, the app id or a scope is malformed
     */
    public function __construct(
        public SlackInstallationKey $key,
        public SlackSecret $botToken,
        public ?SlackTeamId $teamId = null,
        public bool $allowEmailLookup = false,
        public ?string $botUserId = null,
        public ?DateTimeImmutable $revokedAt = null,
        public ?SlackEnterpriseId $enterpriseId = null,
        public ?string $appId = null,
        public ?string $teamName = null,
        array $scopes = [],
        public ?SlackUserId $installerUserId = null,
        public ?DateTimeImmutable $installedAt = null,
        public ?SlackSecret $refreshToken = null,
        public ?DateTimeImmutable $tokenExpiresAt = null,
        public ?string $revokedReason = null,
        public ?SlackSecret $signingSecret = null,
    ) {
        if ($botToken->kind !== SlackSecret::BOT_TOKEN) {
            throw new InvalidArgumentException('A Slack installation needs a bot token.');
        }
        if ($refreshToken instanceof SlackSecret && $refreshToken->kind !== SlackSecret::REFRESH_TOKEN) {
            throw new InvalidArgumentException('The refresh token of a Slack installation must be a refresh token.');
        }
        if ($signingSecret instanceof SlackSecret && $signingSecret->kind !== SlackSecret::SIGNING_SECRET) {
            throw new InvalidArgumentException('The own-app secret of a Slack installation must be a signing secret.');
        }
        if ($appId !== null && preg_match(self::APP_ID_PATTERN, $appId) !== 1) {
            throw new InvalidArgumentException('Invalid Slack app id.');
        }
        foreach ($scopes as $scope) {
            if (preg_match(self::SCOPE_PATTERN, $scope) !== 1) {
                throw new InvalidArgumentException('Invalid Slack scope.');
            }
        }
        $this->scopes = array_values($scopes);
    }

    /**
     * Whether the installation was uninstalled or its token revoked.
     */
    public function isRevoked(): bool
    {
        return $this->revokedAt instanceof DateTimeImmutable;
    }

    /**
     * Whether the bot token has an expiry (token rotation) that has passed.
     */
    public function isTokenExpired(DateTimeInterface $now): bool
    {
        return $this->tokenExpiresAt instanceof DateTimeImmutable && $this->tokenExpiresAt <= $now;
    }

    /**
     * Whether the scope was granted.
     */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /**
     * The key this installation is stored under: its own workspace and enterprise when it knows them.
     */
    public function storageKey(): SlackInstallationKey
    {
        return $this->teamId instanceof SlackTeamId ? new SlackInstallationKey($this->key->app, $this->teamId, $this->enterpriseId) : $this->key;
    }

    /**
     * The same installation under another key.
     */
    public function withKey(SlackInstallationKey $key): self
    {
        return new self($key, $this->botToken, $this->teamId, $this->allowEmailLookup, $this->botUserId, $this->revokedAt, $this->enterpriseId, $this->appId, $this->teamName, $this->scopes, $this->installerUserId, $this->installedAt, $this->refreshToken, $this->tokenExpiresAt, $this->revokedReason, $this->signingSecret);
    }

    /**
     * The same installation, revoked at `$at` for `$reason`.
     */
    public function revoked(DateTimeImmutable $at, ?string $reason = null): self
    {
        return new self($this->key, $this->botToken, $this->teamId, $this->allowEmailLookup, $this->botUserId, $at, $this->enterpriseId, $this->appId, $this->teamName, $this->scopes, $this->installerUserId, $this->installedAt, $this->refreshToken, $this->tokenExpiresAt, $reason, $this->signingSecret);
    }

    /**
     * The same installation with the workspace and bot user an `auth.test` reported (Test connection).
     */
    public function withWorkspace(SlackTeamId $team, ?string $botUserId): self
    {
        return new self($this->key, $this->botToken, $team, $this->allowEmailLookup, $botUserId, $this->revokedAt, $this->enterpriseId, $this->appId, $this->teamName, $this->scopes, $this->installerUserId, $this->installedAt, $this->refreshToken, $this->tokenExpiresAt, $this->revokedReason, $this->signingSecret);
    }
}
