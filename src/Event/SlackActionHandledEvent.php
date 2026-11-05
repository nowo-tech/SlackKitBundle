<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Event;

/**
 * A handler answered a button click.
 */
final class SlackActionHandledEvent
{
    /**
     * @param string $actionId `action_id`
     * @param string|null $accountId actor account id, or null
     * @param int $status HTTP status of the reply
     * @param bool $legacy whether the value was a legacy unsigned value
     */
    public function __construct(
        public readonly string $actionId,
        public readonly ?string $accountId,
        public readonly int $status,
        public readonly bool $legacy,
    ) {
    }
}
