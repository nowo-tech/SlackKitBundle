<?php

declare(strict_types=1);

namespace App\Slack;

use Nowo\SlackKitBundle\Linking\SlackAccountResolverInterface;

/**
 * Demo only: there is no login, so the visitor is always the demo account. A real host returns the signed-in
 * user's id (and null for guests).
 */
final readonly class DemoAccountResolver implements SlackAccountResolverInterface
{
    public function currentAccountId(): ?string
    {
        return DemoIdentityStore::ACCOUNT;
    }
}
