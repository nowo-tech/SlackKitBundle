<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Api;

use Nowo\SlackKitBundle\Messenger\RateLimitRetryMiddleware;
use Throwable;

/**
 * Marker of failures worth retrying later (rate limits); {@see RateLimitRetryMiddleware}
 * turns them into a Messenger retry after {@see self::retryAfterSeconds()}.
 */
interface RecoverableSlackException extends Throwable
{
    /**
     * Seconds to wait before trying again.
     */
    public function retryAfterSeconds(): int;
}
