<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Credentials;

use InvalidArgumentException;
use Nowo\SlackKitBundle\Id\SlackEnterpriseId;
use Nowo\SlackKitBundle\Id\SlackTeamId;

use function preg_match;

/**
 * Which installation a call uses: a configured app name, the workspace and, inside an Enterprise Grid, the
 * organisation. Slack issues one bot token per app and workspace, so (app, enterprise?, team) names exactly one
 * installation. A host with one installation uses {@see self::default()}.
 */
final readonly class SlackInstallationKey
{
    /** App name pattern (`nowo_slack_kit.apps` keys and the `{app}` ingress segment). */
    public const APP_PATTERN = '/^[a-z0-9_]{1,32}$/';

    /** Name of the default app. */
    public const DEFAULT_APP = 'default';

    /**
     * @param string $app app name
     * @param SlackTeamId|null $team workspace, null for "the app's only installation"
     * @param SlackEnterpriseId|null $enterprise Enterprise Grid organisation of the workspace, when it has one
     *
     * @throws InvalidArgumentException when the app name is malformed, or an enterprise is given without a workspace
     */
    public function __construct(
        public string $app = self::DEFAULT_APP,
        public ?SlackTeamId $team = null,
        public ?SlackEnterpriseId $enterprise = null,
    ) {
        if (preg_match(self::APP_PATTERN, $app) !== 1) {
            throw new InvalidArgumentException('Invalid Slack Kit app name.');
        }
        if ($enterprise instanceof SlackEnterpriseId && !$team instanceof SlackTeamId) {
            throw new InvalidArgumentException('A Slack Kit installation key with an enterprise needs a workspace.');
        }
    }

    /**
     * The default app, without a workspace.
     */
    public static function default(): self
    {
        return new self();
    }

    /**
     * `<app>`, `<app>:<team>` or `<app>:<enterprise>:<team>` (cache keys, rate-limit memory, logs).
     */
    public function key(): string
    {
        if (!$this->team instanceof SlackTeamId) {
            return $this->app;
        }

        return $this->enterprise instanceof SlackEnterpriseId
            ? $this->app . ':' . $this->enterprise->value . ':' . $this->team->value
            : $this->app . ':' . $this->team->value;
    }

    /**
     * `<app>` or `<app>:<team>`, without the enterprise: workspace ids are unique in Slack, so stores index
     * installations and bindings by it.
     */
    public function workspaceKey(): string
    {
        return $this->team instanceof SlackTeamId ? $this->app . ':' . $this->team->value : $this->app;
    }

    /**
     * Same app and workspace (both named), and the same enterprise when both name one.
     */
    public function sameWorkspace(?self $other): bool
    {
        return $other instanceof self && $this->team instanceof SlackTeamId && $other->workspaceKey() === $this->workspaceKey()
            && (!$this->enterprise instanceof SlackEnterpriseId || !$other->enterprise instanceof SlackEnterpriseId || $this->enterprise->equals($other->enterprise));
    }

    /**
     * Same app, workspace and enterprise.
     */
    public function equals(?self $other): bool
    {
        return $other instanceof self && $other->key() === $this->key();
    }

    /**
     * Whether an installation returned by a store for this key really belongs to it: same app; when the key names a
     * workspace, the same workspace; when both name an enterprise, the same enterprise (workspace ids are unique in
     * Slack, so a key or a stored installation without the enterprise still matches). A store that ignores part of
     * the key never hands one workspace's token to another.
     */
    public function admits(SlackInstallation $installation): bool
    {
        if ($installation->key->app !== $this->app) {
            return false;
        }
        if (!$this->team instanceof SlackTeamId) {
            return true;
        }
        if (!$this->team->equals($installation->teamId)) {
            return false;
        }

        return !$installation->enterpriseId instanceof SlackEnterpriseId || !$this->enterprise instanceof SlackEnterpriseId || $installation->enterpriseId->equals($this->enterprise);
    }
}
