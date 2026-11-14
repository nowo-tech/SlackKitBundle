<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Ingress;

use DateTimeImmutable;
use Nowo\SlackKitBundle\Credentials\SlackInstallation;
use Nowo\SlackKitBundle\Credentials\SlackInstallationBindingStoreInterface;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Credentials\SlackInstallationStoreInterface;
use Nowo\SlackKitBundle\Event\SlackEventReceivedEvent;
use Nowo\SlackKitBundle\Event\SlackInstallationRevokedEvent;
use Nowo\SlackKitBundle\Id\SlackEnterpriseId;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

use function in_array;
use function is_array;
use function is_int;
use function is_string;

/**
 * Handles a verified `event_callback` envelope (contract §5.4): `app_uninstalled` and `tokens_revoked` (naming the
 * installation's bot) revoke the installation of the envelope's workspace once and dispatch
 * {@see SlackInstallationRevokedEvent} with its bindings; then every event is dispatched as
 * {@see SlackEventReceivedEvent}. Also the Messenger handler of {@see HandleSlackEvent}.
 */
final readonly class EventsHandler
{
    /**
     * @param SlackInstallationStoreInterface $store installation store
     * @param EventDispatcherInterface $events event dispatcher
     * @param ClockInterface $clock clock
     * @param SlackInstallationBindingStoreInterface|null $bindings binding store (`installations.binding_store`)
     */
    public function __construct(
        private SlackInstallationStoreInterface $store,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
        private ?SlackInstallationBindingStoreInterface $bindings = null,
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
        $team  = SlackTeamId::tryFrom($envelope['team_id'] ?? null);
        $key   = new SlackInstallationKey($app, $team, $team instanceof SlackTeamId ? SlackEnterpriseId::tryFrom($envelope['enterprise_id'] ?? null) : null);

        if ($type === 'app_uninstalled' || $type === 'tokens_revoked') {
            $this->revoke($key, $type, $event);
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

    /**
     * Revokes the workspace's active installation once. `tokens_revoked` counts only when it names bot tokens and,
     * when the installation knows its bot user, that bot.
     *
     * @param array<mixed> $event
     */
    private function revoke(SlackInstallationKey $key, string $type, array $event): void
    {
        if (!$key->team instanceof SlackTeamId) {
            return;
        }
        $installation = $this->store->find($key);
        if (!$installation instanceof SlackInstallation || $installation->isRevoked() || !$key->admits($installation)) {
            return;
        }
        if ($type === 'tokens_revoked') {
            $bots = is_array($event['tokens'] ?? null) && is_array($event['tokens']['bot'] ?? null) ? $event['tokens']['bot'] : [];
            if ($bots === [] || ($installation->botUserId !== null && !in_array($installation->botUserId, $bots, true))) {
                return;
            }
        }

        $this->store->markRevoked($key, DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->events->dispatch(new SlackInstallationRevokedEvent($key, $type, $this->bindings?->bindingsOf($key) ?? []));
    }
}
