<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Ingress;

use DateTimeImmutable;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Credentials\SlackInstallationStoreInterface;
use Nowo\SlackKitBundle\Event\SlackEventReceivedEvent;
use Nowo\SlackKitBundle\Event\SlackInstallationRevokedEvent;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

use function is_array;
use function is_int;
use function is_string;

/**
 * Handles a verified `event_callback` envelope (contract §5.4): `app_uninstalled` and `tokens_revoked` (with bot
 * tokens) mark the installation revoked, then every event is dispatched as {@see SlackEventReceivedEvent}. Also the
 * Messenger handler of {@see HandleSlackEvent}.
 */
final readonly class EventsHandler
{
    /**
     * @param SlackInstallationStoreInterface $store installation store
     * @param EventDispatcherInterface $events event dispatcher
     * @param ClockInterface $clock clock
     */
    public function __construct(
        private SlackInstallationStoreInterface $store,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Messenger entry point.
     */
    public function __invoke(HandleSlackEvent $message): void
    {
        $this->handle($message->app, $message->envelope);
    }

    /**
     * Handles one envelope (already verified, de-duplicated and validated by the ingress).
     *
     * @param array<string, mixed> $envelope
     */
    public function handle(string $app, array $envelope): void
    {
        $event = is_array($envelope['event'] ?? null) ? $envelope['event'] : [];
        $type  = is_string($event['type'] ?? null) ? $event['type'] : '';
        $key   = new SlackInstallationKey($app, SlackTeamId::tryFrom($envelope['team_id'] ?? null));

        if ($type === 'app_uninstalled' || ($type === 'tokens_revoked' && is_array($event['tokens'] ?? null) && ($event['tokens']['bot'] ?? []) !== [])) {
            $this->store->markRevoked($key, DateTimeImmutable::createFromInterface($this->clock->now()));
            $this->events->dispatch(new SlackInstallationRevokedEvent($key, $type));
        }

        /* @var array<string, mixed> $event */
        $this->events->dispatch(new SlackEventReceivedEvent(
            $key,
            is_string($envelope['event_id'] ?? null) ? $envelope['event_id'] : '',
            is_int($envelope['event_time'] ?? null) ? $envelope['event_time'] : 0,
            $type,
            $event,
        ));
    }
}
