<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\BlockKit;

use InvalidArgumentException;
use Nowo\SlackKitBundle\Api\SlackMessage;
use Nowo\SlackKitBundle\BlockKit\BlockKitLimitException;
use Nowo\SlackKitBundle\BlockKit\Blocks;
use Nowo\SlackKitBundle\BlockKit\Mrkdwn;
use Nowo\SlackKitBundle\Id\SlackUserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlocksTest extends TestCase
{
    public function testEscapesUserText(): void
    {
        self::assertSame('&lt;!channel&gt; &amp; &lt;@U1&gt;', Mrkdwn::escape('<!channel> & <@U1>'));
        $section = Blocks::section('<!here> hi', fields: ['<a>', 'b']);
        self::assertSame('&lt;!here&gt; hi', $section['text']['text']);
        self::assertSame('&lt;a&gt;', $section['fields'][0]['text']);
        self::assertSame('<!here>', Blocks::section('<!here>', true)['text']['text']);
    }

    public function testLinksAndMentions(): void
    {
        self::assertSame('<https://example.test/x?a=1|a ¦ &lt;b&gt;>', Mrkdwn::link('https://example.test/x?a=1', 'a | <b>'));
        self::assertSame('<http://example.test/|x>', Mrkdwn::link('http://example.test/', 'x', true));
        self::assertSame('<@U00000001>', Mrkdwn::mention(SlackUserId::fromString('U00000001')));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function badUrls(): iterable
    {
        yield 'http' => ['http://example.test/'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'pipe' => ['https://example.test/|evil'];
        yield 'angle' => ['https://example.test/>'];
        yield 'space' => ['https://example.test/a b'];
        yield 'relative' => ['/path'];
        yield 'non ascii' => ["https://example.test/\u{e9}"];
    }

    #[DataProvider('badUrls')]
    public function testRefusesUnsafeUrls(string $url): void
    {
        $this->expectException(BlockKitLimitException::class);
        Mrkdwn::link($url, 'x');
    }

    public function testBuildersAndLimits(): void
    {
        self::assertSame(['type' => 'divider'], Blocks::divider());
        self::assertSame(150, mb_strlen(Blocks::header(str_repeat('h', 200))['text']['text']));
        self::assertStringEndsWith('…', Blocks::section(str_repeat('s', 3100))['text']['text']);
        self::assertSame('ok', Blocks::truncate('ok', 5));
        self::assertCount(2, Blocks::context(['a', 'b'])['elements']);
        $button = Blocks::button('Resolve', 'beacon.issue.resolve', 'v', 'primary', ['title' => 'T', 'text' => 'X', 'confirm' => 'Yes', 'deny' => 'No']);
        self::assertSame('primary', $button['style']);
        self::assertSame('Yes', $button['confirm']['confirm']['text']);
        self::assertSame('https://example.test/', Blocks::linkButton('Open', 'https://example.test/')['url']);
        self::assertSame('actions', Blocks::actions([$button])['type']);
    }

    /**
     * @return iterable<string, array{0: callable(): mixed}>
     */
    public static function overflows(): iterable
    {
        yield 'fields' => [static fn (): array => Blocks::section('x', fields: array_fill(0, 11, 'f'))];
        yield 'context empty' => [static fn (): array => Blocks::context([])];
        yield 'actions empty' => [static fn (): array => Blocks::actions([])];
        yield 'actions too many' => [static fn (): array => Blocks::actions(array_fill(0, 26, ['type' => 'button']))];
        yield 'action id' => [static fn (): array => Blocks::button('x', 'Bad Id', 'v')];
        yield 'empty value' => [static fn (): array => Blocks::button('x', 'a', '')];
        yield 'long value' => [static fn (): array => Blocks::button('x', 'a', str_repeat('v', 2001))];
        yield 'style' => [static fn (): array => Blocks::button('x', 'a', 'v', 'warning')];
        yield 'link action id' => [static fn (): array => Blocks::linkButton('x', 'https://example.test/', 'Bad')];
        yield 'blocks' => [static fn (): SlackMessage => new SlackMessage('x', array_fill(0, 51, Blocks::divider()))];
    }

    #[DataProvider('overflows')]
    public function testRefusesOverflows(callable $make): void
    {
        $this->expectException(BlockKitLimitException::class);
        $make();
    }

    public function testMessageArguments(): void
    {
        $message = new SlackMessage('fallback', [Blocks::divider()], threadTs: '1.2');
        self::assertSame(['text' => 'fallback', 'unfurl_links' => false, 'unfurl_media' => false, 'blocks' => [['type' => 'divider']], 'thread_ts' => '1.2'], $message->toArguments());
        self::assertArrayNotHasKey('blocks', (new SlackMessage('x'))->toArguments());
        self::assertSame('&lt;b&gt;', SlackMessage::plain('<b>')->blocks[0]['text']['text']);
        $this->expectException(InvalidArgumentException::class);
        new SlackMessage(' ');
    }

    public function testFallbackTextIsEscapedUnlessRaw(): void
    {
        // Audit FS-L3: user data in the notification text cannot ping a channel or disguise a link.
        $message = new SlackMessage('<!channel> <https://evil.example|Reset password> & co');
        self::assertSame('&lt;!channel&gt; &lt;https://evil.example|Reset password&gt; &amp; co', $message->toArguments()['text']);
        $raw = new SlackMessage(Mrkdwn::link('https://example.test/x', 'Open'), rawText: true);
        self::assertSame('<https://example.test/x|Open>', $raw->toArguments()['text']);
    }
}
