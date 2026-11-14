<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Id;

/**
 * A Slack Enterprise Grid organisation id (`E…`), part of an installation key when the workspace belongs to a grid.
 */
final class SlackEnterpriseId extends SlackId
{
    /** Accepted format. */
    public const PATTERN = '/^E[A-Z0-9]{2,30}$/';

    /**
     * {@see self::PATTERN}.
     */
    protected static function pattern(): string
    {
        return self::PATTERN;
    }

    /**
     * `enterprise`.
     */
    protected static function kindName(): string
    {
        return 'enterprise';
    }
}
