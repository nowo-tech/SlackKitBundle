<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Routing;

use Nowo\SlackKitBundle\Routing\SlackKitRouteLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SlackKitRouteLoaderTest extends TestCase
{
    public function testRefusesASecondLoad(): void
    {
        $loader = new SlackKitRouteLoader(['enabled' => false, 'interactions' => '/slack/interactions', 'events' => null], '/slack-kit', false, false);
        self::assertTrue($loader->supports('.', 'nowo_slack_kit'));
        $loader->load('.', 'nowo_slack_kit');

        $this->expectException(RuntimeException::class);
        $loader->load('.', 'nowo_slack_kit');
    }
}
