<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Interaction;

use LogicException;
use Nowo\SlackKitBundle\Api\SlackMessage;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Event\SlackActionHandledEvent;
use Nowo\SlackKitBundle\Id\SlackChannelId;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\NowoSlackKitBundle;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function hash;
use function is_array;
use function is_string;
use function preg_match;

/**
 * Routes a verified `block_actions` payload (contract §6): reads the first action, verifies its `nsk1` value (or
 * accepts a legacy value while allowed), finds the handler of the `action_id`, resolves the actor, refuses clicks
 * without one for handlers that need it, calls the handler and schedules its Slack calls for after the response.
 */
final readonly class InteractionRouter
{
    /** Body of a click refused for a missing actor. */
    public const LINK_REQUIRED = 'Link and confirm your Slack account';

    /**
     * @param ContainerInterface $handlers handler services keyed by `action_id`
     * @param array<string, bool> $requiresActor `action_id` → whether the handler needs an actor
     * @param ButtonValueCodecInterface $codec `nsk1` codec
     * @param LegacyValuePolicy $legacy legacy value policy
     * @param ActorResolver $actors actor resolution
     * @param ReplyDispatcher $replies deferred Slack calls
     * @param EventDispatcherInterface|null $events dispatcher of {@see SlackActionHandledEvent}
     * @param TranslatorInterface|null $translator translator of the built-in answers
     * @param string $defaultLocale locale of built-in answers when the clicker has no account
     * @param LoggerInterface $logger PSR-3 logger
     */
    public function __construct(
        private ContainerInterface $handlers,
        private array $requiresActor,
        private ButtonValueCodecInterface $codec,
        private LegacyValuePolicy $legacy,
        private ActorResolver $actors,
        private ReplyDispatcher $replies,
        private ?EventDispatcherInterface $events = null,
        private ?TranslatorInterface $translator = null,
        private string $defaultLocale = 'en',
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Parses the click into a {@see SlackInteraction} without calling a handler; null when it is not routable.
     *
     * @param array<string, mixed> $payload verified `block_actions` payload
     * @param bool $verifiedByResolver the request was verified with a host-resolved secret: legacy values are refused
     * @param bool $trustActor whether `team.id` / `user.id` may resolve an actor (false for resolver-verified clicks,
     *                         audit FS-M1, and for own-app clicks unless `installations.own_app_actors`, PZ-M1)
     * @param bool $verifiedByOwnApp the request was verified with the installation's own-app secret
     *
     * @return array{0: SlackInteraction|null, 1: Response|null} the interaction, or the response that refuses it
     */
    public function parse(array $payload, SlackInstallationKey $installation, bool $verifiedByResolver = false, bool $trustActor = true, bool $verifiedByOwnApp = false): array
    {
        $actions = $payload['actions'] ?? null;
        $action  = is_array($actions) && is_array($actions[0] ?? null) ? $actions[0] : null;
        if ($action === null) {
            return [null, new Response('No actions', Response::HTTP_BAD_REQUEST)];
        }
        $actionId = is_string($action['action_id'] ?? null) ? $action['action_id'] : '';
        $value    = is_string($action['value'] ?? null) ? $action['value'] : '';
        if ($value === '' && is_string($action['url'] ?? null)) {
            return [null, new Response('', Response::HTTP_OK)]; // A link button: Slack opened the URL.
        }

        $team    = SlackTeamId::tryFrom($this->path($payload, 'team', 'id'));
        $user    = SlackUserId::tryFrom($this->path($payload, 'user', 'id'));
        $channel = SlackChannelId::tryFrom($this->path($payload, 'container', 'channel_id') ?? $this->path($payload, 'channel', 'id'));
        $ts      = $this->path($payload, 'container', 'message_ts');
        $base    = new SlackInteraction($installation, $actionId, [], $team, $user, null, $channel, $ts, '', false, $verifiedByResolver, null, SlackSecret::tryResponseUrl($payload['response_url'] ?? null));

        $legacy = false;
        if (ButtonValueCodec::isSignedFamily($value)) {
            try {
                $data = $this->codec->verify($actionId, $value, $team?->value)->data;
            } catch (InvalidButtonValueException $e) {
                $this->logger->info('Slack button value refused.', ['action_id' => preg_match(ButtonValueCodec::ACTION_ID_PATTERN, $actionId) === 1 ? $actionId : 'invalid', 'reason' => $e->reason]);

                return [null, $this->refuse($base, 'interaction.button_invalid', 'Button no longer valid', null)];
            }
        } else {
            $resolved = $verifiedByResolver ? null : $this->legacy->resolve($value);
            if ($resolved === null) {
                $this->logger->info('Slack button value refused.', ['reason' => 'unsigned']);

                return [null, $this->refuse($base, 'interaction.button_invalid', 'Button no longer valid', null)];
            }
            [$actionId, $data] = $resolved;
            $legacy            = true;
            $this->logger->info('Slack legacy button value accepted.', ['action_id' => $actionId]);
        }

        $interactionId = hash('sha256', ($team->value ?? '') . '|' . ($ts ?? '') . '|' . (is_string($action['action_ts'] ?? null) ? $action['action_ts'] : '') . '|' . ($user->value ?? ''));

        return [new SlackInteraction(
            $installation,
            $actionId,
            $data,
            $team,
            $user,
            $trustActor ? $this->actors->resolve($team, $user, $installation, $verifiedByOwnApp) : null,
            $channel,
            $ts,
            $interactionId,
            $legacy,
            $verifiedByResolver,
            SlackOriginalMessage::fromPayload($payload),
            SlackSecret::tryResponseUrl($payload['response_url'] ?? null),
            $verifiedByOwnApp,
        ), null];
    }

    /**
     * Routes a parsed interaction to its handler and answers Slack.
     */
    public function dispatch(SlackInteraction $interaction): Response
    {
        if (!isset($this->requiresActor[$interaction->actionId]) || !$this->handlers->has($interaction->actionId)) {
            $this->logger->info('Slack action without a handler.', ['action_id' => $interaction->actionId]);

            return new Response('Unsupported action', Response::HTTP_BAD_REQUEST);
        }
        if ($this->requiresActor[$interaction->actionId] && !$interaction->actor instanceof SlackActor) {
            return $this->refuse($interaction, 'interaction.link_required', self::LINK_REQUIRED, null);
        }

        $handler = $this->handlers->get($interaction->actionId);
        try {
            if (!$handler instanceof SlackActionHandlerInterface) {
                throw new LogicException('Not a Slack action handler.');
            }
            $reply = $handler->handle($interaction);
        } catch (Throwable $e) {
            $this->logger->error('Slack action handler failed.', ['action_id' => $interaction->actionId, 'exception' => $e::class]);
            $reply = new SlackInteractionReply(Response::HTTP_INTERNAL_SERVER_ERROR, 'Error', $this->message('interaction.error', $interaction->actor?->locale));
        }

        $this->events?->dispatch(new SlackActionHandledEvent($interaction->actionId, $interaction->actor?->accountId, $reply->status, $interaction->legacy));
        $this->replies->schedule($interaction, $reply);

        return new Response($reply->body, $reply->status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * A translated built-in message.
     */
    public function message(string $key, ?string $locale = null): SlackMessage
    {
        $text = $this->translator?->trans($key, [], NowoSlackKitBundle::TRANSLATION_DOMAIN, $locale ?? $this->defaultLocale) ?? $key;

        return SlackMessage::plain($text);
    }

    private function refuse(SlackInteraction $interaction, string $key, string $body, ?string $locale): Response
    {
        $this->replies->schedule($interaction, (new SlackInteractionReply(Response::HTTP_FORBIDDEN, $body))->withEphemeral($this->message($key, $locale)));

        return new Response($body, Response::HTTP_FORBIDDEN, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function path(array $payload, string $first, string $second): ?string
    {
        $inner = $payload[$first] ?? null;
        $value = is_array($inner) ? ($inner[$second] ?? null) : null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
