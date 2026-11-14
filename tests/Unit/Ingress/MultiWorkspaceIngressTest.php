<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Ingress;

use DateTimeImmutable;
use Nowo\SlackKitBundle\Credentials\InMemoryInstallationBindingStore;
use Nowo\SlackKitBundle\Credentials\SlackInstallationBinding;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Credentials\SlackOwnerScope;
use Nowo\SlackKitBundle\Event\SlackEventReceivedEvent;
use Nowo\SlackKitBundle\Event\SlackInstallationRevokedEvent;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Ingress\EventsHandler;
use Nowo\SlackKitBundle\Ingress\UnverifiedPayloadPeek;
use Nowo\SlackKitBundle\Linking\SlackLink;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use Nowo\SlackKitBundle\Tests\Support\Ingress;
use Nowo\SlackKitBundle\Tests\Support\RecordingDispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Spec 237 T000a: several workspaces behind one app. A request from workspace A never acts as, reads for or answers
 * with the installation of workspace B; own-app installations verify with their own secret; uninstall and token
 * revocation revoke exactly one installation, once.
 */
final class MultiWorkspaceIngressTest extends TestCase
{
    public function testEachWorkspaceResolvesItsOwnInstallationActorAndToken(): void
    {
        $ingress = $this->twoWorkspaces();

        $a = $ingress->controller->interactions($this->click($ingress, Fake::TEAM, Fake::USER));
        $b = $ingress->controller->interactions($this->click($ingress, Fake::OTHER_TEAM, Fake::OTHER_USER));

        self::assertSame(200, $a->getStatusCode(), (string) $a->getContent());
        self::assertSame(200, $b->getStatusCode(), (string) $b->getContent());
        [$seenA, $seenB] = $ingress->handler->seen;
        self::assertSame('default:' . Fake::TEAM, $seenA->installation->key());
        self::assertSame('acc-a', $seenA->actor?->accountId);
        self::assertSame('default:' . Fake::OTHER_TEAM, $seenB->installation->key());
        self::assertSame('acc-b', $seenB->actor?->accountId);

        $ingress->replies->flush();
        self::assertSame(['default:' . Fake::TEAM, 'default:' . Fake::OTHER_TEAM], $ingress->api->keys, 'each answer uses its own workspace installation');
    }

    public function testAClickFromAnotherWorkspaceNeverActsAsTheLinkedAccount(): void
    {
        $ingress = $this->twoWorkspaces();
        // USER is linked in TEAM only; the same member id clicking from OTHER_TEAM has no actor there.
        $value    = $ingress->codec->sign('beacon.issue.resolve', ['i' => 'issue-1'], SlackTeamId::fromString(Fake::OTHER_TEAM));
        $response = $ingress->controller->interactions($this->interaction(Fake::blockActions('beacon.issue.resolve', $value, Fake::OTHER_TEAM, Fake::USER)));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $ingress->handler->seen, 'a handler that needs an actor is never called without one');
        $ingress->replies->flush();
        self::assertSame(['chat.postEphemeral'], $ingress->api->methods());
        self::assertSame(['default:' . Fake::OTHER_TEAM], $ingress->api->keys, 'the refusal is answered in the clicker workspace');
    }

    public function testAButtonSignedForWorkspaceAIsRefusedInWorkspaceB(): void
    {
        $ingress  = $this->twoWorkspaces();
        $value    = $ingress->codec->sign('beacon.issue.resolve', ['i' => 'issue-1'], SlackTeamId::fromString(Fake::TEAM));
        $response = $ingress->controller->interactions($this->interaction(Fake::blockActions('beacon.issue.resolve', $value, Fake::OTHER_TEAM, Fake::OTHER_USER)));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Button no longer valid', $response->getContent());
        self::assertSame([], $ingress->handler->seen);
    }

    public function testAnonymousOnlyForHandlersMarkedForIt(): void
    {
        $ingress  = $this->twoWorkspaces();
        $unlinked = 'U00000009';

        $needsActor = $ingress->controller->interactions($this->interaction(Fake::blockActions('beacon.issue.resolve', $ingress->codec->sign('beacon.issue.resolve', [], SlackTeamId::fromString(Fake::TEAM)), Fake::TEAM, $unlinked)));
        self::assertSame(403, $needsActor->getStatusCode());
        self::assertSame([], $ingress->handler->seen);

        $anonymous = $ingress->controller->interactions($this->interaction(Fake::blockActions('demo.ping', $ingress->codec->sign('demo.ping', [], SlackTeamId::fromString(Fake::TEAM)), Fake::TEAM, $unlinked)));
        self::assertSame(200, $anonymous->getStatusCode());
        self::assertNull($ingress->anonymousHandler->seen[0]->actor);
    }

    public function testUnknownRevokedAndForeignEnterpriseOrAppWorkspacesAreRefused(): void
    {
        $ingress = $this->twoWorkspaces();
        $ingress->store->save(Fake::installation('T00000005', Fake::OTHER_TOKEN, Fake::ENTERPRISE, Fake::OTHER_APP_ID));
        $ingress->store->save(Fake::installation('T00000006', Fake::OTHER_TOKEN)->revoked(new DateTimeImmutable('@1'), 'app_uninstalled'));
        $sign = static fn (string $team): string => $ingress->codec->sign('demo.ping', [], SlackTeamId::fromString($team));

        self::assertSame(403, $ingress->controller->interactions($this->interaction(Fake::blockActions('demo.ping', $sign('T00000009'), 'T00000009')))->getStatusCode(), 'not installed');
        self::assertSame(403, $ingress->controller->interactions($this->interaction(Fake::blockActions('demo.ping', $sign('T00000006'), 'T00000006')))->getStatusCode(), 'revoked');

        $foreignEnterprise               = Fake::blockActions('demo.ping', $sign('T00000005'), 'T00000005');
        $foreignEnterprise['enterprise'] = ['id' => Fake::OTHER_ENTERPRISE];
        $foreignEnterprise['api_app_id'] = Fake::OTHER_APP_ID;
        $response                        = $ingress->controller->interactions($this->interaction($foreignEnterprise));
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Workspace mismatch', $response->getContent());

        $sameEnterprise               = Fake::blockActions('demo.ping', $sign('T00000005'), 'T00000005');
        $sameEnterprise['enterprise'] = ['id' => Fake::ENTERPRISE];
        $response                     = $ingress->controller->interactions($this->interaction($sameEnterprise));
        self::assertSame(403, $response->getStatusCode(), 'the installation records its own app id: the configured one is not enough');
        self::assertSame('App mismatch', $response->getContent());

        $sameEnterprise['api_app_id'] = Fake::OTHER_APP_ID;
        self::assertSame(200, $ingress->controller->interactions($this->interaction($sameEnterprise))->getStatusCode());
        self::assertSame('default:' . Fake::ENTERPRISE . ':T00000005', $ingress->anonymousHandler->seen[0]->installation->key());
    }

    public function testOwnAppInstallationsVerifyWithTheirOwnSecretOnly(): void
    {
        $ingress = $this->twoWorkspaces();
        $ingress->store->save(Fake::installation(Fake::OTHER_TEAM, Fake::OTHER_TOKEN, null, Fake::OTHER_APP_ID, Fake::OTHER_SECRET));
        $own               = Fake::blockActions('beacon.issue.resolve', $ingress->codec->sign('beacon.issue.resolve', [], SlackTeamId::fromString(Fake::OTHER_TEAM)), Fake::OTHER_TEAM, Fake::OTHER_USER);
        $own['api_app_id'] = Fake::OTHER_APP_ID;

        $response = $ingress->controller->interactions($this->interaction($own, Fake::NOW, Fake::OTHER_SECRET));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('acc-b', $ingress->handler->seen[0]->actor?->accountId, 'an own-app click is bound to its workspace: the actor is trusted');
        self::assertSame('default:' . Fake::OTHER_TEAM, $ingress->handler->seen[0]->installation->key());

        // The own app's secret cannot speak for another workspace (nor for the app's other installations).
        $forged               = Fake::blockActions('beacon.issue.resolve', $ingress->codec->sign('beacon.issue.resolve', [], SlackTeamId::fromString(Fake::TEAM)), Fake::TEAM, Fake::USER);
        $forged['api_app_id'] = Fake::OTHER_APP_ID;
        self::assertSame(401, $ingress->controller->interactions($this->interaction($forged, Fake::NOW, Fake::OTHER_SECRET))->getStatusCode());

        // The distributed app's secret cannot speak for the own-app workspace's app either.
        $ingress->handler->seen = [];
        $wrongApp               = Fake::blockActions('beacon.issue.resolve', $ingress->codec->sign('beacon.issue.resolve', [], SlackTeamId::fromString(Fake::OTHER_TEAM)), Fake::OTHER_TEAM, Fake::OTHER_USER);
        $response               = $ingress->controller->interactions($this->interaction($wrongApp));
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('App mismatch', $response->getContent());
        self::assertSame([], $ingress->handler->seen);

        // Revoked: its secret is no longer tried.
        $ingress->store->markRevoked(new SlackInstallationKey('default', SlackTeamId::fromString(Fake::OTHER_TEAM)), new DateTimeImmutable('@1'));
        $again               = Fake::blockActions('demo.ping', $ingress->codec->sign('demo.ping', [], SlackTeamId::fromString(Fake::OTHER_TEAM)), Fake::OTHER_TEAM, Fake::OTHER_USER);
        $again['api_app_id'] = Fake::OTHER_APP_ID;
        self::assertSame(401, $ingress->controller->interactions($this->interaction($again, Fake::NOW, Fake::OTHER_SECRET))->getStatusCode());
    }

    public function testOwnAppEventsAndSignatureReplayAndSkew(): void
    {
        $ingress = $this->twoWorkspaces();
        $ingress->store->save(Fake::installation(Fake::OTHER_TEAM, Fake::OTHER_TOKEN, null, Fake::OTHER_APP_ID, Fake::OTHER_SECRET));
        $envelope = (string) json_encode(['type' => 'event_callback', 'team_id' => Fake::OTHER_TEAM, 'api_app_id' => Fake::OTHER_APP_ID, 'event_id' => 'Ev0001', 'event_time' => Fake::NOW, 'event' => ['type' => 'app_mention']]);

        $skewed = $ingress->controller->events($this->raw($envelope, Fake::signed($envelope, Fake::NOW - 301, Fake::OTHER_SECRET), 'application/json'));
        self::assertSame(401, $skewed->getStatusCode(), 'outside the 5-minute window');
        $future = $ingress->controller->events($this->raw($envelope, Fake::signed($envelope, Fake::NOW + 301, Fake::OTHER_SECRET), 'application/json'));
        self::assertSame(401, $future->getStatusCode(), 'from the future too');
        $tampered = $ingress->controller->events($this->raw($envelope . ' ', Fake::signed($envelope, Fake::NOW, Fake::OTHER_SECRET), 'application/json'));
        self::assertSame(401, $tampered->getStatusCode(), 'the signature covers the raw body');

        $first  = $ingress->controller->events($this->raw($envelope, Fake::signed($envelope, Fake::NOW, Fake::OTHER_SECRET), 'application/json'));
        $replay = $ingress->controller->events($this->raw($envelope, Fake::signed($envelope, Fake::NOW, Fake::OTHER_SECRET), 'application/json'));
        self::assertSame(200, $first->getStatusCode(), (string) $first->getContent());
        self::assertSame(401, $replay->getStatusCode(), 'a replayed signature is refused');
        $received = $ingress->events->of(SlackEventReceivedEvent::class);
        self::assertCount(1, $received);
        self::assertSame('default:' . Fake::OTHER_TEAM, $received[0]->installation->key());
    }

    public function testUninstallRevokesOnlyItsWorkspaceOnceAndListsItsBindings(): void
    {
        $ingress  = $this->twoWorkspaces();
        $bindings = new InMemoryInstallationBindingStore();
        $other    = new SlackInstallationKey('default', SlackTeamId::fromString(Fake::OTHER_TEAM));
        $bindings->bind(new SlackInstallationBinding($other, new SlackOwnerScope('organization', 'org-1')));
        $bindings->bind(new SlackInstallationBinding($other, new SlackOwnerScope('organization', 'org-2')));
        $bindings->bind(new SlackInstallationBinding(new SlackInstallationKey('default', SlackTeamId::fromString(Fake::TEAM)), new SlackOwnerScope('organization', 'org-3')));
        $events  = new RecordingDispatcher();
        $handler = new EventsHandler($ingress->store, $events, $ingress->clock, $bindings);

        $handler->handle('default', ['team_id' => Fake::OTHER_TEAM, 'event_id' => 'Ev1', 'event_time' => Fake::NOW, 'event' => ['type' => 'app_uninstalled']]);
        $handler->handle('default', ['team_id' => Fake::OTHER_TEAM, 'event_id' => 'Ev2', 'event_time' => Fake::NOW, 'event' => ['type' => 'tokens_revoked', 'tokens' => ['bot' => ['U0000000B']]]]);

        $revoked = $events->of(SlackInstallationRevokedEvent::class);
        self::assertCount(1, $revoked, 'revoked once');
        self::assertSame('app_uninstalled', $revoked[0]->reason);
        self::assertSame('default:' . Fake::OTHER_TEAM, $revoked[0]->installation->key());
        self::assertSame(['organization:org-1', 'organization:org-2'], array_map(static fn (SlackInstallationBinding $b): string => $b->scope->key(), $revoked[0]->bindings));
        self::assertTrue($ingress->store->find($other)?->isRevoked());
        self::assertFalse($ingress->store->find(new SlackInstallationKey('default', SlackTeamId::fromString(Fake::TEAM)))?->isRevoked(), 'the other workspace keeps working');
        self::assertCount(2, $events->of(SlackEventReceivedEvent::class));

        // Afterwards a click from the revoked workspace is refused.
        $response = $ingress->controller->interactions($this->click($ingress, Fake::OTHER_TEAM, Fake::OTHER_USER));
        self::assertSame(403, $response->getStatusCode());
    }

    public function testTokensRevokedCountsOnlyForTheInstallationBot(): void
    {
        $ingress = $this->twoWorkspaces();
        $ingress->store->save(Fake::installation(Fake::OTHER_TEAM, Fake::OTHER_TOKEN, null, null, null, 'U0000000B'));
        $events  = new RecordingDispatcher();
        $handler = new EventsHandler($ingress->store, $events, $ingress->clock);
        $other   = new SlackInstallationKey('default', SlackTeamId::fromString(Fake::OTHER_TEAM));

        $handler->handle('default', ['team_id' => Fake::OTHER_TEAM, 'event' => ['type' => 'tokens_revoked', 'tokens' => ['oauth' => ['U00000001']]]]);
        $handler->handle('default', ['team_id' => Fake::OTHER_TEAM, 'event' => ['type' => 'tokens_revoked', 'tokens' => ['bot' => ['U0000000Z']]]]);
        $handler->handle('default', ['event' => ['type' => 'app_uninstalled']]);
        $handler->handle('default', ['team_id' => 'T00000009', 'event' => ['type' => 'app_uninstalled']]);
        self::assertSame([], $events->of(SlackInstallationRevokedEvent::class));
        self::assertFalse($ingress->store->find($other)?->isRevoked());

        $handler->handle('default', ['team_id' => Fake::OTHER_TEAM, 'enterprise_id' => Fake::ENTERPRISE, 'event' => ['type' => 'tokens_revoked', 'tokens' => ['bot' => ['U0000000B']]]]);
        self::assertCount(1, $events->of(SlackInstallationRevokedEvent::class));
        self::assertSame([], $events->of(SlackInstallationRevokedEvent::class)[0]->bindings, 'no binding store: no bindings');
        self::assertTrue($ingress->store->find($other)?->isRevoked());
    }

    public function testUnverifiedTeamPeek(): void
    {
        self::assertSame(Fake::TEAM, UnverifiedPayloadPeek::teamId(Fake::form(Fake::blockActions('a', 'b')), true));
        self::assertNull(UnverifiedPayloadPeek::teamId('payload=%7B', true));
        self::assertNull(UnverifiedPayloadPeek::teamId('nothing=1', true));
        self::assertSame(Fake::TEAM, UnverifiedPayloadPeek::teamId((string) json_encode(['team_id' => Fake::TEAM]), false));
        self::assertNull(UnverifiedPayloadPeek::teamId('{', false));
        self::assertNull(UnverifiedPayloadPeek::teamId('[1]', false));
    }

    private function twoWorkspaces(): Ingress
    {
        $ingress = new Ingress();
        $ingress->store->save(Fake::installation(Fake::OTHER_TEAM, Fake::OTHER_TOKEN));
        $ingress->identities->saveLink(new SlackLink('acc-a', SlackTeamId::fromString(Fake::TEAM), SlackUserId::fromString(Fake::USER), new DateTimeImmutable('@1'), new DateTimeImmutable('@1')));
        $ingress->identities->saveLink(new SlackLink('acc-b', SlackTeamId::fromString(Fake::OTHER_TEAM), SlackUserId::fromString(Fake::OTHER_USER), new DateTimeImmutable('@1'), new DateTimeImmutable('@1')));

        return $ingress;
    }

    private function click(Ingress $ingress, string $team, string $user): Request
    {
        return $this->interaction(Fake::blockActions('beacon.issue.resolve', $ingress->codec->sign('beacon.issue.resolve', ['i' => 'issue-1'], SlackTeamId::fromString($team)), $team, $user));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function interaction(array $payload, int $timestamp = Fake::NOW, string $secret = Fake::SIGNING_SECRET): Request
    {
        $body = Fake::form($payload);

        return $this->raw($body, Fake::signed($body, $timestamp, $secret));
    }

    /**
     * @param array<string, string> $server
     */
    private function raw(string $body, array $server, string $type = 'application/x-www-form-urlencoded'): Request
    {
        return Request::create('/slack/interactions', 'POST', [], [], [], ['CONTENT_TYPE' => $type] + $server, $body);
    }
}
