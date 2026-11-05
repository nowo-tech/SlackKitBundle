<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Settings;

use Nowo\InstanceSettingsBundle\Section\SectionActionContext;
use Nowo\SlackKitBundle\Settings\SlackSettingsNoticeProviderInterface;
use Nowo\SlackKitBundle\Settings\SlackSettingsSection;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\TranslatableMessage;

final class SlackSettingsSectionTest extends TestCase
{
    public function testHostNoticesFollowTheBuiltInOnes(): void
    {
        $provider = new class implements SlackSettingsNoticeProviderInterface {
            public function getNotices(): iterable
            {
                yield new TranslatableMessage('host.notice');
            }
        };
        $section = new SlackSettingsSection('slack', Fake::store(), [$provider]);
        $notices = iterator_to_array($section->getNotices(new SectionActionContext('slack', Request::create('/'))), false);

        self::assertCount(1, $notices);
        self::assertSame('host.notice', $notices[0]->getMessage());
    }
}
