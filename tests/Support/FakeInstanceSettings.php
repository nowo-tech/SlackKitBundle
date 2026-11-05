<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Support;

use LogicException;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;

use function array_key_exists;
use function array_keys;
use function is_bool;
use function is_string;

/**
 * In-memory {@see InstanceSettingsInterface}: plain values and secrets keyed by `<section>.<field>`; counts reads.
 */
final class FakeInstanceSettings implements InstanceSettingsInterface
{
    public int $reads = 0;

    /**
     * @param array<string, bool|float|int|string|null> $values
     * @param array<string, string> $secrets
     */
    public function __construct(
        public array $values = [],
        public array $secrets = [],
    ) {
    }

    public function get(string $key): bool|float|int|string|null
    {
        ++$this->reads;

        return $this->values[$key] ?? null;
    }

    public function getString(string $key): ?string
    {
        $value = $this->get($key);

        return is_string($value) ? $value : null;
    }

    public function getInt(string $key): ?int
    {
        throw new LogicException('Not used.');
    }

    public function getFloat(string $key): ?float
    {
        throw new LogicException('Not used.');
    }

    public function getBool(string $key): bool
    {
        return ($this->values[$key] ?? null) === true;
    }

    public function getSecret(string $key): ?string
    {
        ++$this->reads;

        return $this->secrets[$key] ?? null;
    }

    public function hasSecret(string $key): bool
    {
        return array_key_exists($key, $this->secrets);
    }

    public function isOverridden(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function getSection(string $section): array
    {
        return $this->values;
    }

    public function save(string $section, array $values, string $origin = SettingsChangedEvent::ORIGIN_API): array
    {
        foreach ($values as $name => $value) {
            if (array_key_exists($section . '.' . $name, $this->secrets)) {
                if ($value === null) {
                    unset($this->secrets[$section . '.' . $name]);
                } else {
                    $this->secrets[$section . '.' . $name] = (string) $value;
                }
                continue;
            }
            $this->values[$section . '.' . $name] = is_string($value) || is_bool($value) ? $value : null;
        }

        return array_keys($values);
    }
}
