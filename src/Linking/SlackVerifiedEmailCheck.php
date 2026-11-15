<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Linking;

use InvalidArgumentException;

use function preg_match;
use function strlen;
use function strtolower;
use function trim;

/**
 * Does this account own this e-mail, verified? Asked by link-from-click with the Slack member's e-mail (spec 237
 * FR-012). Only printable-ASCII addresses are accepted (no Unicode look-alikes, no invisible characters, audit R2-L1);
 * the address is trimmed and lower-cased (ASCII only). Compare it with your own ASCII-lower-cased verified addresses.
 * It is never logged.
 */
final readonly class SlackVerifiedEmailCheck
{
    /** Accepted address: printable ASCII, one `@`. */
    public const PATTERN = '/^[\x21-\x3F\x41-\x7E]+@[\x21-\x3F\x41-\x7E]+$/';

    /** The e-mail, trimmed and lower-cased (ASCII). */
    public string $email;

    /**
     * @param string $accountId host account (opaque)
     * @param string $email the Slack member's e-mail
     *
     * @throws InvalidArgumentException when the e-mail is not one printable-ASCII address of at most 254 characters
     */
    public function __construct(
        public string $accountId,
        string $email,
    ) {
        $normalised = strtolower(trim($email, " \t\n\r"));
        if (strlen($normalised) > 254 || preg_match(self::PATTERN, $normalised) !== 1) {
            throw new InvalidArgumentException('Invalid e-mail.');
        }
        $this->email = $normalised;
    }
}
