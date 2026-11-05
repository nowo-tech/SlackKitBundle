<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Id;

/**
 * A Slack conversation id: channel, direct message or group (`C…`, `D…`, `G…`).
 */
final class SlackChannelId extends SlackId
{
    /** Accepted format. */
    public const PATTERN = '/^[CDG][A-Z0-9]{2,30}$/';

    /**
     * {@see self::PATTERN}.
     */
    protected static function pattern(): string
    {
        return self::PATTERN;
    }

    /**
     * `conversation`.
     */
    protected static function kindName(): string
    {
        return 'conversation';
    }
}
