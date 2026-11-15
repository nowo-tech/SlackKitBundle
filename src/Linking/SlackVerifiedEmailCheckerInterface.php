<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Linking;

/**
 * Host port of link-from-click (`linking.from_click.email_checker`, spec 237 FR-012): whether the e-mail is a
 * **verified** e-mail of the account. Compare case-insensitively; never treat an unverified address as a match.
 */
interface SlackVerifiedEmailCheckerInterface
{
    /**
     * Whether the account owns the e-mail and verified it.
     */
    public function isVerifiedEmailOf(SlackVerifiedEmailCheck $check): bool;
}
