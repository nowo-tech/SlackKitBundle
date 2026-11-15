<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Linking;

use InvalidArgumentException;

use function mb_strtolower;
use function str_contains;
use function strlen;
use function trim;

/**
 * Does this account own this e-mail, verified? Asked by link-from-click with the Slack member's e-mail (spec 237
 * FR-012). The e-mail is trimmed and lower-cased; it is never logged.
 */
final readonly class SlackVerifiedEmailCheck
{
    /** The e-mail, trimmed and lower-cased. */
    public string $email;

    /**
     * @param string $accountId host account (opaque)
     * @param string $email the Slack member's e-mail
     *
     * @throws InvalidArgumentException when the e-mail is empty, longer than 254 characters or has no `@`
     */
    public function __construct(
        public string $accountId,
        string $email,
    ) {
        $normalised = mb_strtolower(trim($email));
        if ($normalised === '' || strlen($normalised) > 254 || !str_contains($normalised, '@')) {
            throw new InvalidArgumentException('Invalid e-mail.');
        }
        $this->email = $normalised;
    }
}
