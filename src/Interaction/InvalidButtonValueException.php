<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Interaction;

use RuntimeException;

/**
 * A button value refused by {@see ButtonValueCodec}. The message is the reason code only, never the value
 * (contract §6.5.4).
 */
final class InvalidButtonValueException extends RuntimeException
{
    /** The value does not have the `nsk1` shape, or uses an unknown `nsk` version. */
    public const MALFORMED = 'malformed';

    /** No key produces the MAC. */
    public const BAD_MAC = 'bad_mac';

    /** The MAC verifies but the payload is not a valid `nsk1` payload. */
    public const BAD_PAYLOAD = 'bad_payload';

    /** `exp` is in the past. */
    public const EXPIRED = 'expired';

    /** The payload names another workspace than the click's. */
    public const TEAM_MISMATCH = 'team_mismatch';

    /** The data cannot be encoded (type, key, depth or size). */
    public const UNENCODABLE = 'unencodable';

    /**
     * @param string $reason one of the reason constants
     */
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
