<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\BlockKit;

use Nowo\SlackKitBundle\Interaction\ButtonValueCodec;

use function array_map;
use function array_values;
use function count;
use function in_array;
use function mb_strlen;
use function mb_substr;
use function preg_match;
use function strlen;

/**
 * Block Kit builder (contract §9). Text arguments are escaped by default (pass `raw: true` only for text built with
 * {@see Mrkdwn}). Over-long text is truncated with an ellipsis; structural overflows throw
 * {@see BlockKitLimitException}. Pure: no I/O, no state.
 */
final class Blocks
{
    /** Blocks per message. */
    public const MAX_BLOCKS = 50;

    /** Section text length. */
    public const MAX_SECTION_TEXT = 3000;

    /** Header text length. */
    public const MAX_HEADER_TEXT = 150;

    /** Button text length. */
    public const MAX_BUTTON_TEXT = 75;

    /** Button value length. */
    public const MAX_VALUE = 2000;

    /** Elements per actions block. */
    public const MAX_ACTIONS = 25;

    /** Elements per context block. */
    public const MAX_CONTEXT = 10;

    /** Fields per section. */
    public const MAX_FIELDS = 10;

    /** Field text length. */
    public const MAX_FIELD_TEXT = 2000;

    /** Fallback text kept (Slack truncates longer texts). */
    public const MAX_FALLBACK_TEXT = 4000;

    /** `action_id` format. */
    public const ACTION_ID_PATTERN = '/^[a-z0-9_.]{1,64}$/';

    /**
     * A `section` with mrkdwn text, optionally with fields.
     *
     * @param list<string> $fields field texts (escaped unless `$raw`)
     *
     * @return array<string, mixed>
     */
    public static function section(string $text, bool $raw = false, array $fields = []): array
    {
        $block = ['type' => 'section', 'text' => self::mrkdwn($text, $raw, self::MAX_SECTION_TEXT)];
        if ($fields !== []) {
            if (count($fields) > self::MAX_FIELDS) {
                throw new BlockKitLimitException('A section holds at most 10 fields.');
            }
            $block['fields'] = array_map(static fn (string $field): array => self::mrkdwn($field, $raw, self::MAX_FIELD_TEXT), array_values($fields));
        }

        return $block;
    }

    /**
     * A `header` (plain text).
     *
     * @return array<string, mixed>
     */
    public static function header(string $text): array
    {
        return ['type' => 'header', 'text' => self::plain($text, self::MAX_HEADER_TEXT)];
    }

    /**
     * A `context` block of mrkdwn texts.
     *
     * @param list<string> $texts
     *
     * @return array<string, mixed>
     */
    public static function context(array $texts, bool $raw = false): array
    {
        if ($texts === [] || count($texts) > self::MAX_CONTEXT) {
            throw new BlockKitLimitException('A context block holds 1 to 10 elements.');
        }

        return ['type' => 'context', 'elements' => array_map(static fn (string $text): array => self::mrkdwn($text, $raw, self::MAX_FIELD_TEXT), array_values($texts))];
    }

    /**
     * A `divider`.
     *
     * @return array<string, mixed>
     */
    public static function divider(): array
    {
        return ['type' => 'divider'];
    }

    /**
     * An `actions` block.
     *
     * @param list<array<string, mixed>> $elements buttons from {@see self::button()} / {@see self::linkButton()}
     *
     * @return array<string, mixed>
     */
    public static function actions(array $elements): array
    {
        if ($elements === [] || count($elements) > self::MAX_ACTIONS) {
            throw new BlockKitLimitException('An actions block holds 1 to 25 elements.');
        }

        return ['type' => 'actions', 'elements' => array_values($elements)];
    }

    /**
     * A button whose value was signed with {@see ButtonValueCodec::sign()}.
     *
     * @param string|null $style `primary`, `danger` or null
     * @param array{title: string, text: string, confirm: string, deny: string}|null $confirm confirmation dialog
     *
     * @return array<string, mixed>
     */
    public static function button(string $text, string $actionId, string $value, ?string $style = null, ?array $confirm = null): array
    {
        if (preg_match(self::ACTION_ID_PATTERN, $actionId) !== 1) {
            throw new BlockKitLimitException('An action id has 1-64 lowercase letters, digits, dots or underscores.');
        }
        if ($value === '' || strlen($value) > self::MAX_VALUE) {
            throw new BlockKitLimitException('A button value has 1-2000 characters.');
        }
        $button = ['type' => 'button', 'text' => self::plain($text, self::MAX_BUTTON_TEXT), 'action_id' => $actionId, 'value' => $value];
        if ($style !== null) {
            if (!in_array($style, ['primary', 'danger'], true)) {
                throw new BlockKitLimitException('A button style is primary or danger.');
            }
            $button['style'] = $style;
        }
        if ($confirm !== null) {
            $button['confirm'] = [
                'title'   => self::plain($confirm['title'], 100),
                'text'    => self::plain($confirm['text'], 300),
                'confirm' => self::plain($confirm['confirm'], 30),
                'deny'    => self::plain($confirm['deny'], 30),
            ];
        }

        return $button;
    }

    /**
     * A link button (opens a URL; Slack still sends a `block_actions` callback without a value).
     *
     * @return array<string, mixed>
     */
    public static function linkButton(string $text, string $url, string $actionId = 'nowo_slack_kit.link', bool $allowHttp = false): array
    {
        if (preg_match(self::ACTION_ID_PATTERN, $actionId) !== 1) {
            throw new BlockKitLimitException('An action id has 1-64 lowercase letters, digits, dots or underscores.');
        }

        return ['type' => 'button', 'text' => self::plain($text, self::MAX_BUTTON_TEXT), 'action_id' => $actionId, 'url' => Mrkdwn::url($url, $allowHttp)];
    }

    /**
     * Truncates to `$max` characters with an ellipsis.
     */
    public static function truncate(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }

    /**
     * @return array{type: string, text: string}
     */
    private static function mrkdwn(string $text, bool $raw, int $max): array
    {
        return ['type' => 'mrkdwn', 'text' => self::truncate($raw ? $text : Mrkdwn::escape($text), $max)];
    }

    /**
     * @return array{type: string, text: string, emoji: bool}
     */
    private static function plain(string $text, int $max): array
    {
        return ['type' => 'plain_text', 'text' => self::truncate($text, $max), 'emoji' => true];
    }
}
