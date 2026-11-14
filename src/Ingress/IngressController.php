<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Ingress;

use JsonException;
use Nowo\SlackKitBundle\Credentials\SlackAppRegistry;
use Nowo\SlackKitBundle\Credentials\SlackInstallation;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Credentials\SlackInstallationStoreInterface;
use Nowo\SlackKitBundle\Event\SlackEventsRateLimitedEvent;
use Nowo\SlackKitBundle\Event\SlackInteractionReceivedEvent;
use Nowo\SlackKitBundle\Id\SlackEnterpriseId;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Interaction\InteractionRouter;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Throwable;

use function is_array;
use function is_int;
use function is_resource;
use function is_string;
use function json_decode;
use function parse_str;
use function preg_match;
use function str_starts_with;
use function stream_get_contents;
use function strlen;
use function strtolower;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * The public Slack endpoints (contract §5): interactions (`application/x-www-form-urlencoded`, `payload=<json>`) and
 * the Events API (`application/json`). Checks run in this order: content type, capped body, header shape, time window,
 * secret choice, signature, replay, decoding, workspace and app; every refusal answers a short fixed body.
 */
final readonly class IngressController
{
    /** Interactions route name. */
    public const INTERACTIONS_ROUTE = 'nowo_slack_kit_interactions';

    /** Interactions route name with the `{app}` segment. */
    public const INTERACTIONS_APP_ROUTE = 'nowo_slack_kit_interactions_app';

    /** Events route name. */
    public const EVENTS_ROUTE = 'nowo_slack_kit_events';

    /** Events route name with the `{app}` segment. */
    public const EVENTS_APP_ROUTE = 'nowo_slack_kit_events_app';

    /**
     * @param SignatureVerifier $verifier signature checks
     * @param ReplayGuard $replay replay memory
     * @param SlackAppRegistry $apps configured apps and their signing secrets
     * @param SlackInstallationStoreInterface $store installation store
     * @param InteractionRouter $router interactions router
     * @param EventsHandler $events Events API handler (sync mode)
     * @param EventDispatcherInterface|null $dispatcher event dispatcher
     * @param SigningSecretResolverInterface|null $resolver host secret candidates (`ingress.signing_secret_resolver`)
     * @param MessageBusInterface|null $bus Messenger bus (`ingress.async`)
     * @param string $transport Messenger transport of {@see HandleSlackEvent}
     * @param int $maxBodyBytes `ingress.max_body_bytes`
     * @param int $maxSecretCandidates `ingress.max_secret_candidates`
     * @param LoggerInterface $logger PSR-3 logger (route, app and reason only)
     * @param bool $resolverActors `ingress.resolver_actors`: resolve actors for clicks verified with a host secret
     *                             bound to the click's workspace ({@see ResolvedSigningSecret}); off by default
     */
    public function __construct(
        private SignatureVerifier $verifier,
        private ReplayGuard $replay,
        private SlackAppRegistry $apps,
        private SlackInstallationStoreInterface $store,
        private InteractionRouter $router,
        private EventsHandler $events,
        private ?EventDispatcherInterface $dispatcher = null,
        private ?SigningSecretResolverInterface $resolver = null,
        private ?MessageBusInterface $bus = null,
        private string $transport = 'async',
        private int $maxBodyBytes = 1_048_576,
        private int $maxSecretCandidates = 2,
        private LoggerInterface $logger = new NullLogger(),
        private bool $resolverActors = false,
    ) {
    }

    /**
     * `POST <prefix>/interactions[/{app}]`.
     */
    public function interactions(Request $request, ?string $app = null): Response
    {
        $verified = $this->verify($request, 'application/x-www-form-urlencoded', $app, true);
        if ($verified instanceof Response) {
            return $verified;
        }
        [$appName, $body, $byResolver, $boundTeam, $ownApp] = $verified;

        parse_str($body, $fields);
        try {
            $payload = is_string($fields['payload'] ?? null) ? json_decode($fields['payload'], true, 32, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $payload = null;
        }
        if (!is_array($payload)) {
            return $this->text('Invalid payload', Response::HTTP_BAD_REQUEST);
        }
        /** @var array<string, mixed> $payload */
        $type = is_string($payload['type'] ?? null) ? $payload['type'] : '';
        if ($type === 'url_verification') {
            return $this->text('Unsupported on interactions endpoint', Response::HTTP_BAD_REQUEST);
        }

        $installation = SlackInstallationKey::default();
        if (!$byResolver) {
            $team       = is_array($payload['team'] ?? null) ? ($payload['team']['id'] ?? null) : null;
            $enterprise = is_array($payload['enterprise'] ?? null) ? ($payload['enterprise']['id'] ?? null) : null;
            $checked    = $this->checkWorkspace($appName, $team, $payload['api_app_id'] ?? null, $enterprise, $ownApp);
            if ($checked instanceof Response) {
                return $checked;
            }
            $installation = $checked;
        }

        if ($type !== 'block_actions') {
            $this->dispatcher?->dispatch(new SlackInteractionReceivedEvent($type, $payload, null));

            return $this->text('', Response::HTTP_OK);
        }

        // FS-M1: the team and user of a resolver-verified click are chosen by whoever holds that secret.
        $trustActor = !$byResolver
            || ($this->resolverActors && $boundTeam instanceof SlackTeamId && $boundTeam->equals(SlackTeamId::tryFrom(is_array($payload['team'] ?? null) ? ($payload['team']['id'] ?? null) : null)));
        [$interaction, $refusal] = $this->router->parse($payload, $installation, $byResolver, $trustActor);
        $this->dispatcher?->dispatch(new SlackInteractionReceivedEvent($type, $payload, $interaction));

        return $interaction === null ? ($refusal ?? $this->text('No actions', Response::HTTP_BAD_REQUEST)) : $this->router->dispatch($interaction);
    }

    /**
     * `POST <prefix>/events[/{app}]`.
     */
    public function events(Request $request, ?string $app = null): Response
    {
        $verified = $this->verify($request, 'application/json', $app, false);
        if ($verified instanceof Response) {
            return $verified;
        }
        [$appName, $body, , , $ownApp] = $verified;

        try {
            $envelope = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $envelope = null;
        }
        if (!is_array($envelope)) {
            return $this->text('Invalid payload', Response::HTTP_BAD_REQUEST);
        }
        /** @var array<string, mixed> $envelope */
        $type = is_string($envelope['type'] ?? null) ? $envelope['type'] : '';

        if ($type === 'url_verification') {
            $challenge = $envelope['challenge'] ?? null;

            return is_string($challenge) && preg_match('/^[A-Za-z0-9_\-]{1,128}$/', $challenge) === 1
                ? new JsonResponse(['challenge' => $challenge])
                : $this->text('Invalid challenge', Response::HTTP_BAD_REQUEST);
        }

        $checked = $this->checkWorkspace($appName, $envelope['team_id'] ?? null, $envelope['api_app_id'] ?? null, $envelope['enterprise_id'] ?? null, $ownApp);
        if ($checked instanceof Response) {
            return $checked;
        }

        if ($type === 'app_rate_limited') {
            $this->dispatcher?->dispatch(new SlackEventsRateLimitedEvent((string) $checked->team?->value, is_int($envelope['minute_rate_limited'] ?? null) ? $envelope['minute_rate_limited'] : 0));
            $this->logger->warning('Slack paused Events API deliveries.', ['app' => $appName]);

            return $this->text('', Response::HTTP_OK);
        }
        if ($type !== 'event_callback') {
            return $this->text('', Response::HTTP_OK);
        }

        $eventId = $envelope['event_id'] ?? null;
        $event   = $envelope['event'] ?? null;
        if (!is_string($eventId) || preg_match('/^[A-Za-z0-9]{1,64}$/', $eventId) !== 1 || !is_array($event) || !is_string($event['type'] ?? null) || !is_int($envelope['event_time'] ?? null)) {
            return $this->text('Invalid event', Response::HTTP_BAD_REQUEST);
        }
        $claim = $this->replay->claimEvent($eventId);
        if (!$claim instanceof LockInterface) {
            return $this->text('', Response::HTTP_OK); // A Slack retry of a delivered event.
        }

        try {
            if ($this->bus instanceof MessageBusInterface) {
                $this->bus->dispatch(new HandleSlackEvent($appName, $envelope), [new TransportNamesStamp([$this->transport])]);
            } else {
                $this->events->handle($appName, $envelope);
            }
        } catch (Throwable $e) {
            // Audit FS-I5: forget the event so Slack's retry (same event_id) is handled.
            $claim->release();
            $this->logger->error('Slack event handling failed.', ['app' => $appName, 'reason' => 'handler', 'exception' => $e::class]);

            return $this->text('Event handling failed', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->text('', Response::HTTP_OK);
    }

    /**
     * Steps 1–7 of §5.2. Secrets are tried in this order: the app's, then the own-app secret of the installation named
     * by the unverified workspace id alone (spec 237 FR-004), then the host resolver's candidates.
     *
     * @return array{0: string, 1: string, 2: bool, 3: SlackTeamId|null, 4: bool}|Response app, raw body, "verified by
     *                                                                                     a host secret" and its bound
     *                                                                                     workspace, "verified by the
     *                                                                                     installation's own app", or
     *                                                                                     the refusal
     */
    private function verify(Request $request, string $contentType, ?string $app, bool $allowResolver): array|Response
    {
        if (!str_starts_with(strtolower((string) $request->headers->get('Content-Type', '')), $contentType)) {
            return $this->text('Unsupported Media Type', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }
        $length = $request->headers->get('Content-Length');
        if (is_string($length) && (int) $length > $this->maxBodyBytes) {
            return $this->text('Payload too large', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        $stream = $request->getContent(true);
        $body   = is_resource($stream) ? (string) stream_get_contents($stream, $this->maxBodyBytes + 1) : '';
        if (strlen($body) > $this->maxBodyBytes) {
            return $this->text('Payload too large', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $timestamp = trim((string) $request->headers->get('X-Slack-Request-Timestamp', ''));
        $signature = trim((string) $request->headers->get('X-Slack-Signature', ''));
        if (!$this->verifier->headersAreWellFormed($timestamp, $signature) || !$this->verifier->isWithinWindow($timestamp)) {
            return $this->unauthorized($app, 'headers');
        }

        $appName = $app ?? $this->apps->defaultApp;
        if (!$this->apps->has($appName)) {
            return $this->text('Not Found', Response::HTTP_NOT_FOUND);
        }

        $label      = null;
        $byResolver = false;
        $boundTeam  = null;
        $ownApp     = false;
        $secret     = $this->apps->signingSecret($appName);
        if ($secret instanceof SlackSecret && $this->verifier->matches($secret, $timestamp, $signature, $body)) {
            $label = 'app:' . $appName;
        } elseif (($own = $this->ownAppSecret($appName, $body, $contentType === 'application/x-www-form-urlencoded')) instanceof SlackSecret && $this->verifier->matches($own, $timestamp, $signature, $body)) {
            $label  = 'installation';
            $ownApp = true;
        } elseif ($app === null && $allowResolver && $this->resolver instanceof SigningSecretResolverInterface) {
            $tried = 0;
            foreach ($this->resolver->candidates(new UnverifiedPayloadPeek($body)) as $candidate) {
                if (++$tried > $this->maxSecretCandidates) {
                    break;
                }
                $resolved = $candidate instanceof ResolvedSigningSecret ? $candidate : new ResolvedSigningSecret($candidate);
                if ($this->verifier->matches($resolved->secret, $timestamp, $signature, $body)) {
                    $label      = 'resolver';
                    $byResolver = true;
                    $boundTeam  = $resolved->team;
                    break;
                }
            }
        }
        if ($label === null) {
            return $this->unauthorized($appName, 'signature');
        }
        if (!$this->replay->claimSignature($signature)) {
            return $this->unauthorized($appName, 'replay');
        }

        return [$appName, $body, $byResolver, $boundTeam, $ownApp];
    }

    /**
     * The own-app signing secret of the active installation named by the body's unverified workspace id (FR-004).
     * Nothing else of the body is read before the signature passes; the workspace is checked again once verified.
     */
    private function ownAppSecret(string $app, string $body, bool $form): ?SlackSecret
    {
        $team = SlackTeamId::tryFrom(UnverifiedPayloadPeek::teamId($body, $form));
        if (!$team instanceof SlackTeamId) {
            return null;
        }
        $key          = new SlackInstallationKey($app, $team);
        $installation = $this->store->find($key);

        return $installation instanceof SlackInstallation && !$installation->isRevoked() && $key->admits($installation) ? $installation->signingSecret : null;
    }

    /**
     * The installation of the payload's workspace (and enterprise) for this app, and the app id check: the
     * installation's own Slack app id when it records one, else `apps.<name>.app_id` (not for an own-app request,
     * whose app is the installation's).
     */
    private function checkWorkspace(string $app, mixed $teamId, mixed $apiAppId, mixed $enterpriseId = null, bool $ownApp = false): SlackInstallationKey|Response
    {
        $team         = SlackTeamId::tryFrom($teamId);
        $key          = $team instanceof SlackTeamId ? new SlackInstallationKey($app, $team, SlackEnterpriseId::tryFrom($enterpriseId)) : null;
        $installation = $key instanceof SlackInstallationKey ? $this->store->find($key) : null;
        if (!$key instanceof SlackInstallationKey || !$installation instanceof SlackInstallation || $installation->isRevoked() || !$installation->teamId instanceof SlackTeamId || !$key->admits($installation)
            || ($ownApp && !$installation->signingSecret instanceof SlackSecret)) {
            $this->logger->warning('Slack request refused: workspace not installed.', ['app' => $app, 'reason' => 'team']);

            return $this->text('Workspace mismatch', Response::HTTP_FORBIDDEN);
        }
        $appId = $installation->appId ?? ($ownApp ? null : $this->apps->appId($app));
        if ($appId !== null && $apiAppId !== $appId) {
            $this->logger->warning('Slack request refused: app mismatch.', ['app' => $app, 'reason' => 'app_id']);

            return $this->text('App mismatch', Response::HTTP_FORBIDDEN);
        }

        return $key;
    }

    private function unauthorized(?string $app, string $reason): Response
    {
        $this->logger->warning('Slack request rejected.', ['app' => $app, 'reason' => $reason]);

        return $this->text('Invalid signature', Response::HTTP_UNAUTHORIZED);
    }

    private function text(string $body, int $status): Response
    {
        return new Response($body, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
