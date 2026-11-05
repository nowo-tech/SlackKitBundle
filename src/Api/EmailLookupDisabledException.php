<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Api;

/**
 * `users.lookupByEmail` was called for an installation that does not allow it.
 */
final class EmailLookupDisabledException extends SlackApiException
{
    /**
     * Code `email_lookup_disabled`.
     */
    public function __construct()
    {
        parent::__construct('email_lookup_disabled');
    }
}
