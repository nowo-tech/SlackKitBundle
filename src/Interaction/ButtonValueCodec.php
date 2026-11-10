<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Interaction;

use JsonException;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use stdClass;

use function array_is_list;
use function array_key_exists;
use function base64_decode;
use function base64_encode;
use function explode;
use function hash_equals;
use function hash_hkdf;
use function hash_hmac;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function preg_match;
use function rtrim;
use function str_starts_with;
use function strlen;
use function strtr;
use function substr;

use const JSON_FORCE_OBJECT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const SORT_STRING;

/**
 * The normative `nsk1` button-value format (contract §6.5): `nsk1.<payload_b64>.<mac_b64>`, canonical JSON payload
 * `{"d":{…},"exp":<unix>[,"t":"T…"]}`, key = HKDF-SHA256(kernel.secret, info `nowo_slack_kit.button.v1`), MAC = first
 * 16 bytes of HMAC-SHA256(key, "nsk1\n" + action_id + "\n" + payload_b64), base64url without padding, constant-time
 * comparison. Stateless: safe in FrankenPHP workers.
 */
final readonly class ButtonValueCodec implements ButtonValueCodecInterface
{
    /** Version prefix. */
    public const VERSION = 'nsk1';

    /** HKDF `info` of the `nsk1` key. */
    public const KEY_INFO = 'nowo_slack_kit.button.v1';

    /** MAC length in bytes (128 bits). */
    public const MAC_BYTES = 16;

    /** Shortest `kernel.secret` accepted without a warning, in bytes. */
    public const MIN_KERNEL_SECRET_BYTES = 32;

    /** Largest canonical payload JSON a producer may sign, in bytes (§6.5.4). */
    public const MAX_PAYLOAD_JSON_BYTES = 1400;

    /** Whole-value pattern (§6.5.1). */
    public const VALUE_PATTERN = '/^nsk1\.[A-Za-z0-9_-]{2,1972}\.[A-Za-z0-9_-]{22}$/';

    /** `action_id` pattern. */
    public const ACTION_ID_PATTERN = '/^[a-z0-9_.]{1,64}$/';

    /** Payload key pattern (every level). */
    private const KEY_PATTERN = '/^[a-z0-9_]{1,16}$/';

    /** Deepest nesting inside `d` (d itself is level 1). */
    private const MAX_DATA_DEPTH = 3;

    /**
     * @param SlackSecret $key current key ({@see self::deriveKey()})
     * @param list<SlackSecret> $previousKeys verification-only keys of earlier `kernel.secret` values
     * @param ClockInterface $clock clock of `exp`
     * @param int $ttlSeconds default lifetime of a signed value (`interactions.value_ttl`)
     */
    public function __construct(
        private SlackSecret $key,
        private array $previousKeys,
        private ClockInterface $clock,
        private int $ttlSeconds = 2_592_000,
    ) {
    }

    /**
     * HKDF-SHA256 of the secret with the `nsk1` info and an empty salt (§6.5.3).
     */
    public static function deriveKey(#[SensitiveParameter] string $kernelSecret): SlackSecret
    {
        return SlackSecret::key(hash_hkdf('sha256', $kernelSecret, 32, self::KEY_INFO, ''));
    }

    /**
     * {@see self::deriveKey()} for `kernel.secret`, logging a warning when the secret is short (under 32 bytes) or a
     * known placeholder: anyone who knows it can forge every `nsk1` value (audit FS-I11).
     */
    public static function deriveCheckedKey(#[SensitiveParameter] string $kernelSecret, ?LoggerInterface $logger = null): SlackSecret
    {
        $placeholder = preg_match('/^(change.?this|changeme|secret|demo.?secret|test.?secret|thisisnotsecret|ThisTokenIsNotSoSecretChangeIt)/i', $kernelSecret) === 1;
        if ($placeholder || strlen($kernelSecret) < self::MIN_KERNEL_SECRET_BYTES) {
            $logger?->warning('kernel.secret is short or a placeholder: signed Slack button values can be forged. Use a random value of at least 32 bytes.', ['reason' => 'weak_kernel_secret']);
        }

        return self::deriveKey($kernelSecret);
    }

    /**
     * Signs data for a button, valid for `$ttlSeconds` (default `interactions.value_ttl`).
     *
     * @param string $actionId the button's `action_id`
     * @param array<string, mixed> $data the handler's data: strings, integers, booleans and nested objects (depth ≤ 3)
     * @param SlackTeamId|null $team the workspace the message goes to, when known
     *
     * @throws InvalidButtonValueException `unencodable` when the data, the action id or the size is not allowed
     */
    public function sign(string $actionId, array $data, ?SlackTeamId $team = null, ?int $ttlSeconds = null): string
    {
        return $this->signUntil($actionId, $data, $team, $this->clock->now()->getTimestamp() + ($ttlSeconds ?? $this->ttlSeconds));
    }

    /**
     * Signs data with an explicit expiry (test vectors, host-chosen lifetimes).
     *
     * @param array<string, mixed> $data the handler's data
     *
     * @throws InvalidButtonValueException `unencodable`
     */
    public function signUntil(string $actionId, array $data, ?SlackTeamId $team, int $expiresAt): string
    {
        if (preg_match(self::ACTION_ID_PATTERN, $actionId) !== 1 || !self::encodableObject($data, 1)) {
            throw new InvalidButtonValueException(InvalidButtonValueException::UNENCODABLE);
        }
        $payload = ['d' => $data, 'exp' => $expiresAt];
        if ($team instanceof SlackTeamId) {
            $payload['t'] = $team->value;
        }
        $json = self::canonicalJson($payload);
        if (strlen($json) > self::MAX_PAYLOAD_JSON_BYTES) {
            throw new InvalidButtonValueException(InvalidButtonValueException::UNENCODABLE);
        }
        $encoded = self::base64UrlEncode($json);

        return self::VERSION . '.' . $encoded . '.' . self::base64UrlEncode($this->mac($this->key, $actionId, $encoded));
    }

    /**
     * Verifies a value received in `actions[0].value` (§6.5.4 check order): pattern, MAC (current key, then the
     * previous ones), payload schema, expiry, workspace.
     *
     * @param string $actionId `actions[0].action_id` of the click
     * @param string $value `actions[0].value` of the click
     * @param string|null $clickTeamId `team.id` of the click
     *
     * @throws InvalidButtonValueException with the reason code
     */
    public function verify(string $actionId, string $value, ?string $clickTeamId): ButtonValue
    {
        if (preg_match(self::VALUE_PATTERN, $value) !== 1 || preg_match(self::ACTION_ID_PATTERN, $actionId) !== 1) {
            throw new InvalidButtonValueException(InvalidButtonValueException::MALFORMED);
        }
        [, $encoded, $encodedMac] = explode('.', $value);
        $json                     = self::base64UrlDecode($encoded);
        $mac                      = self::base64UrlDecode($encodedMac);
        if ($json === null || $mac === null) {
            throw new InvalidButtonValueException(InvalidButtonValueException::MALFORMED);
        }

        $valid = false;
        foreach ([$this->key, ...$this->previousKeys] as $key) {
            // Every key is tried (no early exit on a match position that would time the key index).
            $valid = hash_equals($this->mac($key, $actionId, $encoded), $mac) || $valid;
        }
        if (!$valid) {
            throw new InvalidButtonValueException(InvalidButtonValueException::BAD_MAC);
        }

        [$data, $expiresAt, $teamId] = $this->decodePayload($json);
        if ($expiresAt <= $this->clock->now()->getTimestamp()) {
            throw new InvalidButtonValueException(InvalidButtonValueException::EXPIRED);
        }
        if ($teamId !== null && $teamId !== $clickTeamId) {
            throw new InvalidButtonValueException(InvalidButtonValueException::TEAM_MISMATCH);
        }

        return new ButtonValue($actionId, $data, $expiresAt, $teamId);
    }

    /**
     * Whether a value claims the `nsk` family (signed values never fall back to legacy, §6.5.7).
     */
    public static function isSignedFamily(string $value): bool
    {
        return str_starts_with($value, 'nsk');
    }

    /**
     * Canonical JSON (§6.5.2): keys sorted by bytes at every level, no whitespace, UTF-8 and `/` unescaped, objects
     * always as objects.
     *
     * @param array<string, mixed> $payload
     */
    public static function canonicalJson(array $payload): string
    {
        return json_encode(self::sortKeys($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR);
    }

    /**
     * Base64url without padding (RFC 4648 §5).
     */
    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Strict base64url decoding: no padding, valid length and canonical trailing bits; null otherwise.
     */
    public static function base64UrlDecode(string $encoded): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]*$/', $encoded) !== 1 || strlen($encoded) % 4 === 1) {
            return null;
        }
        $bytes = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($bytes === false || self::base64UrlEncode($bytes) !== $encoded) {
            return null;
        }

        return $bytes;
    }

    private function mac(SlackSecret $key, string $actionId, string $encodedPayload): string
    {
        return substr(hash_hmac('sha256', self::VERSION . "\n" . $actionId . "\n" . $encodedPayload, $key->reveal(), true), 0, self::MAC_BYTES);
    }

    /**
     * @return array{0: array<string, mixed>, 1: int, 2: string|null}
     */
    private function decodePayload(string $json): array
    {
        try {
            $decoded = json_decode($json, false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidButtonValueException(InvalidButtonValueException::BAD_PAYLOAD);
        }
        if (!$decoded instanceof stdClass) {
            throw new InvalidButtonValueException(InvalidButtonValueException::BAD_PAYLOAD);
        }
        $payload = (array) $decoded;
        foreach ($payload as $key => $unused) {
            if (!in_array($key, ['d', 'exp', 't'], true)) {
                throw new InvalidButtonValueException(InvalidButtonValueException::BAD_PAYLOAD);
            }
        }
        $data = isset($payload['d']) && $payload['d'] instanceof stdClass ? self::objectToArray($payload['d'], 1) : null;
        $exp  = $payload['exp'] ?? null;
        $team = $payload['t'] ?? null;
        if ($data === null || !is_int($exp) || (array_key_exists('t', $payload) && (!is_string($team) || preg_match(SlackTeamId::PATTERN, $team) !== 1))) {
            throw new InvalidButtonValueException(InvalidButtonValueException::BAD_PAYLOAD);
        }

        return [$data, $exp, is_string($team) ? $team : null];
    }

    /**
     * @return array<string, mixed>|null null when a value has a type `nsk1` does not allow
     */
    private static function objectToArray(stdClass $object, int $depth): ?array
    {
        $out = [];
        foreach ((array) $object as $key => $value) {
            if (preg_match(self::KEY_PATTERN, (string) $key) !== 1) {
                return null;
            }
            if ($value instanceof stdClass) {
                if ($depth >= self::MAX_DATA_DEPTH) {
                    return null;
                }
                $value = self::objectToArray($value, $depth + 1);
                if ($value === null) {
                    return null;
                }
            } elseif (!is_string($value) && !is_int($value) && !is_bool($value)) {
                return null;
            }
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @param array<mixed> $data
     */
    private static function encodableObject(array $data, int $depth): bool
    {
        if ($data !== [] && array_is_list($data)) {
            return false;
        }
        foreach ($data as $key => $value) {
            if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
                return false;
            }
            if (is_array($value)) {
                if ($depth >= self::MAX_DATA_DEPTH || !self::encodableObject($value, $depth + 1)) {
                    return false;
                }
            } elseif (!is_string($value) && !is_int($value) && !is_bool($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private static function sortKeys(array $value): array
    {
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortKeys($item);
            }
        }

        return $value;
    }
}
