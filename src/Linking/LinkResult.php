<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Linking;

use function in_array;

/**
 * Outcome of an account-linking step. No result reveals which account holds an id.
 */
enum LinkResult: string
{
    case Claimed            = 'claimed';
    case Found              = 'found';
    case NotFound           = 'not_found';
    case InUse              = 'in_use';
    case Invalid            = 'invalid';
    case AlreadyVerified    = 'already_verified';
    case Unavailable        = 'unavailable';
    case RateLimited        = 'rate_limited';
    case ConfirmationSent   = 'confirmation_sent';
    case ConfirmationFailed = 'confirmation_failed';
    case Unlinked           = 'unlinked';

    /**
     * Translation key of the flash message (domain `NowoSlackKitBundle`).
     */
    public function translationKey(): string
    {
        return 'link.result.' . $this->value;
    }

    /**
     * Whether the step succeeded (flash type `success`).
     */
    public function isSuccess(): bool
    {
        return in_array($this, [self::Claimed, self::Found, self::AlreadyVerified, self::ConfirmationSent, self::Unlinked], true);
    }
}
