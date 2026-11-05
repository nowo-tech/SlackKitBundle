<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Api;

use Nowo\SlackKitBundle\Security\SecretRedactor;
use RuntimeException;
use Throwable;

/**
 * The `previous` of a `transport_error`: the HttpClient failure's class and message passed through
 * {@see SecretRedactor}, with no further chain, so an incoming-webhook URL or a token
 * in a vendor message never reaches logs, the Messenger failure transport or error pages (audit FS-L2, contract §12).
 */
final class RedactedTransportException extends RuntimeException
{
    /**
     * Wraps the failure without keeping it.
     */
    public static function from(Throwable $failure): self
    {
        return new self($failure::class . ': ' . SecretRedactor::redact($failure->getMessage()));
    }
}
