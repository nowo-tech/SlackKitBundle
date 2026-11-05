<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Credentials;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Security\SlackSecret;

/**
 * One app, one installation, stored in an InstanceSettings section (default `slack`) with the keys `bot_token`
 * (encrypted secret), `signing_secret` (encrypted secret), `team_id` and `allow_email_lookup`: the model Symfony
 * Beacon uses today, so adopting the bundle needs no data migration.
 *
 * Revocation clears the stored bot token (a revoked token is dead anyway); {@see self::save()} only stores the
 * workspace id (filled by Test connection).
 */
final readonly class InstanceSettingsInstallationStore implements SlackInstallationStoreInterface
{
    /**
     * @param InstanceSettingsInterface $settings instance settings store (encrypts secrets)
     * @param string $section section name (`credentials.settings_section`)
     * @param string $app the only app this store serves (`default_app`)
     */
    public function __construct(
        private InstanceSettingsInterface $settings,
        private string $section = 'slack',
        private string $app = SlackInstallationKey::DEFAULT_APP,
    ) {
    }

    /**
     * The installation when a well-formed bot token is stored and the key names this app (and its workspace).
     */
    public function find(SlackInstallationKey $key): ?SlackInstallation
    {
        if ($key->app !== $this->app) {
            return null;
        }
        $token = SlackSecret::tryBotToken($this->settings->getSecret($this->section . '.bot_token'));
        if (!$token instanceof SlackSecret) {
            return null;
        }
        $team = SlackTeamId::tryFrom($this->settings->getString($this->section . '.team_id'));
        if ($key->team instanceof SlackTeamId && !$key->team->equals($team)) {
            return null;
        }

        return new SlackInstallation(new SlackInstallationKey($this->app), $token, $team, $this->settings->getBool($this->section . '.allow_email_lookup'));
    }

    /**
     * The stored signing secret of this app.
     */
    public function findSigningSecret(string $app): ?SlackSecret
    {
        return $app === $this->app ? SlackSecret::trySigningSecret($this->settings->getSecret($this->section . '.signing_secret')) : null;
    }

    /**
     * Stores the workspace id of the installation (the token is entered by an administrator).
     */
    public function save(SlackInstallation $installation): void
    {
        if ($installation->key->app !== $this->app || !$installation->teamId instanceof SlackTeamId) {
            return;
        }
        $this->settings->save($this->section, ['team_id' => $installation->teamId->value]);
    }

    /**
     * Clears the stored bot token.
     */
    public function markRevoked(SlackInstallationKey $key, DateTimeImmutable $at): void
    {
        if ($key->app !== $this->app || $this->settings->getSecret($this->section . '.bot_token') === null) {
            return;
        }
        $this->settings->save($this->section, ['bot_token' => null]);
    }
}
