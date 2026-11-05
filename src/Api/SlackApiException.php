<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Api;

use RuntimeException;
use Throwable;

use function preg_match;

/**
 * A Slack Web API or incoming-webhook failure. The message is `Slack API error: <code>` and nothing else: never a
 * token, a request body or an arbitrary string from Slack (contract §4.3).
 */
class SlackApiException extends RuntimeException
{
    /** Stable error code (`ok:false` error, `transport_error`, `invalid_response`, `not_installed`…). */
    public readonly string $errorCode;

    /**
     * @param string $errorCode Slack error code; anything outside `^[a-z0-9_]{1,64}$` becomes `unknown_error`
     * @param Throwable|null $previous transport or decoding failure
     */
    public function __construct(string $errorCode, ?Throwable $previous = null)
    {
        $this->errorCode = preg_match('/^[a-z0-9_]{1,64}$/', $errorCode) === 1 ? $errorCode : 'unknown_error';
        parent::__construct('Slack API error: ' . $this->errorCode, 0, $previous);
    }
}
