<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Security;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;
use Stringable;

use function is_string;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function strlen;
use function trim;

/**
 * A Slack credential (bot token, signing secret, button key or incoming-webhook URL) that cannot leak by accident: it
 * casts to `[redacted]`, dumps as `[redacted]`, refuses serialisation, JSON encoding and `var_export`, and is only
 * readable through {@see self::reveal()} (contract §7.3).
 */
final class SlackSecret implements Stringable, JsonSerializable
{
    /** Placeholder shown instead of the value. */
    public const REDACTED = '[redacted]';

    /** Bot token format (`xoxb-…`). */
    public const BOT_TOKEN_PATTERN = '/^xoxb-[A-Za-z0-9-]{10,255}$/';

    /** Signing secret format (Slack issues 32 hexadecimal characters). */
    public const SIGNING_SECRET_PATTERN = '/^[A-Za-z0-9]{16,128}$/';

    /** Incoming-webhook URL format: only `https://hooks.slack.com/services/…`. */
    public const WEBHOOK_URL_PATTERN = '#^https://hooks\.slack\.com/services/[A-Za-z0-9_/-]{10,200}$#';

    /** Refresh token format of token rotation (`xoxe-1-…`). */
    public const REFRESH_TOKEN_PATTERN = '/^xoxe-1-[A-Za-z0-9-]{10,500}$/';

    /** OAuth client secret format (Slack issues 32 hexadecimal characters). */
    public const CLIENT_SECRET_PATTERN = '/^[A-Za-z0-9]{16,128}$/';

    /** Interaction `response_url` format: only `https://hooks.slack.com/actions/…`. */
    public const RESPONSE_URL_PATTERN = '#^https://hooks\.slack\.com/actions/[A-Za-z0-9_/-]{10,300}$#';

    /** Kinds of secret. */
    public const BOT_TOKEN      = 'bot_token';
    public const SIGNING_SECRET = 'signing_secret';
    public const BUTTON_KEY     = 'button_key';
    public const WEBHOOK_URL    = 'webhook_url';
    public const REFRESH_TOKEN  = 'refresh_token';
    public const CLIENT_SECRET  = 'client_secret';
    public const RESPONSE_URL   = 'response_url';

    /** Token prefixes refused as a bot token, by kind name. */
    private const OTHER_TOKENS = [
        'xoxp-' => 'a user token',
        'xapp-' => 'an app-level token',
        'xoxa-' => 'a workspace token',
        'xoxe'  => 'a refresh token',
        'xoxs-' => 'a session token',
        'xoxr-' => 'a refresh token',
    ];

    private function __construct(
        #[SensitiveParameter]
        private readonly string $value,
        public readonly string $kind,
    ) {
    }

    /**
     * A bot token (`xoxb-`); user, app-level and refresh tokens are refused with a message that names the kind only.
     *
     * @throws InvalidArgumentException when the value is not a bot token
     */
    public static function botToken(#[SensitiveParameter] string $value): self
    {
        $value = trim($value);
        foreach (self::OTHER_TOKENS as $prefix => $name) {
            if (str_starts_with($value, $prefix)) {
                throw new InvalidArgumentException(sprintf('The Slack credential is %s, not a bot token (xoxb-).', $name));
            }
        }
        if (preg_match(self::BOT_TOKEN_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('The Slack bot token is malformed (expected xoxb- followed by 10-255 letters, digits or dashes).');
        }

        return new self($value, self::BOT_TOKEN);
    }

    /**
     * A signing secret (16-128 letters or digits).
     *
     * @throws InvalidArgumentException when the value is malformed
     */
    public static function signingSecret(#[SensitiveParameter] string $value): self
    {
        $value = trim($value);
        if (preg_match(self::SIGNING_SECRET_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('The Slack signing secret is malformed (expected 16-128 letters or digits).');
        }

        return new self($value, self::SIGNING_SECRET);
    }

    /**
     * An incoming-webhook URL (`https://hooks.slack.com/services/…`); the URL itself is the credential.
     *
     * @throws InvalidArgumentException when the URL is not a Slack incoming-webhook URL
     */
    public static function webhookUrl(#[SensitiveParameter] string $value): self
    {
        $value = trim($value);
        if (preg_match(self::WEBHOOK_URL_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('The Slack incoming-webhook URL must be https://hooks.slack.com/services/….');
        }

        return new self($value, self::WEBHOOK_URL);
    }

    /**
     * A refresh token of token rotation (`xoxe-1-`).
     *
     * @throws InvalidArgumentException when the value is not a refresh token
     */
    public static function refreshToken(#[SensitiveParameter] string $value): self
    {
        $value = trim($value);
        if (preg_match(self::REFRESH_TOKEN_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('The Slack refresh token is malformed (expected xoxe-1-…).');
        }

        return new self($value, self::REFRESH_TOKEN);
    }

    /**
     * An OAuth client secret (16-128 letters or digits).
     *
     * @throws InvalidArgumentException when the value is malformed
     */
    public static function clientSecret(#[SensitiveParameter] string $value): self
    {
        $value = trim($value);
        if (preg_match(self::CLIENT_SECRET_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('The Slack client secret is malformed (expected 16-128 letters or digits).');
        }

        return new self($value, self::CLIENT_SECRET);
    }

    /**
     * An interaction `response_url` (`https://hooks.slack.com/actions/…`): it lets anyone who holds it answer the click
     * for 30 minutes, so it is handled as a credential.
     *
     * @throws InvalidArgumentException when the URL is not a Slack response URL
     */
    public static function responseUrl(#[SensitiveParameter] string $value): self
    {
        $value = trim($value);
        if (preg_match(self::RESPONSE_URL_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('The Slack response URL must be https://hooks.slack.com/actions/….');
        }

        return new self($value, self::RESPONSE_URL);
    }

    /**
     * Same as {@see self::refreshToken()}, but null for an empty or malformed value (stores and OAuth answers).
     */
    public static function tryRefreshToken(#[SensitiveParameter] ?string $value): ?self
    {
        try {
            return $value === null || trim($value) === '' ? null : self::refreshToken($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Same as {@see self::responseUrl()}, but null for a missing or malformed value.
     */
    public static function tryResponseUrl(#[SensitiveParameter] mixed $value): ?self
    {
        try {
            return is_string($value) && trim($value) !== '' ? self::responseUrl($value) : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * A derived binary key (button values, §6.5.3).
     *
     * @throws InvalidArgumentException when the key is shorter than 32 bytes
     */
    public static function key(#[SensitiveParameter] string $bytes): self
    {
        if (strlen($bytes) < 32) {
            throw new InvalidArgumentException('A Slack Kit key needs at least 32 bytes.');
        }

        return new self($bytes, self::BUTTON_KEY);
    }

    /**
     * Same as {@see self::botToken()}, but null for an empty or malformed value (stores and configuration).
     */
    public static function tryBotToken(#[SensitiveParameter] ?string $value): ?self
    {
        try {
            return $value === null || trim($value) === '' ? null : self::botToken($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Same as {@see self::signingSecret()}, but null for an empty or malformed value (stores and configuration).
     */
    public static function trySigningSecret(#[SensitiveParameter] ?string $value): ?self
    {
        try {
            return $value === null || trim($value) === '' ? null : self::signingSecret($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The plain value. Call it only where the value is sent or used for a MAC, never to log or display it.
     */
    public function reveal(): string
    {
        return $this->value;
    }

    /**
     * `[redacted]`.
     */
    public function __toString(): string
    {
        return self::REDACTED;
    }

    /**
     * Dumps (`var_dump`, VarDumper, profiler) show the kind only.
     *
     * @return array{kind: string, value: string}
     */
    public function __debugInfo(): array
    {
        return ['kind' => $this->kind, 'value' => self::REDACTED];
    }

    /**
     * Refused: secrets are never serialised (caches, sessions, Messenger messages).
     *
     * @throws LogicException always
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A Slack secret cannot be serialised.');
    }

    /**
     * Refused.
     *
     * @param array<string, mixed> $data ignored
     *
     * @throws LogicException always
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A Slack secret cannot be unserialised.');
    }

    /**
     * Refused.
     *
     * @throws LogicException always
     */
    public function jsonSerialize(): mixed
    {
        throw new LogicException('A Slack secret cannot be JSON encoded.');
    }

    /**
     * Refused (`var_export` output would contain the value).
     *
     * @param array<string, mixed> $properties ignored
     *
     * @throws LogicException always
     */
    public static function __set_state(array $properties): self
    {
        throw new LogicException('A Slack secret cannot be exported.');
    }
}
