<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Credentials;

use InvalidArgumentException;
use Nowo\SlackKitBundle\Id\SlackTeamId;

use function preg_match;

/**
 * Which installation a call uses: a configured app name and, for multi-workspace stores, the workspace. A host with
 * one installation uses {@see self::default()}.
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
     *
     * @throws InvalidArgumentException when the app name is malformed
     */
    public function __construct(
        public string $app = self::DEFAULT_APP,
        public ?SlackTeamId $team = null,
    ) {
        if (preg_match(self::APP_PATTERN, $app) !== 1) {
            throw new InvalidArgumentException('Invalid Slack Kit app name.');
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
     * `<app>` or `<app>:<team>` (cache keys, logs).
     */
    public function key(): string
    {
        return $this->team instanceof SlackTeamId ? $this->app . ':' . $this->team->value : $this->app;
    }
}
