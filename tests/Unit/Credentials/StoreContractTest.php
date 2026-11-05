<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Credentials;

use Nowo\SlackKitBundle\Credentials\InMemoryInstallationStore;
use Nowo\SlackKitBundle\Credentials\SlackInstallation;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Credentials\SlackInstallationStoreInterface;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Nowo\SlackKitBundle\Test\SlackInstallationStoreContractTestCase;

/**
 * The shipped contract test case run against the in-memory store (no inspectable backend).
 */
final class StoreContractTest extends SlackInstallationStoreContractTestCase
{
    protected function createStore(): SlackInstallationStoreInterface
    {
        return new InMemoryInstallationStore(
            [SlackInstallationKey::DEFAULT_APP => SlackSecret::signingSecret(self::SIGNING_SECRET)],
            [new SlackInstallation(SlackInstallationKey::default(), SlackSecret::botToken(self::BOT_TOKEN), SlackTeamId::fromString(self::TEAM))],
        );
    }
}
