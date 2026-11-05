<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Id;

/**
 * A Slack workspace (team) or Enterprise Grid id (`T…` or `E…`).
 */
final class SlackTeamId extends SlackId
{
    /** Accepted format. */
    public const PATTERN = '/^[TE][A-Z0-9]{2,30}$/';

    /**
     * {@see self::PATTERN}.
     */
    protected static function pattern(): string
    {
        return self::PATTERN;
    }

    /**
     * `workspace`.
     */
    protected static function kindName(): string
    {
        return 'workspace';
    }
}
