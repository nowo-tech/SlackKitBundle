<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\BlockKit;

use Nowo\SlackKitBundle\Id\SlackUserId;

use function is_string;
use function parse_url;
use function preg_match;
use function str_replace;
use function strpbrk;

use const PHP_URL_HOST;
use const PHP_URL_SCHEME;

/**
 * Slack mrkdwn helpers: escaping (`&`, `<`, `>`, Slack's own rule) so user text cannot produce `<!channel>`, mentions
 * or disguised links, and links / mentions built only from checked parts.
 */
final class Mrkdwn
{
    /**
     * Escapes `&`, `<` and `>`.
     */
    public static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    /**
     * `<url|label>` with the label escaped. Only `https:` URLs (and `http:` when allowed), without `|`, `<` or `>`.
     *
     * @throws BlockKitLimitException when the URL is not allowed
     */
    public static function link(string $url, string $label, bool $allowHttp = false): string
    {
        return '<' . self::url($url, $allowHttp) . '|' . str_replace('|', '¦', self::escape($label)) . '>';
    }

    /**
     * `<@U…>` for a validated member id.
     */
    public static function mention(SlackUserId $user): string
    {
        return '<@' . $user->value . '>';
    }

    /**
     * Checks a URL for a link or a link button.
     *
     * @throws BlockKitLimitException when the URL is not allowed
     */
    public static function url(string $url, bool $allowHttp = false): string
    {
        $scheme  = parse_url($url, PHP_URL_SCHEME);
        $host    = parse_url($url, PHP_URL_HOST);
        $allowed = $scheme === 'https' || ($allowHttp && $scheme === 'http');
        if (!$allowed || !is_string($host) || $host === '' || strpbrk($url, "|<> \t\r\n") !== false || preg_match('/^[\x21-\x7e]{1,3000}$/', $url) !== 1) {
            throw new BlockKitLimitException('Only absolute https URLs without "|", "<", ">" or spaces can be linked.');
        }

        return $url;
    }
}
