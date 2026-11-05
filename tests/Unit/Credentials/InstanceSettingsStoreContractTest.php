<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Credentials;

use Nowo\SlackKitBundle\Credentials\InstanceSettingsInstallationStore;
use Nowo\SlackKitBundle\Credentials\SlackInstallationStoreInterface;
use Nowo\SlackKitBundle\Test\SlackInstallationStoreContractTestCase;
use Nowo\SlackKitBundle\Tests\Support\FakeInstanceSettings;

/**
 * The shipped contract test case run against the InstanceSettings store. The fake keeps secret fields apart from
 * plain values, as InstanceSettings encrypts them; the plain values are the inspectable backend.
 */
final class InstanceSettingsStoreContractTest extends SlackInstallationStoreContractTestCase
{
    private FakeInstanceSettings $settings;

    protected function createStore(): SlackInstallationStoreInterface
    {
        $this->settings = new FakeInstanceSettings(['slack.team_id' => self::TEAM], ['slack.bot_token' => self::BOT_TOKEN, 'slack.signing_secret' => self::SIGNING_SECRET]);

        return new InstanceSettingsInstallationStore($this->settings);
    }

    protected function rawStorage(): string
    {
        return (string) json_encode($this->settings->values);
    }
}
