<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Interaction;

use DateTimeImmutable;
use JsonException;
use Psr\Clock\ClockInterface;

use function is_array;
use function is_string;
use function json_decode;
use function preg_match;
use function strlen;
use function substr;

use const JSON_THROW_ON_ERROR;

/**
 * Legacy unsigned JSON values (contract §6.5.8): accepted only while `legacy_until` is in the future, never for a value
 * of the `nsk` family, and only when the legacy key names a mapped action.
 */
final readonly class LegacyValuePolicy
{
    private ?DateTimeImmutable $until;

    /**
     * @param string|null $key `interactions.legacy_value_key` (null: legacy mode off)
     * @param array<string, string> $actionMap legacy action name → `action_id`
     * @param DateTimeImmutable|string|null $until `interactions.legacy_until` (exclusive); a string is parsed by
     *                                             {@see self::parseUntil()} (invalid: legacy off)
     * @param ClockInterface $clock clock
     */
    public function __construct(
        private ?string $key,
        private array $actionMap,
        DateTimeImmutable|string|null $until,
        private ClockInterface $clock,
    ) {
        $this->until = is_string($until) ? self::parseUntil($until) : $until;
    }

    /**
     * `interactions.legacy_until` as an instant: only a strict UTC timestamp `YYYY-MM-DDTHH:MM:SS` with `Z` or
     * `+00:00` (audit FS-L4). Anything else (relative strings, zone-less or empty values) is null: legacy mode off.
     */
    public static function parseUntil(?string $value): ?DateTimeImmutable
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|\+00:00)$/', $value) !== 1) {
            return null;
        }
        $instant = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);

        return $instant instanceof DateTimeImmutable && $instant->format('Y-m-d\TH:i:s') === substr($value, 0, 19) ? $instant : null;
    }

    /**
     * Whether legacy values are accepted right now.
     */
    public function isActive(): bool
    {
        return $this->key !== null && $this->isWindowOpen();
    }

    /**
     * Whether `legacy_until` is set and still in the future, whatever `legacy_value_key` says. Signed link
     * confirmations without the claim time `c` (Beacon 1.122.1 interim format) are accepted only while it is.
     */
    public function isWindowOpen(): bool
    {
        return $this->until instanceof DateTimeImmutable && $this->clock->now() < $this->until;
    }

    /**
     * The mapped `action_id` and the decoded value, or null when the value is not an acceptable legacy value.
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    public function resolve(string $value): ?array
    {
        if (!$this->isActive() || ButtonValueCodec::isSignedFamily($value) || strlen($value) > 2000) {
            return null;
        }
        try {
            $decoded = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        $name = is_array($decoded) ? ($decoded[(string) $this->key] ?? null) : null;
        if (!is_string($name) || !isset($this->actionMap[$name])) {
            return null;
        }

        /* @var array<string, mixed> $decoded */
        return [$this->actionMap[$name], $decoded];
    }
}
