# Usage

## Table of contents

- [Installations and the Web API client](#installations-and-the-web-api-client)
  - [Errors and rate limits](#errors-and-rate-limits)
  - [Incoming webhooks](#incoming-webhooks)
- [Building messages (Block Kit and mrkdwn)](#building-messages-block-kit-and-mrkdwn)
- [Signed button values (nsk1)](#signed-button-values-nsk1)
- [Handling clicks](#handling-clicks)
  - [The actor](#the-actor)
  - [Replies](#replies)
  - [Per-destination signing secrets](#per-destination-signing-secrets)
- [Events API](#events-api)
- [Account linking](#account-linking)
  - [Identity store](#identity-store)
  - [Account resolver](#account-resolver)
  - [The account card and its routes](#the-account-card-and-its-routes)
  - [Linker API](#linker-api)
- [Settings section and Test connection](#settings-section-and-test-connection)
- [Installation stores](#installation-stores)
  - [Testing your installation store](#testing-your-installation-store)
- [Notifier transport](#notifier-transport)
- [Events reference](#events-reference)
- [Twig functions](#twig-functions)

## Installations and the Web API client

Every call names an **installation** (`SlackInstallationKey`: app name, optional workspace). A host with one Slack app
in one workspace uses `SlackInstallationKey::default()`. The client asks the installation store for the bot token at
call time and keeps it only for that request.

```php
use Nowo\SlackKitBundle\Api\SlackApiClientInterface;
use Nowo\SlackKitBundle\Api\SlackMessage;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Id\SlackUserId;

$key     = SlackInstallationKey::default();
$channel = $slack->openDirectMessage($key, SlackUserId::fromString('U00000001'));    // conversations.open
$posted  = $slack->postMessage($key, $channel, SlackMessage::plain('Hello'));        // chat.postMessage
$slack->updateMessage($key, $posted, SlackMessage::plain('Hello again'));            // chat.update
$slack->deleteMessage($key, $posted);                                                // chat.delete
$slack->postEphemeral($key, $channel, SlackUserId::fromString('U00000001'), SlackMessage::plain('Only you'));
$user    = $slack->lookupUserByEmail($key, 'someone@example.test');                  // ?SlackUserId
$auth    = $slack->authTest($key);                                                   // team, teamId, user, userId, botId
```

- `SlackMessage($text, $blocks = [], $unfurlLinks = false, $unfurlMedia = false, $threadTs = null, $rawText = false)`:
  the fallback `text` is required (Slack accessibility rule; empty text throws); unfurling is off by default. The
  fallback text is **mrkdwn-escaped** (`&`, `<`, `>`) when sent (after truncation), so user data such as an issue
  title `<!channel>` cannot ping a channel or disguise a link in the notification. Pass `rawText: true` only for text
  built with `Mrkdwn::escape()` / `Mrkdwn::link()` / `Mrkdwn::mention()`.
- `SlackUserId`, `SlackTeamId`, `SlackChannelId` are validated value objects (`fromString()` throws, `tryFrom()`
  returns `null`), so a malformed id never reaches Slack.
- `lookupUserByEmail()` throws `EmailLookupDisabledException` unless the installation allows it (setting
  `allow_email_lookup`, scope `users:read.email`).

### Errors and rate limits

| Outcome | Exception |
| --- | --- |
| Transport error or timeout | `SlackApiException('transport_error')`; its `previous` is a `RedactedTransportException` (class and `SecretRedactor`-redacted message of the HttpClient failure, no further chain) |
| Response over `api.max_response_bytes` | `SlackApiException('response_too_large')` |
| Body not a JSON object | `SlackApiException('invalid_response')` |
| `ok: false` | `SlackApiException(<error>)` (`unknown_error` when the code does not match `^[a-z0-9_]{1,64}$`) |
| `not_authed`, `invalid_auth`, `account_inactive`, `token_revoked`, `token_expired` | `SlackAuthException`; dispatches `SlackInstallationUnhealthyEvent` at most every 10 min per installation |
| `missing_scope` | `SlackScopeException` (`needed` scope) |
| HTTP 429 or `ratelimited` | `SlackRateLimitedException` (`retryAfterSeconds`, clamped) |
| No installation in the store, or revoked | `SlackApiException('not_installed')` (Slack is not called) |

All extend `SlackApiException`, whose public `errorCode` is stable and whose message is `Slack API error: <code>`
only. `SlackRateLimitedException` implements `RecoverableSlackException`. The client never sleeps: catch it and retry
later, or let the Messenger middleware do it ([CONFIGURATION.md](CONFIGURATION.md#messenger)). While a 429 is
remembered, calls to the same method of the same installation fail fast without reaching Slack.

### Incoming webhooks

```php
use Nowo\SlackKitBundle\Api\IncomingWebhookClient;
use Nowo\SlackKitBundle\Security\SlackSecret;

$webhooks->post(SlackSecret::webhookUrl($destination->webhookUrl()), $message);
```

Only `https://hooks.slack.com/services/…` URLs are accepted (exact host, path pattern, no redirects). Keep the URL
encrypted at rest: anyone holding it can post to the channel. The client uses its own HttpClient
(`nowo_slack_kit.webhook_http_client`) with no logger and no profiler tracing, so the URL never reaches the
`http_client` log or the profiler, and a transport error never carries it.

## Building messages (Block Kit and mrkdwn)

```php
use Nowo\SlackKitBundle\BlockKit\Blocks;
use Nowo\SlackKitBundle\BlockKit\Mrkdwn;

$blocks = [
    Blocks::header('Deploy finished'),
    Blocks::section('Release *'.Mrkdwn::escape($tag).'* is live: '.Mrkdwn::link($url, 'notes'), raw: true),
    Blocks::context(['by '.$author]),
    Blocks::divider(),
    Blocks::actions([
        Blocks::button('Roll back', 'acme.deploy.rollback', $value, 'danger', [
            'title' => 'Roll back?', 'text' => 'This redeploys the previous release.', 'confirm' => 'Roll back', 'deny' => 'Cancel',
        ]),
        Blocks::linkButton('Open', 'https://example.test/deploys/42'),
    ]),
];
```

- Text arguments are escaped with `Mrkdwn::escape()` (`&`, `<`, `>`) unless `raw: true`; build links and mentions
  with `Mrkdwn::link()` / `Mrkdwn::mention()` (their parts are escaped; `|`, `<`, `>` in URLs are refused; only
  `https:` unless `$allowHttp`).
- Limits: 50 blocks, 3000 characters per section text, 150 per header, 75 per button text, 2000 per value, 25
  elements per actions block, 10 context elements and fields. Text is truncated with an ellipsis; structure
  overflows throw `BlockKitLimitException`.

## Signed button values (nsk1)

Every button that triggers a handler should carry a value signed with `ButtonValueCodec`:

```php
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Interaction\ButtonValueCodec;

$value = $codec->sign('acme.issue.resolve', ['p' => $projectId, 'i' => $issueId]);                  // no team
$value = $codec->sign('acme.task.done', ['t' => '42'], SlackTeamId::fromString('T00000000'));      // with team
$value = $codec->sign('acme.task.done', ['t' => '42'], null, 3600);                                 // own TTL
```

- Format `nsk1.<payload_b64>.<mac_b64>` (contract §6.5): canonical JSON `{"d":{…},"exp":…,"t":"T…"}`, key
  HKDF-SHA256(`kernel.secret`, info `nowo_slack_kit.button.v1`), MAC over `nsk1\n<action_id>\n<payload_b64>`
  truncated to 16 bytes, base64url without padding.
- Data: string keys `^[a-z0-9_]{1,16}$`, values strings, integers, booleans or nested objects (depth ≤ 3); no floats,
  `null` or lists. The canonical JSON must stay ≤ 1400 bytes. Violations throw `InvalidButtonValueException`
  (`unencodable`).
- `t` is added only when you pass the team (messages posted through incoming webhooks do not know it). When present,
  a click from another workspace is refused.
- The MAC binds the `action_id`: a *Resolve* value cannot be replayed on an *Assign* button.
- Values are not secret (anyone in the channel sees them). Do not put personal data in them.

## Handling clicks

```php
use Nowo\SlackKitBundle\Interaction\AsSlackActionHandler;
use Nowo\SlackKitBundle\Interaction\SlackActionHandlerInterface;
use Nowo\SlackKitBundle\Interaction\SlackInteraction;
use Nowo\SlackKitBundle\Interaction\SlackInteractionReply;

#[AsSlackActionHandler(action: 'acme.issue.resolve')]           // requiresActor: true by default
final readonly class ResolveIssueHandler implements SlackActionHandlerInterface
{
    public function __construct(private IssueService $issues) {}

    public function handle(SlackInteraction $interaction): SlackInteractionReply
    {
        $issue = $this->issues->find((string) $interaction->string('i'));
        if ($issue === null) {
            return SlackInteractionReply::gone()->replaceOriginal(SlackMessage::plain('No longer available'));
        }
        if (!$this->issues->canResolve($interaction->actor->accountId, $issue)) {   // the host's own authorization
            return SlackInteractionReply::noAccess();
        }
        $this->issues->resolve($issue);                                             // idempotent

        return SlackInteractionReply::done();
    }
}
```

Before a handler runs, the ingress has verified the request signature, window, replay and workspace, and the router
has verified the `nsk1` value (MAC, expiry, team). `SlackInteraction` exposes `installation`, `actionId`, `data`
(the verified `d`), `team`, `user` (the clicker), `actor`, `channel`, `messageTs`, `interactionId` (a stable
idempotency key), `legacy` and `verifiedByResolver`; `string($key)` / `int($key)` read typed data. No raw body,
headers or token.

Answers to Slack: unknown `action_id` → 400 `Unsupported action`; bad value → 403 + ephemeral *This button is no
longer valid*; handler exception → 500 + generic ephemeral (logged with the exception class only).

### The actor

`requiresActor: true` handlers run only when `ActorResolver` finds a link for `(team, clicker)` that is **verified**,
belongs to an **active** account and to the click's workspace. Otherwise the clicker gets the ephemeral *Link and
confirm your Slack account first* and Slack gets 403. Use `requiresActor: false` for handlers that make sense without
an account (the built-in link confirmation is one). Linking must be enabled for actors to exist.

### Replies

`SlackInteractionReply::done()`, `gone()` (404), `noAccess()` (403), `forbidden()` (403), plus
`->withEphemeral(SlackMessage)`, `->replaceOriginal(SlackMessage)` and `->deleteOriginal()`. The Slack calls
(`chat.postEphemeral`, `chat.update`, `chat.delete`) run on `kernel.terminate`, after the HTTP answer, so a slow Slack
call never pushes the 3-second limit. Failures are logged with the error code. For clicks verified with a secret from
a host resolver (see below) no Slack call is made: the bundle has no bot token for that workspace.

### Per-destination signing secrets

When channel messages are posted through customers' own Slack apps (incoming webhooks), each click is signed with that
app's secret. Implement `SigningSecretResolverInterface` and set `ingress.signing_secret_resolver`:

```php
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Ingress\ResolvedSigningSecret;
use Nowo\SlackKitBundle\Ingress\SigningSecretResolverInterface;
use Nowo\SlackKitBundle\Ingress\UnverifiedPayloadPeek;
use Nowo\SlackKitBundle\Security\SlackSecret;

final readonly class DestinationSecretResolver implements SigningSecretResolverInterface
{
    public function candidates(UnverifiedPayloadPeek $peek): iterable
    {
        $id = $peek->actionDataString('d', '/^[0-9a-f-]{36}$/');   // unverified: only chooses which secret to try
        $destination = $id !== null ? $this->destinations->find($id) : null;
        if ($destination !== null) {
            // A plain SlackSecret is enough; bind the workspace only if you opt in to resolver actors.
            yield new ResolvedSigningSecret(
                SlackSecret::signingSecret($destination->signingSecret()),
                SlackTeamId::tryFrom($destination->slackTeamId()),   // e.g. T00000000, or null when unknown
            );
        }
    }
}
```

The peek exposes only string fields of the first action's data that match your pattern; at most
`ingress.max_secret_candidates` secrets are tried and every failure gives the same 401. Candidates are `SlackSecret`
or `ResolvedSigningSecret` objects.

Clicks verified this way skip the installation workspace check and carry `verifiedByResolver: true`. Whoever holds
such a secret (the customer's Slack app, its admins, whoever pasted it) can sign **any** `team.id` and `user.id`, so:

- the click gets **no actor**: handlers with `requiresActor: true` answer 403 *Link and confirm…*; handlers with
  `requiresActor: false` run and must authorise from their own verified data (the `nsk1` value, the destination);
- legacy unsigned values are refused (403 *This button is no longer valid*), even inside the legacy window;
- the built-in link confirmation refuses it.

Opt-in: with `ingress.resolver_actors: true`, an actor is resolved when the verifying candidate was a
`ResolvedSigningSecret` bound to a workspace and the click's `team.id` equals it. This trusts every holder of every
such secret to act as any linked member of that workspace; enable it only when those secrets are held by your
operators alone.

## Events API

Enable `ingress.events_enabled`, add the Events URL (`nowo_slack_kit_ingress_url('events')`) and the bot events
`app_uninstalled` and `tokens_revoked` to the Slack app. Then:

- `url_verification` is answered only after the signature and replay checks;
- `app_uninstalled` / `tokens_revoked` (bot tokens) call `markRevoked()` on the store and dispatch
  `SlackInstallationRevokedEvent`;
- every `event_callback` is dispatched as `SlackEventReceivedEvent` (installation key, `eventId`, `eventTime`, `type`,
  decoded `event`), inline or through Messenger with `ingress.async: true`;
- Slack retries of the same `event_id` within an hour answer 200 and are dropped; when handling fails (inline
  listener or Messenger dispatch) the claim is released and the endpoint answers 500, so Slack's retry is handled;
- `app_rate_limited` dispatches `SlackEventsRateLimitedEvent`.

```php
#[AsEventListener]
public function onSlackEvent(SlackEventReceivedEvent $event): void
{
    if ($event->type === 'member_joined_channel') { /* … */ }
}
```

## Account linking

Linking proves that a Slack member is the owner of an account: a typed (or e-mail-found) member id is only a
**claim** until the member clicks *Confirm* in a DM. Enable it with the two host services:

```yaml
nowo_slack_kit:
    linking:
        enabled: true
        identity_store: App\Slack\UserSlackIdentityStore
        account_resolver: App\Slack\CurrentUserAccountResolver
        email_lookup: true            # optional, needs allow_email_lookup and users:read.email
        return_route: app_profile
```

### Identity store

```php
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Linking\SlackIdentityStoreInterface;
use Nowo\SlackKitBundle\Linking\SlackLink;

final class UserSlackIdentityStore implements SlackIdentityStoreInterface
{
    public function findLinkByAccount(string $accountId): ?SlackLink { /* … */ }
    public function findLinkBySlackUser(SlackTeamId $team, SlackUserId $user): ?SlackLink { /* … */ }
    public function isAccountActive(string $accountId): bool { /* enabled and not anonymised */ }
    public function accountEmail(string $accountId): ?string { /* confirmed e-mail only */ }
    public function accountLocale(string $accountId): ?string { /* … */ }
    public function saveLink(SlackLink $link): void { /* claim or verify */ }
    public function removeLink(string $accountId): void { /* unlink or release */ }
}
```

`SlackLink` = `accountId`, `team`, `slackUserId`, `claimedAt`, `verifiedAt` (`null` until confirmed), `source`
(`typed` | `email_lookup`). Keep **one Slack id per account** and **one account per (team, Slack id)** with a unique
index; changing the id must clear `verifiedAt`. A single-workspace host can store only the Slack id, claim time and
verification time and take `team` from its installation.

### Account resolver

```php
final readonly class CurrentUserAccountResolver implements SlackAccountResolverInterface
{
    public function currentAccountId(): ?string
    {
        return $this->security->getUser()?->getPublicId();   // never from the request
    }
}
```

### The account card and its routes

Include the card in the profile page (it renders nothing for guests or when linking is disabled):

```twig
{{ include('@NowoSlackKitBundle/account/_link.html.twig') }}
```

It shows the status (`none` / `claimed` / `verified`), the member-id form while not verified, and the actions
*Find me by e-mail*, *Send confirmation* and *Unlink* — each a POST with a CSRF token to
`{ui.prefix}/account/slack/{claim,find,confirm,unlink}` (routes `nowo_slack_kit_link_*`). Results become translated
flashes; none reveals which account holds an id. Overrides: [TEMPLATES.md](TEMPLATES.md).

### Linker API

`AccountLinker` (public service) for hosts with their own UI:

| Method | Result |
| --- | --- |
| `claim($accountId, $slackUserId)` | `Claimed`, `AlreadyVerified`, `InUse`, `Invalid`, `Unavailable` (no workspace id), `RateLimited` |
| `findByEmail($accountId)` | `Found`, `NotFound`, `InUse`, `Unavailable`, `RateLimited` |
| `sendConfirmation($accountId)` | `ConfirmationSent`, `ConfirmationFailed`, `AlreadyVerified`, `Unavailable`, `RateLimited` |
| `unlink($accountId)` | `Unlinked` (dispatches `SlackAccountUnlinkedEvent`) |
| `linkOf($accountId)`, `canFindByEmail()` | current link, whether e-mail lookup is offered |

Rules: a verified link is never taken; an unverified link of another account yields after `claim_ttl` (event reason
`expired`) or at once to an e-mail-proven claim (`claimed_by_email`). The confirmation DM carries a button
`nowo_slack_kit.link.confirm` with signed data `{acc, c, uid}` (`c` = claim time) for the workspace, valid for
`confirmation_ttl`. The click verifies the link only when the account and Slack id in the value, the clicker, the
link's current Slack id, the workspace and `c` all match and the account is active; then
`SlackAccountVerifiedEvent` and the DM is replaced with *Your Slack account is linked*. Confirming twice is a no-op.
When the account is disabled or erased, call `unlink()` (or remove the link): actors already require an active
account.

## Settings section and Test connection

With `credentials.store: instance_settings` the InstanceSettings admin gets a **Slack** section: bot token (`xoxb-`
only; user, app-level and refresh tokens are refused), signing secret, workspace id, *Allow "Find me by e-mail"*.
Secrets are stored encrypted and never rendered back. The intro shows the Interactivity URL to paste into the Slack
app. *Test connection* (once a token is stored) runs `auth.test`, shows the workspace and bot user, and fills an empty
workspace id. Notices: *not configured* and *workspace id missing*; add your own by implementing
`SlackSettingsNoticeProviderInterface` (autoconfigured).

## Installation stores

Implement `SlackInstallationStoreInterface` (`find()`, `findSigningSecret()`, `save()`, `markRevoked()`) for
multi-tenant hosts and set `credentials.store` to its service id. Return `SlackSecret` objects, scope reads to the
key, encrypt at rest ([SECURITY.md](SECURITY.md#installation-store-contract)). `InMemoryInstallationStore` is for
tests and demos (fake values only).

### Testing your installation store

The store contract ships as an abstract PHPUnit test case (requires `phpunit/phpunit` in your dev dependencies).
Extend it and return your store seeded with one installation of the default app in workspace `T00000000`, bot token
`xoxb-test-not-a-real-token` and signing secret `testsigningsecretnotreal00000000` (the class constants):

```php
use Nowo\SlackKitBundle\Credentials\SlackInstallationStoreInterface;
use Nowo\SlackKitBundle\Test\SlackInstallationStoreContractTestCase;

final class DoctrineSlackStoreContractTest extends SlackInstallationStoreContractTestCase
{
    protected function createStore(): SlackInstallationStoreInterface
    {
        // Seed self::TEAM, self::BOT_TOKEN and self::SIGNING_SECRET into a fresh test database.
        return $this->seededStore();
    }

    protected function rawStorage(): ?string
    {
        return $this->dumpRows();   // optional: proves the token and secret are not stored in clear
    }
}
```

It checks that `find()` returns `SlackSecret` objects, that reads are scoped to the key, that `markRevoked()` is
idempotent and leaves no usable installation, and, when `rawStorage()` returns the backend contents, that no secret
is stored in clear. The bundle runs it against `InMemoryInstallationStore` and `InstanceSettingsInstallationStore`.

## Notifier transport

```yaml
nowo_slack_kit:
    notifier:
        enabled: true
framework:
    notifier:
        chatter_transports:
            slack_kit: 'nowo-slack://default?app=default'
```

```php
use Nowo\SlackKitBundle\Notifier\SlackKitOptions;
use Symfony\Component\Notifier\Message\ChatMessage;

$message = (new ChatMessage('Build failed'))->options(new SlackKitOptions(SlackChannelId::fromString('C00000001')));
$sent    = $chatter->send($message);   // getMessageId() = "C00000001:<ts>"
```

The DSN never holds a secret (credentials in the DSN are refused); the token comes from the store. A `SlackUserId`
recipient opens a DM first. The `ChatMessage` subject becomes the fallback text and is mrkdwn-escaped like any
`SlackMessage` text. The scheme is `nowo-slack`, so `symfony/slack-notifier` can coexist.

## Events reference

| Event | When |
| --- | --- |
| `SlackEventReceivedEvent` | A verified, de-duplicated Events API callback |
| `SlackInteractionReceivedEvent` | A verified interaction, before routing (also types 1.0 does not route) |
| `SlackActionHandledEvent` | After a handler returned (action id, actor account id, status, legacy flag) |
| `SlackAccountClaimedEvent` / `SlackAccountVerifiedEvent` / `SlackAccountUnlinkedEvent` | Linking transitions |
| `SlackLinkReleasedEvent` | An unverified link yielded (holder, claimant, Slack id, reason `expired` / `claimed_by_email`) |
| `SlackConfirmationRateLimitedEvent` | A linking limiter refused (account id, limiter) |
| `SlackInstallationRevokedEvent` | `app_uninstalled` / `tokens_revoked` |
| `SlackInstallationUnhealthyEvent` | Repeated auth errors (at most every 10 min per installation) |
| `SlackEventsRateLimitedEvent` | `app_rate_limited` callback |

No event carries a token, a signature or a raw body.

## Twig functions

| Function | Returns |
| --- | --- |
| `nowo_slack_kit_ingress_url(kind = 'interactions', app = null)` | Absolute URL of the interactions or events endpoint (per app when `app` is given) |
| `nowo_slack_kit_link_card()` | View data of the account card (`status`, `slack_user_id`, `claim_form`, `actions`), or `null` |
