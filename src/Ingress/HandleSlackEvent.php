<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Ingress;

/**
 * Messenger message of a verified Events API callback (`ingress.async: true`): the decoded envelope only, never the
 * raw body or headers. Messages queued by 1.2 (without `verifiedByOwnApp`) still unserialise.
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

    /**
     * Restores a queued message, including one queued by 1.2 (no `verifiedByOwnApp`: false), audit PZ-L9.
     *
     * @param array{app: string, envelope: array<string, mixed>, verifiedByOwnApp?: bool} $data
     */
    public function __unserialize(array $data): void
    {
        $this->app              = $data['app'];
        $this->envelope         = $data['envelope'];
        $this->verifiedByOwnApp = $data['verifiedByOwnApp'] ?? false;
    }
}
