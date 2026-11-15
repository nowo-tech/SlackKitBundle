<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Linking;

use JsonException;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Interaction\ButtonValueCodec;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Psr\Clock\ClockInterface;
use SensitiveParameter;

use function count;
use function explode;
use function hash_equals;
use function hash_hkdf;
use function hash_hmac;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;
use function random_bytes;

use const JSON_THROW_ON_ERROR;

/**
 * Signs and verifies link-from-click tokens (spec 237 FR-011): `<payload>.<mac>`, base64url, HMAC-SHA256 with a key
 * derived from `kernel.secret` (its own HKDF info, so a button value can never pass as a link token), a lifetime of at
 * most 10 minutes and a random nonce (single use is enforced by {@see ClickLinker} with a lock).
 *
 * @internal used by {@see ClickLinker}; not a public API
 */
final readonly class ClickLinkSigner
{
    /** Largest lifetime of a token. */
    public const MAX_TTL = 600;

    /** Token format (route requirement). */
    public const PATTERN = '[A-Za-z0-9_-]{20,400}\.[A-Za-z0-9_-]{43}';

    /** HKDF info of the key. */
    private const KEY_INFO = 'nowo_slack_kit.link_click.v1';

    /**
     * @param SlackSecret $key key ({@see self::deriveKey()})
     * @param ClockInterface $clock clock
     * @param int $ttl lifetime in seconds (at most {@see self::MAX_TTL})
     */
    public function __construct(
        private SlackSecret $key,
        private ClockInterface $clock,
        private int $ttl = self::MAX_TTL,
    ) {
    }

    /**
     * The key derived from `kernel.secret`.
     */
    public static function deriveKey(#[SensitiveParameter] string $kernelSecret): SlackSecret
    {
        return SlackSecret::key(hash_hkdf('sha256', $kernelSecret, 32, self::KEY_INFO, ''));
    }

    /**
     * A token for this member of this workspace through this installation.
     */
    public function issue(SlackInstallationKey $installation, SlackTeamId $team, SlackUserId $user): string
    {
        $payload = ButtonValueCodec::base64UrlEncode((string) json_encode([
            'a' => $installation->app,
            't' => $team->value,
            'u' => $user->value,
            'e' => $this->clock->now()->getTimestamp() + $this->ttl,
            'n' => ButtonValueCodec::base64UrlEncode(random_bytes(16)),
        ]));

        return $payload . '.' . $this->mac($payload);
    }

    /**
     * The token, or null when it is malformed, forged or expired.
     */
    public function verify(string $token): ?ClickLinkToken
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || preg_match('/^' . self::PATTERN . '$/', $token) !== 1 || !hash_equals($this->mac($parts[0]), $parts[1])) {
            return null;
        }
        try {
            $data = json_decode((string) ButtonValueCodec::base64UrlDecode($parts[0]), true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($data) || !is_string($data['a'] ?? null) || preg_match(SlackInstallationKey::APP_PATTERN, $data['a']) !== 1
            || !is_int($data['e'] ?? null) || $data['e'] <= $this->clock->now()->getTimestamp() || !is_string($data['n'] ?? null)) {
            return null;
        }
        $team = SlackTeamId::tryFrom($data['t'] ?? null);
        $user = SlackUserId::tryFrom($data['u'] ?? null);

        return $team instanceof SlackTeamId && $user instanceof SlackUserId
            ? new ClickLinkToken(new SlackInstallationKey($data['a'], $team), $team, $user, $data['e'], $data['n'])
            : null;
    }

    private function mac(string $payload): string
    {
        return ButtonValueCodec::base64UrlEncode(hash_hmac('sha256', $payload, $this->key->reveal(), true));
    }
}
