<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Id;

/**
 * A Slack member id (`U…` or `W…`).
 */
final class SlackUserId extends SlackId
{
    /** Accepted format. */
    public const PATTERN = '/^[UW][A-Z0-9]{2,30}$/';

    /**
     * {@see self::PATTERN}.
     */
    protected static function pattern(): string
    {
        return self::PATTERN;
    }

    /**
     * `member`.
     */
    protected static function kindName(): string
    {
        return 'member';
    }
}
