<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Support;

use Nowo\SlackKitBundle\Linking\SlackAccountResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The signed-in user identifier is the account id (test kernel).
 */
final readonly class TokenAccountResolver implements SlackAccountResolverInterface
{
    public function __construct(private TokenStorageInterface $tokens)
    {
    }

    public function currentAccountId(): ?string
    {
        return $this->tokens->getToken()?->getUserIdentifier();
    }
}
