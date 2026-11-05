<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Interaction;

/**
 * A verified `nsk1` button value: the handler data, the expiry and the optional workspace it is bound to.
 */
final readonly class ButtonValue
{
    /**
     * @param string $actionId `action_id` the MAC is bound to
     * @param array<string, mixed> $data the handler's own data (`d`)
     * @param int $expiresAt `exp`, Unix seconds
     * @param string|null $teamId `t`, or null when the producer did not know the workspace
     */
    public function __construct(
        public string $actionId,
        public array $data,
        public int $expiresAt,
        public ?string $teamId,
    ) {
    }
}
