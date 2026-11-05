<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Interaction;

use Attribute;

/**
 * Registers a {@see SlackActionHandlerInterface} for an `action_id` (autoconfigured, tag `nowo_slack_kit.action_handler`).
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class AsSlackActionHandler
{
    /**
     * @param string $action `action_id` (`^[a-z0-9_.]{1,64}$`), e.g. `beacon.issue.resolve`
     * @param bool $requiresActor refuse clicks without a verified, active account (default)
     */
    public function __construct(
        public string $action,
        public bool $requiresActor = true,
    ) {
    }
}
