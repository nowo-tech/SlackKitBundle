<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Linking;

use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Interaction\ButtonValue;
use Nowo\SlackKitBundle\Interaction\ButtonValueCodecInterface;
use Nowo\SlackKitBundle\Interaction\SlackInteraction;
use Nowo\SlackKitBundle\Interaction\SlackInteractionReply;
use Nowo\SlackKitBundle\Linking\AccountLinkerInterface;
use Nowo\SlackKitBundle\Linking\ConfirmLinkHandler;
use Nowo\SlackKitBundle\Linking\LinkResult;
use Nowo\SlackKitBundle\Linking\SlackLink;
use PHPUnit\Framework\TestCase;

/**
 * 1.2 (DDD P2): the bundle runs against host implementations of the linker and codec ports.
 */
final class HostPortImplementationsTest extends TestCase
{
    public function testConfirmHandlerDelegatesToAHostLinker(): void
    {
        $linker = new class implements AccountLinkerInterface {
            public ?SlackInteraction $confirmed = null;

            public function linkOf(string $accountId): ?SlackLink
            {
                return null;
            }

            public function canFindByEmail(): bool
            {
                return false;
            }

            public function claim(string $accountId, string $slackUserId): LinkResult
            {
                return LinkResult::RateLimited;
            }

            public function findByEmail(string $accountId): LinkResult
            {
                return LinkResult::RateLimited;
            }

            public function sendConfirmation(string $accountId): LinkResult
            {
                return LinkResult::RateLimited;
            }

            public function confirm(SlackInteraction $interaction): SlackInteractionReply
            {
                $this->confirmed = $interaction;

                return SlackInteractionReply::done('host');
            }

            public function unlink(string $accountId): LinkResult
            {
                return LinkResult::RateLimited;
            }
        };

        $interaction = new SlackInteraction(new SlackInstallationKey(), 'nowo_slack_kit.link.confirm', [], null, null, null, null, null, 'i-1');
        $reply       = (new ConfirmLinkHandler($linker))->handle($interaction);

        self::assertSame($interaction, $linker->confirmed);
        self::assertEquals(SlackInteractionReply::done('host'), $reply);
    }

    public function testAnApplicationServiceCanUseACodecDouble(): void
    {
        $codec = new class implements ButtonValueCodecInterface {
            public function sign(string $actionId, array $data, ?SlackTeamId $team = null, ?int $ttlSeconds = null): string
            {
                return 'signed:' . $actionId . ':' . json_encode($data);
            }

            public function signUntil(string $actionId, array $data, ?SlackTeamId $team, int $expiresAt): string
            {
                return 'until:' . $expiresAt;
            }

            public function verify(string $actionId, string $value, ?string $clickTeamId): ButtonValue
            {
                return new ButtonValue($actionId, ['v' => $value], 0, $clickTeamId);
            }
        };

        self::assertSame('signed:acme.approve:{"id":7}', $codec->sign('acme.approve', ['id' => 7]));
        self::assertSame('until:10', $codec->signUntil('acme.approve', [], null, 10));
        self::assertSame(['v' => 'x'], $codec->verify('acme.approve', 'x', 'T1')->data);
    }
}
