<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Linking;

/**
 * The account id of the signed-in user (the account link routes act on it only; it never comes from the request).
 */
interface SlackAccountResolverInterface
{
    /**
     * The current account id, or null when nobody is signed in.
     */
    public function currentAccountId(): ?string;
}
