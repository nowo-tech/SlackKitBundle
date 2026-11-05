<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Interaction;

/**
 * The account a click acts for: verified link, active account, same workspace (contract §6.3). Handlers still check
 * the actor's own access to the resource.
 */
final readonly class SlackActor
{
    /**
     * @param string $accountId host account id (opaque)
     * @param string|null $locale preferred locale of the account
     */
    public function __construct(
        public string $accountId,
        public ?string $locale = null,
    ) {
    }
}
