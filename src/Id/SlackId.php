<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Id;

use InvalidArgumentException;
use Stringable;

use function is_string;
use function preg_match;
use function sprintf;
use function trim;

/**
 * A validated Slack identifier (member, workspace or conversation). A malformed id never reaches Slack.
 */
abstract class SlackId implements Stringable
{
    final private function __construct(public readonly string $value)
    {
    }

    /**
     * Validates and wraps the id.
     *
     * @throws InvalidArgumentException when the id does not match the kind's pattern
     */
    public static function fromString(string $value): static
    {
        $value = trim($value);
        if (preg_match(static::pattern(), $value) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid Slack %s id.', static::kindName()));
        }

        return new static($value);
    }

    /**
     * Null for a missing or malformed id.
     */
    public static function tryFrom(mixed $value): ?static
    {
        if (!is_string($value)) {
            return null;
        }
        try {
            return static::fromString($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Same id.
     */
    public function equals(?self $other): bool
    {
        return $other instanceof static && $other->value === $this->value;
    }

    /**
     * The id.
     */
    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * The regular expression of the kind.
     */
    abstract protected static function pattern(): string;

    /**
     * The kind's name in messages.
     */
    abstract protected static function kindName(): string;
}
