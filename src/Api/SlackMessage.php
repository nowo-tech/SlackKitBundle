<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Api;

use InvalidArgumentException;
use Nowo\SlackKitBundle\BlockKit\BlockKitLimitException;
use Nowo\SlackKitBundle\BlockKit\Blocks;
use Nowo\SlackKitBundle\BlockKit\Mrkdwn;

use function array_values;
use function count;
use function mb_strlen;
use function mb_substr;
use function preg_replace;
use function trim;

/**
 * A message for `chat.postMessage`, `chat.update`, `chat.postEphemeral` or an incoming webhook: the fallback text
 * (required by Slack for notifications and screen readers) and the Block Kit blocks. Link and media unfurling are off
 * by default. The fallback text is mrkdwn-escaped (`&`, `<`, `>`) when sent, so user data cannot ping `<!channel>` or
 * disguise a link (audit FS-L3); pass `rawText: true` only for text built with {@see Mrkdwn}.
 */
final readonly class SlackMessage
{
    /** @var list<array<string, mixed>> */
    public array $blocks;

    /**
     * @param string $text fallback text (not empty)
     * @param array<array<string, mixed>> $blocks Block Kit blocks (at most 50)
     * @param bool $unfurlLinks let Slack unfurl links
     * @param bool $unfurlMedia let Slack unfurl media
     * @param string|null $threadTs reply in this thread
     * @param bool $rawText send the fallback text as is (already escaped mrkdwn)
     *
     * @throws InvalidArgumentException when the text is empty
     * @throws BlockKitLimitException when there are more than 50 blocks
     */
    public function __construct(
        public string $text,
        array $blocks = [],
        public bool $unfurlLinks = false,
        public bool $unfurlMedia = false,
        public ?string $threadTs = null,
        public bool $rawText = false,
    ) {
        if (trim($text) === '') {
            throw new InvalidArgumentException('A Slack message needs a fallback text.');
        }
        if (count($blocks) > Blocks::MAX_BLOCKS) {
            throw new BlockKitLimitException('A Slack message holds at most 50 blocks.');
        }
        $this->blocks = array_values($blocks);
    }

    /**
     * A section with the (escaped) text as the only block.
     */
    public static function plain(string $text): self
    {
        return new self($text, [Blocks::section($text)]);
    }

    /**
     * The Web API arguments (without `channel`).
     *
     * @return array<string, mixed>
     */
    public function toArguments(): array
    {
        $arguments = ['text' => $this->fallbackText(), 'unfurl_links' => $this->unfurlLinks, 'unfurl_media' => $this->unfurlMedia];
        if ($this->blocks !== []) {
            $arguments['blocks'] = $this->blocks;
        }
        if ($this->threadTs !== null) {
            $arguments['thread_ts'] = $this->threadTs;
        }

        return $arguments;
    }

    /**
     * The fallback text, escaped unless raw, and capped **after** escaping (audit FSD-I4) without leaving a cut
     * entity behind.
     */
    private function fallbackText(): string
    {
        if ($this->rawText) {
            return Blocks::truncate($this->text, Blocks::MAX_FALLBACK_TEXT);
        }
        $escaped = Mrkdwn::escape($this->text);
        if (mb_strlen($escaped) <= Blocks::MAX_FALLBACK_TEXT) {
            return $escaped;
        }

        return preg_replace('/&[a-z]{0,3}$/', '', mb_substr($escaped, 0, Blocks::MAX_FALLBACK_TEXT - 1)) . '…';
    }
}
