<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Ingress;

use DateTimeImmutable;
use Nowo\SlackKitBundle\Credentials\SlackInstallation;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Event\SlackActionHandledEvent;
use Nowo\SlackKitBundle\Event\SlackEventReceivedEvent;
use Nowo\SlackKitBundle\Event\SlackEventsRateLimitedEvent;
use Nowo\SlackKitBundle\Event\SlackInteractionReceivedEvent;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Ingress\HandleSlackEvent;
use Nowo\SlackKitBundle\Ingress\ResolvedSigningSecret;
use Nowo\SlackKitBundle\Ingress\SigningSecretResolverInterface;
use Nowo\SlackKitBundle\Ingress\UnverifiedPayloadPeek;
use Nowo\SlackKitBundle\Linking\SlackLink;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use Nowo\SlackKitBundle\Tests\Support\Ingress;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

use function strlen;

/**
 * Contract §5.2 order of checks, replay, workspace and app checks, routing and the Events API.
 */
final class IngressControllerTest extends TestCase
{
    public function testAVerifiedActorRunsTheHandlerAndTheReplyIsDeferred(): void
    {
        $ingress = new Ingress();
        $this->link($ingress);
        $value = $ingress->codec->sign('beacon.issue.resolve', ['i' => 'issue-1'], SlackTeamId::fromString(Fake::TEAM));

        $response = $ingress->controller->interactions($this->interaction(Fake::blockActions('beacon.issue.resolve', $value)));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Done', $response->getContent());
        self::assertCount(1, $ingress->handler->seen);
        $seen = $ingress->handler->seen[0];
        self::assertSame('acc-1', $seen->actor?->accountId);
        self::assertSame(['i' => 'issue-1'], $seen->data);
        self::assertFalse($seen->legacy);
        self::assertSame(64, strlen($seen->interactionId));
        self::assertSame('default:' . Fake::TEAM, $seen->installation->key());
        self::assertSame([], $ingress->api->calls, 'no Slack call before the response');
        self::assertCount(1, $ingress->events->of(SlackInteractionReceivedEvent::class));
        self::assertSame(200, $ingress->events->of(SlackActionHandledEvent::class)[0]->status);

        $ingress->replies->flush();
        self::assertSame(['chat.update'], $ingress->api->methods());
    }

    public function testReplayedRequestIsRefused(): void
    {
        $ingress = new Ingress();
        $this->link($ingress);
        $body   = Fake::form(Fake::blockActions('beacon.issue.resolve', $ingress->codec->sign('beacon.issue.resolve', [])));
        $first  = $ingress->controller->interactions($this->raw($body, Fake::signed($body)));
        $second = $ingress->controller->interactions($this->raw($body, Fake::signed($body)));

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(401, $second->getStatusCode());
        self::assertSame('Invalid signature', $second->getContent());
        self::assertCount(1, $ingress->handler->seen);
    }

    public function testRefusalsInOrder(): void
    {
        $ingress = new Ingress(maxBody: 16_384);
        $body    = Fake::form(Fake::blockActions('beacon.issue.resolve', 'x'));

        self::assertSame(415, $ingress->controller->interactions($this->raw($body, Fake::signed($body), 'application/json'))->getStatusCode());
        self::assertSame(413, $ingress->controller->interactions($this->raw($body, Fake::signed($body) + ['CONTENT_LENGTH' => '20000']))->getStatusCode());
        $big = str_repeat('a', 16_385);
        self::assertSame(413, $ingress->controller->interactions($this->raw($big, Fake::signed($big)))->getStatusCode());
        self::assertSame(401, $ingress->controller->interactions($this->raw($body, []))->getStatusCode());
        self::assertSame(401, $ingress->controller->interactions($this->raw($body, Fake::signed($body, Fake::NOW - 301)))->getStatusCode());
        self::assertSame(401, $ingress->controller->interactions($this->raw($body, Fake::signed($body, Fake::NOW, Fake::OTHER_SECRET)))->getStatusCode());
        self::assertSame(401, $ingress->controller->interactions($this->raw($body . 'x', Fake::signed($body)))->getStatusCode());
        self::assertSame(404, $ingress->controller->interactions($this->raw($body, Fake::signed($body)), 'unknown')->getStatusCode());
        self::assertSame([], $ingress->handler->seen);
        self::assertContains('Slack request rejected.', $ingress->logger->messages());
        self::assertStringNotContainsString(Fake::SIGNING_SECRET, (string) json_encode($ingress->logger->records));
    }

    public function testPayloadAndWorkspaceChecks(): void
    {
        $ingress = new Ingress();
        $cases   = [
            ['payload=%7B', 400, 'Invalid payload'],
            ['other=1', 400, 'Invalid payload'],
            [Fake::form(['type' => 'url_verification', 'challenge' => 'abc']), 400, 'Unsupported on interactions endpoint'],
            [Fake::form(Fake::blockActions('beacon.issue.resolve', 'x', Fake::OTHER_TEAM)), 403, 'Workspace mismatch'],
            [Fake::form(['team' => ['id' => Fake::TEAM], 'api_app_id' => 'A99999999', 'type' => 'block_actions']), 403, 'App mismatch'],
            [Fake::form(['team' => ['id' => Fake::TEAM], 'api_app_id' => Fake::APP_ID, 'type' => 'block_actions', 'actions' => []]), 400, 'No actions'],
        ];
        foreach ($cases as [$body, $status, $text]) {
            $response = $ingress->controller->interactions($this->raw($body, Fake::signed($body)));
            self::assertSame($status, $response->getStatusCode(), $text);
            self::assertSame($text, $response->getContent());
        }
    }

    public function testWorkspaceMustBeConfiguredAndInstalled(): void
    {
        $noTeam = new Ingress(withTeam: false);
        $body   = Fake::form(Fake::blockActions('demo.ping', 'x'));
        self::assertSame(403, $noTeam->controller->interactions($this->raw($body, Fake::signed($body)))->getStatusCode());

        $revoked = new Ingress();
        $revoked->store->markRevoked(new SlackInstallationKey('default', SlackTeamId::fromString(Fake::TEAM)), new DateTimeImmutable());
        self::assertSame(403, $revoked->controller->interactions($this->raw($body, Fake::signed($body)))->getStatusCode());
    }

    public function testOtherInteractionTypesAreAcknowledgedAndDispatched(): void
    {
        $ingress  = new Ingress();
        $body     = Fake::form(['type' => 'view_submission', 'team' => ['id' => Fake::TEAM], 'api_app_id' => Fake::APP_ID]);
        $response = $ingress->controller->interactions($this->raw($body, Fake::signed($body)));
        self::assertSame(200, $response->getStatusCode());
        $event = $ingress->events->of(SlackInteractionReceivedEvent::class)[0];
        self::assertSame('view_submission', $event->type);
        self::assertNull($event->interaction);
    }

    public function testClicksWithoutAnActorAreRefusedWithAnEphemeral(): void
    {
        $ingress  = new Ingress();
        $value    = $ingress->codec->sign('beacon.issue.resolve', []);
        $response = $ingress->controller->interactions($this->interaction(Fake::blockActions('beacon.issue.resolve', $value)));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Link and confirm your Slack account', $response->getContent());
        self::assertSame([], $ingress->handler->seen);
        $ingress->replies->flush();
        self::assertSame(['chat.postEphemeral'], $ingress->api->methods());
        self::assertSame('interaction.link_required', $ingress->api->calls[0][1][2]->text);
    }

    public function testUnverifiedSuspendedOrOtherTeamLinksAreNotActors(): void
    {
        foreach (['unverified', 'inactive'] as $case) {
            $ingress = new Ingress();
            $this->link($ingress, $case === 'unverified' ? null : new DateTimeImmutable('@1'));
            if ($case === 'inactive') {
                $ingress->identities->active['acc-1'] = false;
            }
            $response = $ingress->controller->interactions($this->interaction(Fake::blockActions('beacon.issue.resolve', $ingress->codec->sign('beacon.issue.resolve', []))));
            self::assertSame(403, $response->getStatusCode(), $case);
        }
    }

    public function testInvalidButtonValues(): void
    {
        $ingress = new Ingress(legacyUntil: null);
        $this->link($ingress);
        $cases = [
            $ingress->codec->sign('beacon.issue.assign', []),
            '{"a":"resolve"}',
            'nsk2.whatever',
        ];
        foreach ($cases as $value) {
            $response = $ingress->controller->interactions($this->interaction(Fake::blockActions('beacon.issue.resolve', $value)));
            self::assertSame(403, $response->getStatusCode(), $value);
            self::assertSame('Button no longer valid', $response->getContent());
        }
        self::assertSame([], $ingress->handler->seen);
        $ingress->replies->flush();
        self::assertSame(['chat.postEphemeral', 'chat.postEphemeral', 'chat.postEphemeral'], $ingress->api->methods());
    }

    public function testLegacyValuesWhileAllowed(): void
    {
        $ingress = new Ingress();
        $this->link($ingress);
        $response = $ingress->controller->interactions($this->interaction(Fake::blockActions('old_button', '{"a":"resolve","i":"issue-9"}')));
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($ingress->handler->seen[0]->legacy);
        self::assertSame('beacon.issue.resolve', $ingress->handler->seen[0]->actionId);
        self::assertSame('issue-9', $ingress->handler->seen[0]->string('i'));
        self::assertNull($ingress->handler->seen[0]->int('i'));
        self::assertTrue($ingress->events->of(SlackActionHandledEvent::class)[0]->legacy);

        $ingress->clock->modify('@1796428800');
        $later = $ingress->controller->interactions($this->interaction(Fake::blockActions('old_button', '{"a":"resolve"}'), Fake::NOW + 2_592_000));
        self::assertSame(403, $later->getStatusCode(), 'cutoff passed');
    }

    public function testUnknownActionsLinkButtonsAndBrokenHandlers(): void
    {
        $ingress = new Ingress();
        $unknown = $ingress->controller->interactions($this->interaction(Fake::blockActions('demo.unknown', $ingress->codec->sign('demo.unknown', []))));
        self::assertSame(400, $unknown->getStatusCode());

        $payload                      = Fake::blockActions('nowo_slack_kit.link', '');
        $payload['actions'][0]['url'] = 'https://example.test/';
        unset($payload['actions'][0]['value']);
        self::assertSame(200, $ingress->controller->interactions($this->interaction($payload))->getStatusCode());

        $broken = $ingress->controller->interactions($this->interaction(Fake::blockActions('demo.broken', $ingress->codec->sign('demo.broken', []))));
        self::assertSame(500, $broken->getStatusCode());

        $ingress->anonymousHandler->throw = true;
        $failed                           = $ingress->controller->interactions($this->interaction(Fake::blockActions('demo.ping', $ingress->codec->sign('demo.ping', []))));
        self::assertSame(500, $failed->getStatusCode());
        self::assertSame('Error', $failed->getContent());
        self::assertStringNotContainsString(Fake::TOKEN, (string) json_encode($ingress->logger->records), 'the exception class only is logged');
    }

    public function testPerAppRouteUsesThatAppsSecret(): void
    {
        $ingress = new Ingress(['default' => ['app_id' => null, 'signing_secret' => null], 'ops' => ['app_id' => null, 'signing_secret' => Fake::OTHER_SECRET]]);
        $ingress->store->save(new SlackInstallation(new SlackInstallationKey('ops'), SlackSecret::botToken(Fake::TOKEN), SlackTeamId::fromString(Fake::TEAM)));
        $body = Fake::form(Fake::blockActions('demo.ping', $ingress->codec->sign('demo.ping', [])));

        self::assertSame(401, $ingress->controller->interactions($this->raw($body, Fake::signed($body)), 'ops')->getStatusCode());
        self::assertSame(200, $ingress->controller->interactions($this->raw($body, Fake::signed($body, Fake::NOW, Fake::OTHER_SECRET)), 'ops')->getStatusCode());
        self::assertSame('ops:' . Fake::TEAM, $ingress->anonymousHandler->seen[0]->installation->key());
    }

    public function testHostResolverSecretsSkipTheTeamCheckAndSlackAnswers(): void
    {
        $destination = SlackSecret::signingSecret(Fake::OTHER_SECRET);
        $resolver    = new class($destination) implements SigningSecretResolverInterface {
            public int $calls = 0;

            public function __construct(private readonly SlackSecret $secret)
            {
            }

            public function candidates(UnverifiedPayloadPeek $peek): iterable
            {
                ++$this->calls;
                if ($peek->actionDataString('d', '/^dest-\d$/') === null) {
                    return [];
                }
                yield SlackSecret::signingSecret('wrongwrongwrongwrongwrong0000000');
                yield $this->secret;
            }
        };
        $ingress = new Ingress(resolver: $resolver);
        $this->link($ingress);
        $payload = Fake::blockActions('beacon.issue.resolve', $ingress->codec->sign('beacon.issue.resolve', ['d' => 'dest-1']), Fake::OTHER_TEAM);
        $body    = Fake::form($payload);

        $response = $ingress->controller->interactions($this->raw($body, Fake::signed($body, Fake::NOW, Fake::OTHER_SECRET)));
        self::assertSame(403, $response->getStatusCode(), 'the customer workspace has no linked actor');

        $other = Fake::form(Fake::blockActions('demo.ping', $ingress->codec->sign('demo.ping', ['d' => 'dest-1']), Fake::OTHER_TEAM));
        self::assertSame(200, $ingress->controller->interactions($this->raw($other, Fake::signed($other, Fake::NOW, Fake::OTHER_SECRET)))->getStatusCode());
        self::assertTrue($ingress->anonymousHandler->seen[0]->verifiedByResolver);
        $ingress->replies->flush();
        self::assertSame([], $ingress->api->calls, 'never answers a foreign workspace with the instance token');

        $third = Fake::form(Fake::blockActions('demo.ping', $ingress->codec->sign('demo.ping', ['d' => 'dest-2'])));
        self::assertSame(401, (new Ingress(resolver: new class implements SigningSecretResolverInterface {
            public function candidates(UnverifiedPayloadPeek $peek): iterable
            {
                return [SlackSecret::signingSecret('fakesigningsecretnotreal000000aa'), SlackSecret::signingSecret('fakesigningsecretnotreal000000bb'), SlackSecret::signingSecret(Fake::OTHER_SECRET)];
            }
        }))->controller->interactions($this->raw($third, Fake::signed($third, Fake::NOW, Fake::OTHER_SECRET)))->getStatusCode(), 'third candidate is over the cap of 2');
    }

    public function testEventsApi(): void
    {
        $ingress   = new Ingress();
        $challenge = (string) json_encode(['type' => 'url_verification', 'challenge' => 'fake_challenge-1']);
        $response  = $ingress->controller->events($this->raw($challenge, Fake::signed($challenge), 'application/json'));
        self::assertSame('{"challenge":"fake_challenge-1"}', $response->getContent());

        $unsigned = (string) json_encode(['type' => 'url_verification', 'challenge' => 'x']);
        self::assertSame(401, $ingress->controller->events($this->raw($unsigned, [], 'application/json'))->getStatusCode(), 'never an open reflector');
        $badChallenge = (string) json_encode(['type' => 'url_verification', 'challenge' => '<script>']);
        self::assertSame(400, $ingress->controller->events($this->raw($badChallenge, Fake::signed($badChallenge), 'application/json'))->getStatusCode());

        $event = (string) json_encode(['type' => 'event_callback', 'team_id' => Fake::TEAM, 'api_app_id' => Fake::APP_ID, 'event_id' => 'Ev00000001', 'event_time' => Fake::NOW, 'event' => ['type' => 'app_mention']]);
        self::assertSame(200, $ingress->controller->events($this->raw($event, Fake::signed($event), 'application/json'))->getStatusCode());
        // A Slack retry: same event id, new timestamp and signature.
        self::assertSame(200, $ingress->controller->events($this->raw($event, Fake::signed($event, Fake::NOW + 1), 'application/json'))->getStatusCode());
        self::assertCount(1, $ingress->events->of(SlackEventReceivedEvent::class));

        foreach ([
            ['{', 400],
            [(string) json_encode(['type' => 'event_callback', 'team_id' => Fake::TEAM, 'api_app_id' => Fake::APP_ID, 'event_id' => 'bad id', 'event_time' => 1, 'event' => ['type' => 'x']]), 400],
            [(string) json_encode(['type' => 'event_callback', 'team_id' => Fake::OTHER_TEAM, 'api_app_id' => Fake::APP_ID]), 403],
            [(string) json_encode(['type' => 'something_else', 'team_id' => Fake::TEAM, 'api_app_id' => Fake::APP_ID]), 200],
        ] as [$body, $status]) {
            self::assertSame($status, $ingress->controller->events($this->raw($body, Fake::signed($body), 'application/json'))->getStatusCode(), $body);
        }

        $limited = (string) json_encode(['type' => 'app_rate_limited', 'team_id' => Fake::TEAM, 'api_app_id' => Fake::APP_ID, 'minute_rate_limited' => 1_793_836_740]);
        self::assertSame(200, $ingress->controller->events($this->raw($limited, Fake::signed($limited), 'application/json'))->getStatusCode());
        self::assertSame(1_793_836_740, $ingress->events->of(SlackEventsRateLimitedEvent::class)[0]->minuteRateLimited);
        self::assertSame(415, $ingress->controller->events($this->raw($event, Fake::signed($event)))->getStatusCode());
    }

    public function testEventsGoToMessengerWhenAsync(): void
    {
        $bus = new class implements MessageBusInterface {
            /** @var list<Envelope> */
            public array $sent = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return $this->sent[] = Envelope::wrap($message, $stamps);
            }
        };
        $ingress = new Ingress(bus: $bus);
        $event   = (string) json_encode(['type' => 'event_callback', 'team_id' => Fake::TEAM, 'api_app_id' => Fake::APP_ID, 'event_id' => 'Ev00000002', 'event_time' => Fake::NOW, 'event' => ['type' => 'app_uninstalled']]);

        self::assertSame(200, $ingress->controller->events($this->raw($event, Fake::signed($event), 'application/json'))->getStatusCode());
        self::assertCount(1, $bus->sent);
        $message = $bus->sent[0]->getMessage();
        self::assertInstanceOf(HandleSlackEvent::class, $message);
        self::assertSame('default', $message->app);
        self::assertSame(['async'], $bus->sent[0]->last(TransportNamesStamp::class)?->getTransportNames());
        self::assertSame([], $ingress->events->of(SlackEventReceivedEvent::class), 'handled by the worker');
    }

    public function testAFailedEventIsReleasedSoSlackRetriesAreHandled(): void
    {
        $bus = new class implements MessageBusInterface {
            public int $calls = 0;

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                if (++$this->calls === 1) {
                    throw new RuntimeException('transport down');
                }

                return Envelope::wrap($message, $stamps);
            }
        };
        $ingress = new Ingress(bus: $bus);
        $event   = (string) json_encode(['type' => 'event_callback', 'team_id' => Fake::TEAM, 'api_app_id' => Fake::APP_ID, 'event_id' => 'Ev00000003', 'event_time' => Fake::NOW, 'event' => ['type' => 'app_uninstalled']]);

        self::assertSame(500, $ingress->controller->events($this->raw($event, Fake::signed($event), 'application/json'))->getStatusCode());
        self::assertContains('Slack event handling failed.', $ingress->logger->messages());
        // Slack's retry: same event_id, new timestamp and signature.
        self::assertSame(200, $ingress->controller->events($this->raw($event, Fake::signed($event, Fake::NOW + 5), 'application/json'))->getStatusCode());
        self::assertSame(2, $bus->calls);
    }

    /**
     * Audit FS-M1 regression (from the PoC): whoever holds a destination secret signs any team.id / user.id.
     */
    public function testResolverSecretHolderCannotImpersonateAVerifiedActor(): void
    {
        $resolver = new class implements SigningSecretResolverInterface {
            public function candidates(UnverifiedPayloadPeek $peek): iterable
            {
                if ($peek->actionDataString('d', '/^dest-\d$/') !== null) {
                    yield SlackSecret::signingSecret(Fake::OTHER_SECRET);
                }
            }
        };
        $ingress = new Ingress(resolver: $resolver);
        $this->link($ingress); // acc-1 is a verified member (U00000001) of the installation workspace

        // A genuine value from the destination channel, a forged installation team and the victim's user id.
        $value = $ingress->codec->sign('beacon.issue.resolve', ['d' => 'dest-1', 'i' => 'issue-9']);
        $body  = Fake::form(Fake::blockActions('beacon.issue.resolve', $value, Fake::TEAM, Fake::USER));
        $r     = $ingress->controller->interactions($this->raw($body, Fake::signed($body, Fake::NOW, Fake::OTHER_SECRET)));
        self::assertSame(403, $r->getStatusCode());
        self::assertSame('Link and confirm your Slack account', $r->getContent());
        self::assertSame([], $ingress->handler->seen, 'the requiresActor handler never runs');

        // Legacy unsigned values are refused on resolver-verified requests, even inside the legacy window.
        $legacy = Fake::form(Fake::blockActions('whatever', (string) json_encode(['a' => 'resolve', 'd' => 'dest-1', 'i' => 'any-issue']), Fake::TEAM, Fake::USER));
        $r2     = $ingress->controller->interactions($this->raw($legacy, Fake::signed($legacy, Fake::NOW + 1, Fake::OTHER_SECRET)));
        self::assertSame(403, $r2->getStatusCode());
        self::assertSame('Button no longer valid', $r2->getContent());
        self::assertSame([], $ingress->handler->seen);
    }

    public function testResolverActorsNeedTheOptInAndABoundMatchingWorkspace(): void
    {
        $bound = new class implements SigningSecretResolverInterface {
            public function candidates(UnverifiedPayloadPeek $peek): iterable
            {
                yield new ResolvedSigningSecret(SlackSecret::signingSecret(Fake::OTHER_SECRET), SlackTeamId::fromString(Fake::TEAM));
            }
        };
        $click = function (Ingress $ingress, string $team, int $ts): int {
            $body = Fake::form(Fake::blockActions('beacon.issue.resolve', $ingress->codec->sign('beacon.issue.resolve', ['d' => 'dest-1']), $team, Fake::USER));

            return $ingress->controller->interactions($this->raw($body, Fake::signed($body, $ts, Fake::OTHER_SECRET)))->getStatusCode();
        };

        $off = new Ingress(resolver: $bound);
        $this->link($off);
        self::assertSame(403, $click($off, Fake::TEAM, Fake::NOW), 'opt-in off');

        $on = new Ingress(resolver: $bound, resolverActors: true);
        $this->link($on);
        self::assertSame(200, $click($on, Fake::TEAM, Fake::NOW), 'bound workspace = click workspace');
        self::assertSame('acc-1', $on->handler->seen[0]->actor?->accountId);
        self::assertSame(403, $click($on, Fake::OTHER_TEAM, Fake::NOW + 1), 'bound workspace differs');

        $unbound = new Ingress(resolver: new class implements SigningSecretResolverInterface {
            public function candidates(UnverifiedPayloadPeek $peek): iterable
            {
                yield SlackSecret::signingSecret(Fake::OTHER_SECRET);
            }
        }, resolverActors: true);
        $this->link($unbound);
        self::assertSame(403, $click($unbound, Fake::TEAM, Fake::NOW), 'a plain secret is not bound to a workspace');
    }

    private function link(Ingress $ingress, ?DateTimeImmutable $verifiedAt = new DateTimeImmutable('@1')): void
    {
        $ingress->identities->saveLink(new SlackLink('acc-1', SlackTeamId::fromString(Fake::TEAM), SlackUserId::fromString(Fake::USER), new DateTimeImmutable('@1'), $verifiedAt));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function interaction(array $payload, int $timestamp = Fake::NOW): Request
    {
        $body = Fake::form($payload);

        return $this->raw($body, Fake::signed($body, $timestamp));
    }

    /**
     * @param array<string, string> $server
     */
    private function raw(string $body, array $server, string $type = 'application/x-www-form-urlencoded'): Request
    {
        return Request::create('/slack/interactions', 'POST', [], [], [], ['CONTENT_TYPE' => $type] + $server, $body);
    }
}
