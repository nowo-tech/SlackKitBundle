<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Security;

use function is_array;
use function is_string;
use function preg_match;
use function preg_replace;

/**
 * Removes Slack tokens, bearer credentials, incoming-webhook paths and secret form / query values from strings and
 * arrays before they reach a log (contract §12).
 */
final class SecretRedactor
{
    /** Patterns replaced by {@see SlackSecret::REDACTED}. */
    private const PATTERNS = [
        '/\bxox[abpre]-[A-Za-z0-9-]+/',
        '/\bxoxe(?:\.xox[a-z])?-[A-Za-z0-9-]+/',
        '/\bxapp-[A-Za-z0-9-]+/',
        '#hooks\.slack\.com/(?:services|workflows|triggers|actions)/[A-Za-z0-9_/-]+#',
    ];

    /**
     * Redacts one string.
     */
    public static function redact(string $value): string
    {
        foreach (self::PATTERNS as $pattern) {
            $value = (string) preg_replace($pattern, SlackSecret::REDACTED, $value);
        }
        $value = (string) preg_replace('/Bearer\s+\S+/i', 'Bearer ' . SlackSecret::REDACTED, $value);

        return (string) preg_replace('/(?i)\b((?:client_secret|signing_secret|token|code|state)=)[^&\s]+/', '$1' . SlackSecret::REDACTED, $value);
    }

    /**
     * Redacts secret-named keys and every string value, recursively.
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    public static function redactArray(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match('/token|secret|authorization|signature|password|webhook/i', $key) === 1 && (is_string($value) || $value instanceof SlackSecret)) {
                $out[$key] = SlackSecret::REDACTED;
                continue;
            }
            $out[$key] = match (true) {
                is_string($value) => self::redact($value),
                is_array($value)  => self::redactArray($value),
                default           => $value,
            };
        }

        return $out;
    }
}
