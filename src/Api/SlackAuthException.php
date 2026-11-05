<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Api;

/**
 * The token is not usable: `not_authed`, `invalid_auth`, `account_inactive`, `token_revoked` or `token_expired`.
 */
final class SlackAuthException extends SlackApiException
{
    /** Error codes mapped to this exception. */
    public const CODES = ['not_authed', 'invalid_auth', 'account_inactive', 'token_revoked', 'token_expired'];
}
