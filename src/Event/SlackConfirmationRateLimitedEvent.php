<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Event;

/**
 * A link confirmation, claim or e-mail lookup was refused by a rate limiter; nothing was sent.
 */
final class SlackConfirmationRateLimitedEvent
{
    /**
     * @param string $accountId account id
     * @param string $limiter `confirmation_per_account`, `confirmation_per_target`, `email_lookup_per_account` or `claim_per_account`
     */
    public function __construct(
        public readonly string $accountId,
        public readonly string $limiter,
    ) {
    }
}
