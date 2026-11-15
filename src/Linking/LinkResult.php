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

    /** 1.4 link-from-click: the identity is linked (e-mail matched, or the installation's opt-in). */
    case Linked = 'linked';

    /** 1.4: the link is malformed, forged, expired or already used. */
    case LinkExpired = 'link_expired';

    /** 1.4: the Slack member's e-mail is not a verified e-mail of the account. */
    case EmailMismatch = 'email_mismatch';

    /** 1.4: Slack does not tell the member's e-mail (no `users:read.email`, or none set). */
    case EmailUnavailable = 'email_unavailable';

    /** 1.4: Slack reports the member's e-mail as not confirmed (`is_email_confirmed: false`). */
    case EmailUnconfirmed = 'email_unconfirmed';

    /** 1.4: the account already holds another identity in that workspace (unlink it first). */
    case AlreadyLinkedInWorkspace = 'already_linked_in_workspace';

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
        return in_array($this, [self::Claimed, self::Found, self::AlreadyVerified, self::ConfirmationSent, self::Unlinked, self::Linked], true);
    }
}
