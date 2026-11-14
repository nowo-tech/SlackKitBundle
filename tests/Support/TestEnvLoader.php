<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Support;

use Symfony\Component\DependencyInjection\EnvVarLoaderInterface;

/**
 * Fake environment of the test kernel (no superglobals touched): the OAuth client secret of "Add to Slack".
 */
final class TestEnvLoader implements EnvVarLoaderInterface
{
    /**
     * @return array<string, string>
     */
    public function loadEnvVars(): array
    {
        return ['SLACK_KIT_TEST_CLIENT_SECRET' => Fake::CLIENT_SECRET];
    }
}
