<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\BlockKit;

use Nowo\SlackKitBundle\Api\SlackMessage;
use Nowo\SlackKitBundle\Interaction\SlackOriginalMessage;

use function count;
use function in_array;
use function is_array;
use function is_string;
use function trim;

/**
 * Rebuilds a clicked message without its spent buttons and with a context line such as "Assigned to Ana" (spec 237
 * T000c, FR-016), for `chat.update` or a `response_url` replacement. Pure: no I/O, no state.
 *
 * Interactive elements (buttons, menus…) whose `action_id` is spent are removed from `actions` blocks and from section
 * accessories; an `actions` block left empty is dropped. Every other block is kept as Slack sent it.
 */
final class MessageUpdate
{
    /**
     * The original message without the spent elements, plus a context line.
     *
     * @param list<string> $spentActionIds `action_id`s that no longer apply; empty: every interactive element
     * @param string|null $context context line appended as a `context` block (escaped unless `$rawContext`)
     * @param bool $rawContext the context line is already mrkdwn built with {@see Mrkdwn}
     *
     * @throws BlockKitLimitException when the context line would make the message exceed 50 blocks
     */
    public static function withoutSpentButtons(SlackOriginalMessage $original, array $spentActionIds = [], ?string $context = null, bool $rawContext = false): SlackMessage
    {
        $blocks = [];
        foreach ($original->blocks as $block) {
            $kept = self::block($block, $spentActionIds);
            if ($kept !== null) {
                $blocks[] = $kept;
            }
        }
        if ($context !== null && trim($context) !== '') {
            if (count($blocks) >= Blocks::MAX_BLOCKS) {
                throw new BlockKitLimitException('A Slack message holds at most 50 blocks.');
            }
            $blocks[] = Blocks::context([$context], $rawContext);
        }

        if (trim($original->text) !== '') {
            // Slack sends the fallback text already escaped.
            return new SlackMessage($original->text, $blocks, rawText: true);
        }

        return new SlackMessage($context !== null && trim($context) !== '' ? $context : '…', $blocks, rawText: $rawContext);
    }

    /**
     * @param array<string, mixed> $block
     * @param list<string> $spent
     *
     * @return array<string, mixed>|null
     */
    private static function block(array $block, array $spent): ?array
    {
        if ($block['type'] === 'actions') {
            $elements = [];
            foreach (is_array($block['elements'] ?? null) ? $block['elements'] : [] as $element) {
                if (is_array($element) && !self::isSpent($element, $spent)) {
                    $elements[] = $element;
                }
            }
            if ($elements === []) {
                return null;
            }
            $block['elements'] = $elements;

            return $block;
        }
        if (is_array($block['accessory'] ?? null) && self::isSpent($block['accessory'], $spent)) {
            unset($block['accessory']);
        }

        return $block;
    }

    /**
     * @param array<mixed> $element
     * @param list<string> $spent
     */
    private static function isSpent(array $element, array $spent): bool
    {
        $actionId = $element['action_id'] ?? null;
        if (!is_string($actionId)) {
            return false;
        }

        return $spent === [] || in_array($actionId, $spent, true);
    }
}
