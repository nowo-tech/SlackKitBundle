<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\BlockKit;

use Nowo\SlackKitBundle\BlockKit\BlockKitLimitException;
use Nowo\SlackKitBundle\BlockKit\Blocks;
use Nowo\SlackKitBundle\BlockKit\MessageUpdate;
use Nowo\SlackKitBundle\Interaction\SlackOriginalMessage;
use PHPUnit\Framework\TestCase;

/**
 * Spec 237 T000c: the clicked message rebuilt without its spent buttons, plus a context line.
 */
final class MessageUpdateTest extends TestCase
{
    public function testSpentButtonsGoAndAContextLineIsAdded(): void
    {
        $original = new SlackOriginalMessage('New issue &lt;x&gt;', [
            Blocks::section('New issue'),
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => 'Item'], 'accessory' => Blocks::button('Assign', 'beacon.issue.assign', 'v1')],
            ['type' => 'actions', 'block_id' => 'b1', 'elements' => [Blocks::button('Assign to me', 'beacon.issue.assign', 'v1'), Blocks::button('Resolve', 'beacon.issue.resolve', 'v2'), Blocks::linkButton('Open', 'https://example.test/i/1')]],
        ]);

        $message = MessageUpdate::withoutSpentButtons($original, ['beacon.issue.assign'], 'Assigned to <Ana>');
        $args    = $message->toArguments();

        self::assertSame('New issue &lt;x&gt;', $args['text'], 'Slack sent the fallback text escaped: it is kept as is');
        self::assertArrayNotHasKey('accessory', $message->blocks[1]);
        self::assertSame('b1', $message->blocks[2]['block_id']);
        self::assertSame(['beacon.issue.resolve', 'nowo_slack_kit.link'], array_column($message->blocks[2]['elements'], 'action_id'));
        self::assertSame(['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => 'Assigned to &lt;Ana&gt;']]], $message->blocks[3]);
    }

    public function testEmptySpentListRemovesEveryInteractiveElement(): void
    {
        $original = new SlackOriginalMessage('', [
            ['type' => 'actions', 'elements' => [Blocks::button('Resolve', 'beacon.issue.resolve', 'v2'), ['type' => 'static_select', 'action_id' => 'pick'], ['type' => 'image']]],
            ['type' => 'actions', 'elements' => [Blocks::button('Assign', 'beacon.issue.assign', 'v1')]],
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => 'x'], 'accessory' => ['type' => 'image', 'image_url' => 'https://example.test/x.png', 'alt_text' => 'x']],
        ]);

        $message = MessageUpdate::withoutSpentButtons($original, [], 'Resolved by Ana');

        self::assertCount(3, $message->blocks, 'an actions block left empty is dropped');
        self::assertSame([['type' => 'image']], $message->blocks[0]['elements']);
        self::assertArrayHasKey('accessory', $message->blocks[1], 'a non-interactive accessory stays');
        self::assertSame('Resolved by Ana', $message->toArguments()['text'], 'no fallback text: the context line is used');
    }

    public function testRawContextAndNoContext(): void
    {
        $raw = MessageUpdate::withoutSpentButtons(new SlackOriginalMessage('', []), [], '*Done* by <@U00000001>', true);
        self::assertSame('*Done* by <@U00000001>', $raw->toArguments()['text']);
        self::assertSame('*Done* by <@U00000001>', $raw->blocks[0]['elements'][0]['text']);

        $none = MessageUpdate::withoutSpentButtons(new SlackOriginalMessage(' ', [Blocks::divider()]), [], ' ');
        self::assertSame('…', $none->toArguments()['text']);
        self::assertSame([Blocks::divider()], $none->blocks);
    }

    public function testTheContextLineCannotPushPastFiftyBlocks(): void
    {
        $this->expectException(BlockKitLimitException::class);
        MessageUpdate::withoutSpentButtons(new SlackOriginalMessage('x', array_fill(0, 50, Blocks::divider())), [], 'Done');
    }

    public function testOriginalMessageFromPayload(): void
    {
        self::assertNull(SlackOriginalMessage::fromPayload([]));
        $message = SlackOriginalMessage::fromPayload(['message' => ['text' => 'Hi', 'blocks' => [Blocks::divider(), 'junk', ['no' => 'type']]]]);
        self::assertNotNull($message);
        self::assertSame('Hi', $message->text);
        self::assertSame([Blocks::divider()], $message->blocks);
        self::assertSame('', SlackOriginalMessage::fromPayload(['message' => ['blocks' => 'x']])?->text);
    }
}
