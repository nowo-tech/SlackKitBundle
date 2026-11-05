<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Security;

use InvalidArgumentException;
use Nowo\SlackKitBundle\Id\SlackChannelId;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use PHPUnit\Framework\TestCase;

final class SlackIdTest extends TestCase
{
    public function testValidatesEachKind(): void
    {
        self::assertSame('U00000001', (string) SlackUserId::fromString(' U00000001 '));
        self::assertSame('W00000001', SlackUserId::fromString('W00000001')->value);
        self::assertSame('E00000001', SlackTeamId::fromString('E00000001')->value);
        self::assertSame('G00000001', SlackChannelId::fromString('G00000001')->value);
        self::assertNull(SlackUserId::tryFrom('T00000001'));
        self::assertNull(SlackUserId::tryFrom(42));
        self::assertNull(SlackChannelId::tryFrom('<!channel>'));
        self::assertTrue(SlackUserId::fromString('U00000001')->equals(SlackUserId::fromString('U00000001')));
        self::assertFalse(SlackUserId::fromString('U00000001')->equals(null));
        self::assertFalse(SlackTeamId::fromString('T00000001')->equals(SlackTeamId::fromString('T00000002')));
    }

    public function testMessageNamesTheKindOnly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Slack workspace id.');
        SlackTeamId::fromString('nope');
    }

    public function testChannelKindName(): void
    {
        $this->expectExceptionMessage('Invalid Slack conversation id.');
        SlackChannelId::fromString('x');
    }

    public function testMemberKindName(): void
    {
        $this->expectExceptionMessage('Invalid Slack member id.');
        SlackUserId::fromString('x');
    }
}
