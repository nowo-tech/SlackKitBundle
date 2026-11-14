<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Ingress;

/**
 * Messenger message of a verified Events API callback (`ingress.async: true`): the decoded envelope only, never the
 * raw body or headers.
 */
final readonly class HandleSlackEvent
{
    /**
     * @param string $app app the callback was verified for
     * @param array<string, mixed> $envelope decoded `event_callback` envelope
     * @param bool $verifiedByOwnApp the callback was verified with an installation's own-app secret (1.3)
     */
    public function __construct(
        public string $app,
        public array $envelope,
        public bool $verifiedByOwnApp = false,
    ) {
    }
}
