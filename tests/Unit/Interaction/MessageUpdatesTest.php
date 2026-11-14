<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Interaction;

use Nowo\SlackKitBundle\Api\ResponseUrlClientInterface;
use Nowo\SlackKitBundle\Api\SlackApiException;
use Nowo\SlackKitBundle\Api\SlackMessage;
use Nowo\SlackKitBundle\Api\SlackResponseUrlReply;
use Nowo\SlackKitBundle\BlockKit\Blocks;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Id\SlackChannelId;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Interaction\ReplyDispatcher;
use Nowo\SlackKitBundle\Interaction\SlackInteraction;
use Nowo\SlackKitBundle\Interaction\SlackInteractionReply;
use Nowo\SlackKitBundle\Interaction\SlackOriginalMessage;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use Nowo\SlackKitBundle\Tests\Support\Ingress;
use Nowo\SlackKitBundle\Tests\Support\RecordingApi;
use Nowo\SlackKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Spec 237 T000c: a handler result updates the clicked message (`chat.update`) or answers through `response_url`.
 */
final class MessageUpdatesTest extends TestCase
{
    public function testTheRouterHandsTheOriginalMessageAndResponseUrlToTheHandler(): void
    {
        $ingress                 = new Ingress();
        $payload                 = Fake::blockActions('demo.ping', $ingress->codec->sign('demo.ping', [], SlackTeamId::fromString(Fake::TEAM)));
        $payload['message']      = ['text' => 'New issue', 'blocks' => [Blocks::section('New issue'), Blocks::actions([Blocks::button('Ping', 'demo.ping', 'v')])]];
        $payload['response_url'] = Fake::RESPONSE_URL;
        $body                    = Fake::form($payload);

        $response = $ingress->controller->interactions(Request::create('/slack/interactions', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'] + Fake::signed($body), $body));

        self::assertSame(200, $response->getStatusCode());
        $seen = $ingress->anonymousHandler->seen[0];
        self::assertSame('New issue', $seen->message?->text);
        self::assertCount(2, $seen->message->blocks);
        self::assertSame(SlackSecret::RESPONSE_URL, $seen->responseUrl?->kind);
        self::assertSame(Fake::RESPONSE_URL, $seen->responseUrl->reveal());
        self::assertSame(SlackSecret::REDACTED, (string) $seen->responseUrl);

        $payload['response_url']            = 'https://evil.example/actions/x';
        $payload['actions'][0]['action_ts'] = '1793836801.000300';
        $body                               = Fake::form($payload);
        $ingress->controller->interactions(Request::create('/slack/interactions', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'] + Fake::signed($body), $body));
        self::assertNull($ingress->anonymousHandler->seen[1]->responseUrl, 'only hooks.slack.com/actions URLs are kept');
    }

    public function testWithSpentButtonsReplacesTheOriginalThroughChatUpdate(): void
    {
        $api        = new RecordingApi();
        $dispatcher = new ReplyDispatcher($api, new RecordingLogger());
        $click      = $this->interaction(new SlackOriginalMessage('New issue', [Blocks::section('New issue'), Blocks::actions([Blocks::button('Assign to me', 'beacon.issue.assign', 'v'), Blocks::button('Resolve', 'beacon.issue.resolve', 'w')])]));

        $reply = SlackInteractionReply::done('Done')->withSpentButtons($click, ['beacon.issue.assign'], 'Assigned to Ana');
        $dispatcher->schedule($click, $reply);
        $dispatcher->flush();

        self::assertSame(['chat.update'], $api->methods());
        self::assertSame(['default:' . Fake::OTHER_TEAM], $api->keys);
        $message = $api->calls[0][1][2];
        self::assertInstanceOf(SlackMessage::class, $message);
        self::assertSame(['beacon.issue.resolve'], array_column($message->blocks[1]['elements'], 'action_id'));
        self::assertSame('context', $message->blocks[2]['type']);

        $noMessage = $this->interaction(null);
        self::assertSame($reply->status, SlackInteractionReply::done()->withSpentButtons($noMessage)->status);
        self::assertNull(SlackInteractionReply::done()->withSpentButtons($noMessage)->replacement, 'no message on the click: nothing to replace');
    }

    public function testViaResponseUrlNeedsNoTokenAndWorksForResolverVerifiedClicks(): void
    {
        $api       = new RecordingApi();
        $responses = new class implements ResponseUrlClientInterface {
            /** @var list<SlackResponseUrlReply> */
            public array $sent = [];

            public ?string $fail = null;

            public function respond(SlackSecret $responseUrl, SlackResponseUrlReply $reply): void
            {
                $this->sent[] = $reply;
                if ($this->fail !== null) {
                    throw new SlackApiException($this->fail);
                }
            }
        };
        $logger     = new RecordingLogger();
        $dispatcher = new ReplyDispatcher($api, $logger, $responses);
        $resolver   = $this->interaction(new SlackOriginalMessage('x', []), true);

        $dispatcher->schedule($resolver, SlackInteractionReply::forbidden()->withEphemeral(SlackMessage::plain('No access')));
        self::assertSame(0, $dispatcher->count(), 'resolver-verified clicks still get no Web API answer');

        $dispatcher->schedule($resolver, SlackInteractionReply::done()->withEphemeral(SlackMessage::plain('Only you'))->replaceOriginal(SlackMessage::plain('Done'))->viaResponseUrl());
        $dispatcher->schedule($resolver, SlackInteractionReply::done()->deleteOriginal()->viaResponseUrl());
        $dispatcher->schedule($resolver, SlackInteractionReply::done()->viaResponseUrl());
        self::assertSame(2, $dispatcher->count());
        $dispatcher->flush();

        self::assertSame([], $api->calls, 'no token used');
        self::assertSame([SlackResponseUrlReply::EPHEMERAL, SlackResponseUrlReply::REPLACE, SlackResponseUrlReply::DELETE], array_map(static fn (SlackResponseUrlReply $r): string => $r->mode, $responses->sent));

        // Without a response URL on the click, the Web API answers as before.
        $plain = $this->interaction(new SlackOriginalMessage('x', []), false, false);
        $dispatcher->schedule($plain, SlackInteractionReply::done()->replaceOriginal(SlackMessage::plain('Done'))->viaResponseUrl());
        $dispatcher->flush();
        self::assertSame(['chat.update'], $api->methods());

        // Failures are logged with their code only.
        $responses->fail = 'expired_url';
        $dispatcher->schedule($resolver, SlackInteractionReply::done()->deleteOriginal()->viaResponseUrl());
        $dispatcher->flush();
        self::assertSame('expired_url', $logger->records[0]['context']['error'] ?? null);
        self::assertStringNotContainsString('fakefakefakefake', (string) json_encode($logger->records));

        $dispatcher->schedule($resolver, SlackInteractionReply::done()->deleteOriginal()->viaResponseUrl());
        $dispatcher->reset();
        self::assertSame(0, $dispatcher->count());
    }

    public function testRepliesKeepTheResponseUrlChoiceThroughTheBuilders(): void
    {
        $reply = SlackInteractionReply::done()->viaResponseUrl()->withEphemeral(SlackMessage::plain('a'))->replaceOriginal(SlackMessage::plain('b'));
        self::assertTrue($reply->viaResponseUrl);
        self::assertTrue($reply->deleteOriginal()->viaResponseUrl);
        self::assertFalse(SlackInteractionReply::done()->viaResponseUrl);
    }

    private function interaction(?SlackOriginalMessage $message, bool $byResolver = false, bool $withUrl = true): SlackInteraction
    {
        return new SlackInteraction(
            new SlackInstallationKey('default', SlackTeamId::fromString(Fake::OTHER_TEAM)),
            'beacon.issue.assign',
            [],
            SlackTeamId::fromString(Fake::OTHER_TEAM),
            SlackUserId::fromString(Fake::OTHER_USER),
            null,
            SlackChannelId::fromString(Fake::CHANNEL),
            '1793836800.000100',
            'id',
            false,
            $byResolver,
            $message,
            $withUrl ? SlackSecret::responseUrl(Fake::RESPONSE_URL) : null,
        );
    }
}
