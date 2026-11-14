<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Credentials;

use InvalidArgumentException;

use function preg_match;

/**
 * Who may use an installation, in the host's own terms (spec 237 FR-001): a type such as `instance`, `organization`
 * or `personal`, and an opaque id. The bundle never interprets either; it only compares them exactly.
 */
final readonly class SlackOwnerScope
{
    /** Scope type format. */
    public const TYPE_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    /** Scope id format (opaque host id, at most 128 characters, compared exactly). */
    public const ID_PATTERN = '/^[A-Za-z0-9_.:\-]{1,128}$/';

    /**
     * @param string $type scope type (`instance`, `organization`, `personal`…)
     * @param string $id host id of the owner within the type
     *
     * @throws InvalidArgumentException when the type or the id is malformed
     */
    public function __construct(
        public string $type,
        public string $id,
    ) {
        if (preg_match(self::TYPE_PATTERN, $type) !== 1) {
            throw new InvalidArgumentException('Invalid Slack Kit owner scope type.');
        }
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException('Invalid Slack Kit owner scope id.');
        }
    }

    /**
     * Same type and id (exact, case-sensitive).
     */
    public function equals(?self $other): bool
    {
        return $other instanceof self && $other->type === $this->type && $other->id === $this->id;
    }

    /**
     * `<type>:<id>`.
     */
    public function key(): string
    {
        return $this->type . ':' . $this->id;
    }
}
