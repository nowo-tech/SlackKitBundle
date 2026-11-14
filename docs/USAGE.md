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
- [Several workspaces (1.3)](#several-workspaces-13)
  - [Standard Symfony host](#standard-symfony-host)
  - [DDD / hexagonal host](#ddd--hexagonal-host)
  - ["Add to Slack"](#add-to-slack)
  - [Own apps](#own-apps)
  - [Uninstall and token revocation](#uninstall-and-token-revocation)
- [Updating the clicked message (1.3)](#updating-the-clicked-message-13)
- [Notifier transport](#notifier-transport)
- [Events reference](#events-reference)
- [Twig functions](#twig-functions)
- [Using it from a DDD / hexagonal application](#using-it-from-a-ddd--hexagonal-application)

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
  fallback text is **mrkdwn-escaped** (`&`, `<`, `>`) when sent and then capped at 4 000 characters **after**
  escaping, never leaving a cut entity behind, so user data such as an issue
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

Every button that triggers a handler should carry a value signed with `ButtonValueCodecInterface` (since 1.2; the
default implementation and public alias target is `ButtonValueCodec`):

```php
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Interaction\ButtonValueCodecInterface; // inject it as $codec

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
                // Host-verified only (auth.test with the destination's own bot token, OAuth, or an operator):
                // never a value typed by whoever pasted the destination secret. Null when unknown.
                SlackTeamId::tryFrom($destination->verifiedSlackTeamId()),
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
operators alone. The bound team must come from a **host-verified** source (`auth.test` with the destination's own bot
token, OAuth, or an operator) and never from a value the secret holder typed or chose; otherwise that person picks the
workspace and can act as its members. The opt-in only restores the actor: legacy unsigned values and link
confirmations stay refused on resolver-verified clicks.

## Events API

Enable `ingress.events_enabled`, add the Events URL (`nowo_slack_kit_ingress_url('events')`) and the bot events
`app_uninstalled` and `tokens_revoked` to the Slack app. Then:

- `url_verification` is answered only after the signature and replay checks;
- `app_uninstalled` / `tokens_revoked` (bot tokens) call `markRevoked()` on the store and dispatch
  `SlackInstallationRevokedEvent`;
- every `event_callback` is dispatched as `SlackEventReceivedEvent` (installation key, `eventId`, `eventTime`, `type`,
  decoded `event`), inline or through Messenger with `ingress.async: true`;
- Slack retries of the same `event_id` within an hour answer 200 and are dropped; when handling fails (inline
  listener or Messenger dispatch) the claim is released and the endpoint answers 500, so Slack's retry is handled.
  Delivery is therefore **at-least-once**: partial side effects of a failed run repeat on the retry, and a slow
  handler that fails after Slack's retry was already answered 200 loses the event (Slack does not retry again). Keep
  listeners idempotent and prefer `ingress.async: true`;
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

`AccountLinkerInterface` (since 1.2; public alias of `AccountLinker`, registered when `linking.enabled`) for hosts
with their own UI. The bundle's controller, Twig extension and *Confirm* handler use the alias, so a decorator
registered under it applies to them too:

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

## Several workspaces (1.3)

A host with one Slack app keeps working unchanged: its installation is the **default installation**. To serve several
workspaces, every installation is named by `SlackInstallationKey(app, team, enterprise?)` and stored by your
`SlackInstallationStoreInterface`; an owner scope of your own (`SlackOwnerScope`: a type such as `organization` and an
opaque id) gets the use of an installation through a **binding**, so two organizations in one workspace share one
token and one revocation.

Isolation rules the bundle enforces (spec 237): a store answer for another workspace is never used (the API client
checks `SlackInstallationKey::admits()`), each click and event resolves its own workspace's installation, button
values stay bound to their workspace, the actor comes only from a link of the click's workspace, and replies use the
click's installation. Handlers still re-check the actor's access to the item.

### Standard Symfony host

Map two entities with attributes (or XML) and wrap them in the ports; encrypt the secrets with your encryption
bundle:

```php
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['app', 'team_id'])]
class SlackWorkspace
{
    #[ORM\Column(length: 32)] public string $app;
    #[ORM\Column(length: 32, options: ['collation' => 'utf8mb4_bin'])] public string $teamId;
    #[ORM\Column(length: 32, nullable: true)] public ?string $enterpriseId = null;
    #[ORM\Column(type: 'text')] public string $botTokenEncrypted;          // never in clear
    #[ORM\Column(type: 'json')] public array $scopes = [];
    #[ORM\Column(nullable: true)] public ?\DateTimeImmutable $revokedAt = null;
    // appId, teamName, botUserId, installerUserId, installedAt, ownSigningSecretEncrypted…
}

final readonly class DoctrineSlackStore implements SlackInstallationStoreInterface
{
    public function find(SlackInstallationKey $key): ?SlackInstallation
    {
        $row = $key->team ? $this->repo->findOneBy(['app' => $key->app, 'teamId' => $key->team->value]) : null;

        return $row ? new SlackInstallation(
            new SlackInstallationKey($row->app, SlackTeamId::fromString($row->teamId), SlackEnterpriseId::tryFrom($row->enterpriseId)),
            SlackSecret::botToken($this->crypto->decrypt($row->botTokenEncrypted)),
            SlackTeamId::fromString($row->teamId),
            in_array('users:read.email', $row->scopes, true),
            revokedAt: $row->revokedAt,
            scopes: $row->scopes,
        ) : null;
    }
    // findSigningSecret(), save() (upsert by app + team: a re-install updates the token), markRevoked() (idempotent)
}
```

```yaml
nowo_slack_kit:
    credentials: { store: App\Slack\DoctrineSlackStore }
    installations:
        binding_store: App\Slack\DoctrineSlackBindings
```

Send with the installation of the destination: `$slack->postMessage(new SlackInstallationKey('default', $team),
$channel, $message)`; list what a scope may use with `$bindings->bindingsFor(new SlackOwnerScope('organization',
(string) $org->getId()))`.

### DDD / hexagonal host

Same ports, mapped in infrastructure (XML mapping, ids only): the bundle never sees your entities. Keep
`SlackInstallationKey` / `SlackOwnerScope` at the adapter boundary and translate them to your own value objects.
Validate the adapters with the shipped contract test cases:

```php
final class SlackWorkspaceRepositoryContractTest extends SlackMultiInstallationStoreContractTestCase
{
    protected function createEmptyStore(): SlackInstallationStoreInterface { return $this->freshRepository(); }
}

final class SlackBindingRepositoryContractTest extends SlackInstallationBindingStoreContractTestCase
{
    protected function createEmptyStore(): SlackInstallationBindingStoreInterface { return $this->freshBindings(); }
}
```

### "Add to Slack"

```yaml
nowo_slack_kit:
    apps:
        default:
            app_id: A0123456789                       # required: the grant must be for this app
            client_id: '%env(SLACK_CLIENT_ID)%'
            client_secret: '%env(SLACK_CLIENT_SECRET)%' # %env()% only
    installations:
        binding_store: App\Slack\DoctrineSlackBindings
        oauth:
            enabled: true
            authorizer: App\Slack\InstallAuthorizer  # SlackInstallAuthorizerInterface
            account_resolver: null                    # default: linking.account_resolver
            scopes: [chat:write, channels:read, groups:read, users:read]
            optional_scopes: [chat:write.public]      # add users:read.email only when e-mail lookup is on
            return_route: app_integrations
```

```php
final readonly class InstallAuthorizer implements SlackInstallAuthorizerInterface
{
    public function mayInstall(SlackInstallAttempt $attempt): bool
    {
        return $attempt->scope->type === 'organization' && $this->orgs->isAdmin($attempt->accountId, $attempt->scope->id);
    }
}
```

```twig
{% set add = nowo_slack_kit_install_action('organization', organization.id) %}
{% if add %}{{ include('@NowoUiKitBundle/partials/_action.html.twig', {action: add}) }}{% endif %}
```

Register `nowo_slack_kit_install_redirect_url()` (`<ui.prefix>/install/callback`) as the Slack app's redirect URL and
keep **token rotation off** (rotating grants are refused in 1.3). The flow: POST with CSRF → authoriser → single-use
state in the session (10 minutes, bound to the account) → Slack → callback: same session and account, authoriser
again, `oauth.v2.access`, grant checks (bot token, `app_id`, workspace install, required scopes) → `save()` (a
re-install updates the token) → `bind()` → `SlackInstallationCompletedEvent` → flash and redirect. Org-wide
Enterprise Grid installs are refused, and so is a workspace connected through its own app. Each refusal shows one
translated message (`install.refused.<reason>`: `app_mismatch`, `enterprise_install`, `token_rotation_unsupported`,
`missing_scope` with the missing scopes, `invalid_grant`, `not_allowed`, `own_app_installed`); the reason code goes to
the log only. Slack's redirect is a top-level GET from slack.com, so the session cookie must
be sent on it (`SameSite=Lax`, Symfony's default; `Strict` makes every callback fail as "invalid or expired").

### Own apps

A workspace whose policy forbids third-party apps can use its own Slack app: store its installation with the bot
token, its own `signingSecret` **and** its Slack `appId` (required: an own-app installation without it is never
tried; read it with `bots.info` for the `bot_id` that `auth.test` returns). A click or event that the configured app's
secret does not verify is tried with the own-app secret of the installation named by the request's workspace id, and
then must carry that installation's `api_app_id`. Handlers see `$interaction->verifiedByOwnApp` (and listeners
`SlackInteractionReceivedEvent::$verifiedByOwnApp`).

**Actors are off for own-app clicks by default.** The own-app signing secret is not held by Slack alone: whoever
pasted it (a tenant admin) can sign a click with **any member id** of that workspace, including members linked to
other tenants' accounts. So, like resolver-verified clicks, own-app clicks get no actor: handlers that need one refuse
them, anonymous-safe handlers (`requiresActor: false`) still run, Web API answers work. Turn
`installations.own_app_actors: true` on only together with an `installations.actor_filter`
(`SlackActorFilterInterface`): it gets a `SlackActorCandidate` (account id, team, member, installation key,
`verifiedByOwnApp`) for every verified, active link and can limit own-app clicks to the accounts of the owner scopes
bound to that installation:

```php
final readonly class BoundScopesActorFilter implements SlackActorFilterInterface
{
    public function mayAct(SlackActorCandidate $candidate): bool
    {
        if (!$candidate->verifiedByOwnApp || $candidate->installation === null) {
            return true;
        }
        foreach ($this->bindings->bindingsOf($candidate->installation) as $binding) {
            if ($this->members->belongsTo($candidate->accountId, $binding->scope)) {
                return true;
            }
        }

        return false;
    }
}
```

Bind an own-app installation to one scope only, never for the instance workspace. Own-app and "Add to Slack"
installations of one workspace share its key (app + workspace): "Add to Slack" refuses a workspace that has an active
own-app installation (`own_app_installed`), and your own-app form must not overwrite an installation bound to other
scopes either.

### Uninstall and token revocation

With `ingress.events_enabled`, `app_uninstalled` and `tokens_revoked` (naming the installation's bot) call
`markRevoked()` once and dispatch `SlackInstallationRevokedEvent` with the installation's `bindings`; mark their
destinations broken and notify their admins. Later requests from that workspace are refused (403) until it is
installed again.

## Updating the clicked message (1.3)

> **Removing a spent button is cosmetic, not a control.** The replacement is rebuilt from the clicker's copy of the
> message and sent best effort: two close clicks on the same copy each rebuild from the original, and the last update
> wins (a spent button can come back); a failed `chat.update` leaves it. The button's signed value stays valid until it
> expires. Handlers must always re-check the item's own state (already assigned, already resolved by X: answer
> ephemerally and change nothing). When concurrent clicks matter, rebuild the message from your own state
> (`new SlackMessage(...)` + `replaceOriginal()`).

Handlers get the clicked message (`$interaction->message`, from the verified payload) and its `response_url`
(`$interaction->responseUrl`, a redacted `SlackSecret`):

```php
#[AsSlackActionHandler('acme.issue.assign')]
final readonly class AssignFromSlack implements SlackActionHandlerInterface
{
    public function handle(SlackInteraction $interaction): SlackInteractionReply
    {
        $issue = $this->assign->toActor($interaction->string('issue'), $interaction->actor);

        return SlackInteractionReply::done()
            // chat.update with the click's installation: drop the spent button, add "Assigned to Ana".
            ->withSpentButtons($interaction, ['acme.issue.assign'], 'Assigned to ' . $issue->assigneeName());
    }
}
```

- `MessageUpdate::withoutSpentButtons($message, $spentActionIds, $context)` builds the same `SlackMessage` yourself
  (empty list: every interactive element goes; an emptied `actions` block is dropped; the context line is escaped).
- `->viaResponseUrl()` sends the ephemeral answer, replacement or deletion to the click's `response_url` instead of
  the Web API (no bot token; works for clicks verified by a host resolver, which never get Web API answers). Without a
  valid `response_url` the Web API is used. `ResponseUrlClientInterface` is public for your own calls.

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
| `SlackInstallationRevokedEvent` | `app_uninstalled` / `tokens_revoked`, once per installation, with its `bindings` (1.3) |
| `SlackInstallationCompletedEvent` | "Add to Slack" saved and bound an installation (installation, scope, account id, re-install flag) |
| `SlackInstallationUnhealthyEvent` | Repeated auth errors (at most every 10 min per installation) |
| `SlackEventsRateLimitedEvent` | `app_rate_limited` callback |

No event carries a token, a signature or a raw body.

## Twig functions

| Function | Returns |
| --- | --- |
| `nowo_slack_kit_ingress_url(kind = 'interactions', app = null)` | Absolute URL of the interactions or events endpoint (per app when `app` is given) |
| `nowo_slack_kit_link_card()` | View data of the account card (`status`, `slack_user_id`, `claim_form`, `actions`), or `null` |
| `nowo_slack_kit_install_action(scope_type, scope_id, app = null)` | The "Add to Slack" UiKit `Action` (POST, CSRF) for a scope, or `null` (flow off, nobody signed in, authoriser refuses) |
| `nowo_slack_kit_install_redirect_url()` | Absolute OAuth redirect URL to register in the Slack app, or `null` when the flow is off |

## Using it from a DDD / hexagonal application

SlackKitBundle is an **infrastructure adapter**: outbound it talks to the Slack Web API, inbound it verifies
Slack requests and hands them to your handlers. It has no Doctrine mapping and no entities; everything it needs to
store goes through interfaces you implement, and your accounts are only ever seen as opaque string ids.

**Keep the domain free of the bundle**

- Define your own port in the domain (for example `ChatNotifier`) and implement it in infrastructure with
  `SlackApiClientInterface` (`postMessage()`, `postEphemeral()`, `openDirectMessage()`…). Domain code never sees
  `SlackMessage` or `Blocks`.
- Implement the persistence ports in your infrastructure layer, mapped to your own tables (several workspaces:
  [Several workspaces](#ddd--hexagonal-host)):
  - `SlackIdentityStoreInterface` (`linking.identity_store`) — account links (`SlackLink`) plus account status,
    e-mail and locale lookups by account id;
  - `SlackAccountResolverInterface` (`linking.account_resolver`) — the current account id;
  - `SlackInstallationStoreInterface` (`credentials.store: <service id>`) if you do not use InstanceSettings;
    validate it with `SlackInstallationStoreContractTestCase`.
- Application services that sign button values or drive account linking depend on `ButtonValueCodecInterface` and
  `AccountLinkerInterface` (since 1.2), so they can be unit-tested with doubles.
- Treat `#[AsSlackActionHandler]` handlers as driving adapters (like controllers): read `SlackInteraction`
  (`actionId`, `data`, `actor?->accountId`, `interactionId` as idempotency key), call an application command, and
  return a `SlackInteractionReply`.
- React to the bundle's Symfony events in infrastructure listeners and translate them to your own domain events:
  `SlackAccountVerifiedEvent`, `SlackAccountUnlinkedEvent`, `SlackLinkReleasedEvent`, `SlackInstallationRevokedEvent`,
  `SlackInstallationUnhealthyEvent`, `SlackEventReceivedEvent`, `SlackActionHandledEvent`.

**Adapter sketch**

```php
final readonly class SlackApprovalNotifier implements ApprovalNotifier // your domain port
{
    public function __construct(
        private SlackApiClientInterface $slack,
        private ButtonValueCodecInterface $codec,
        private SlackInstallationKey $installation,
    ) {}

    public function askApproval(ApprovalRequest $request, string $slackUserId): void
    {
        $channel = $this->slack->openDirectMessage($this->installation, SlackUserId::fromString($slackUserId));
        $value = $this->codec->sign('acme.approval.approve', ['id' => $request->id()]);
        $this->slack->postMessage($this->installation, $channel, /* SlackMessage built with Blocks and $value */);
    }
}

#[AsSlackActionHandler('acme.approval.approve')]
final readonly class ApproveFromSlack implements SlackActionHandlerInterface
{
    public function __construct(private ApproveRequestHandler $approve) {}

    public function handle(SlackInteraction $interaction): SlackInteractionReply
    {
        ($this->approve)(new ApproveRequest((string) $interaction->string('id'), $interaction->actor?->accountId));

        return SlackInteractionReply::done();
    }
}
```

**Known limitations**

- `IncomingWebhookClient` is a final class without an interface: keep it inside your infrastructure adapters.
  (`ButtonValueCodecInterface` and `AccountLinkerInterface` cover the codec and the linker since 1.2.)
- `SlackEventReceivedEvent::$event` and `SlackInteractionReceivedEvent::$payload` are raw Slack arrays; parse them in
  the listener, not in the domain.
- The bundle requires FormKit and UiKit even if you only use the API client and the ingress.
