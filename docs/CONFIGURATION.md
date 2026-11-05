# Configuration

All keys live under `nowo_slack_kit`. The tree is strict: unknown keys fail the build, numbers are range-checked and
security switches must be literal booleans (REQ-SF-006).

## Table of contents

- [Full reference with defaults](#full-reference-with-defaults)
- [api](#api)
- [apps and default_app](#apps-and-default_app)
- [credentials](#credentials)
- [ingress](#ingress)
- [interactions](#interactions)
  - [value_ttl](#value_ttl)
  - [previous_secrets (kernel.secret rotation)](#previous_secrets-kernelsecret-rotation)
  - [Legacy unsigned values: legacy_value_key, legacy_action_map, legacy_until](#legacy-unsigned-values-legacy_value_key-legacy_action_map-legacy_until)
  - [Link confirmations without c](#link-confirmations-without-c)
- [linking](#linking)
- [test_connection](#test_connection)
- [rate_limit](#rate_limit)
- [notifier](#notifier)
- [ui](#ui)
- [Messenger](#messenger)
- [Build-time refusals](#build-time-refusals)
- [Environment variables](#environment-variables)

## Full reference with defaults

```yaml
nowo_slack_kit:
    default_locale: en                # Slack-side texts when the account has no locale

    api:
        timeout: 5.0                  # idle timeout, 1..30 s (REQ-RUNTIME-001)
        max_duration: 10.0            # total duration cap, 1..30 s
        max_response_bytes: 1048576   # 64 KiB..8 MiB; larger responses are aborted

    default_app: default
    apps:
        default:
            app_id: null              # when set, api_app_id of every request must match
            signing_secret: null      # null = from the installation store; otherwise '%env(SLACK_SIGNING_SECRET)%'

    credentials:
        store: instance_settings      # instance_settings | memory | <service id implementing SlackInstallationStoreInterface>
        settings_section: slack

    ingress:
        enabled: true
        prefix: /slack
        interactions_path: null       # full override, e.g. /hooks/slack/interactions
        events_path: null
        events_enabled: false
        max_body_bytes: 1048576       # 16 KiB..4 MiB
        max_skew_seconds: 300         # 30..300
        replay_cache_pool: cache.app  # PSR-6 pool shared by all workers
        max_secret_candidates: 2      # 1..5
        signing_secret_resolver: null # service id implementing SigningSecretResolverInterface
        async: false                  # Events API callbacks through Messenger (requires symfony/messenger)
        transport: async

    interactions:
        value_ttl: P30D               # lifetime of nsk1 values (ISO-8601 duration)
        previous_secrets: []          # earlier kernel.secret values, %env()% only, verify-only
        legacy_value_key: null        # key of the action name in legacy unsigned JSON values
        legacy_action_map: {}         # legacy action name => action_id
        legacy_until: null            # ISO-8601 UTC instant (exclusive) or '%env(SLACK_LEGACY_UNTIL)%'

    linking:
        enabled: false
        identity_store: null          # service id implementing SlackIdentityStoreInterface (required when enabled)
        account_resolver: null        # service id implementing SlackAccountResolverInterface (required when enabled)
        claim_ttl: PT24H              # age after which an unverified claim yields
        confirmation_ttl: PT24H       # exp of the confirmation button
        email_lookup: false           # "Find me by e-mail"; also needs the installation flag and users:read.email
        return_route: null            # route the account actions redirect to (null: /)
        claim_per_account:        { limit: 10, interval: '1 hour' }
        email_lookup_per_account: { limit: 5,  interval: '1 hour' }
        confirmation_per_account: { limit: 3,  interval: '1 hour' }
        confirmation_per_target:  { limit: 3,  interval: '1 hour' }

    test_connection:
        enabled: true                 # only with the instance_settings store
        rate_limit: { limit: 5, interval: '1 minute' }

    rate_limit:
        max_retry_after: 300          # clamp of Slack's Retry-After, 1..3600 s

    notifier:
        enabled: false                # nowo-slack:// chat transport (requires symfony/notifier)

    ui:
        prefix: /slack-kit            # prefix of the settings action and the account link routes
```

## api

Every Slack call uses the constant base URL `https://slack.com/api/` (not configurable), `max_redirects: 0`, the
`timeout` / `max_duration` above and a response cap enforced while downloading. `max_duration` should be ≥ `timeout`.
Keep both below PHP's `max_execution_time` and the web server's write timeout (see
[DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md#timeout-hierarchy)).

## apps and default_app

An **app** is a Slack app the host operates. `default_app` must be a key of `apps` (`^[a-z0-9_]{1,32}$`). The
ingress verifies `POST {prefix}/interactions` with the default app and `POST {prefix}/interactions/{app}` with the
named app.

- `signing_secret`: `null` reads it from the installation store (InstanceSettings: `slack.signing_secret`). Any other
  value must be an `%env()%` placeholder; a literal fails the build so a secret never lands in VCS.
- `app_id`: when set, `api_app_id` of every interaction and event must equal it (403 otherwise).

## credentials

| `store` | Behaviour |
| --- | --- |
| `instance_settings` (default) | `InstanceSettingsInstallationStore`: one app, one installation, section `settings_section` (default `slack`) with `bot_token` (secret), `signing_secret` (secret), `team_id`, `allow_email_lookup`. Requires `nowo-tech/instance-settings-bundle`; enables the **Slack** settings section and *Test connection* |
| `memory` | `InMemoryInstallationStore` (tests, demos); reset on `kernel.reset` |
| any other string | The service id of your `SlackInstallationStoreInterface` (multi-tenant hosts) |

The store contract (encryption at rest, scoped reads, idempotent `markRevoked()`) is in
[SECURITY.md](SECURITY.md#installation-store-contract).

## ingress

Routes (loaded by the `nowo_slack_kit` route loader):

| Route | Path (defaults) |
| --- | --- |
| `nowo_slack_kit_interactions` / `nowo_slack_kit_interactions_app` | `POST /slack/interactions`, `POST /slack/interactions/{app}` |
| `nowo_slack_kit_events` / `nowo_slack_kit_events_app` | `POST /slack/events`, `POST /slack/events/{app}` (only with `events_enabled: true`) |

- `interactions_path` / `events_path` replace `{prefix}/interactions` / `{prefix}/events` entirely (keep a URL that is
  already pasted in a Slack app, e.g. `/hooks/slack/interactions`).
- `max_skew_seconds` is the timestamp window; signatures are remembered for twice that long in
  `replay_cache_pool`. Use a pool shared by every worker and server (Redis, PDO); `cache.app` on a filesystem or array
  adapter only protects one host or one process.
- `signing_secret_resolver`: a host service that proposes extra secrets for the default interactions route from a
  bounded, unverified peek of the payload (per-destination secrets). At most `max_secret_candidates` are tried.
- `async: true` sends verified `event_callback` envelopes to Messenger as `HandleSlackEvent` on `transport` and
  answers Slack at once; `false` dispatches them inline.

The ingress routes must not require authentication; add a firewall with `security: false` for their paths
([INSTALLATION.md](INSTALLATION.md#firewall)).

## interactions

### value_ttl

ISO-8601 duration (`P30D`, `PT12H`, …), parsed at build time (literal only). `exp` of every signed value is now +
`value_ttl`, except link confirmations, whose `exp` is now + `linking.confirmation_ttl`. A per-call TTL can be passed
to `ButtonValueCodec::sign()`.

### previous_secrets (kernel.secret rotation)

The button key is HKDF-SHA256 of `kernel.secret` (`info = nowo_slack_kit.button.v1`). After rotating `kernel.secret`,
list the old value so buttons already in Slack keep verifying until they expire:

```yaml
nowo_slack_kit:
    interactions:
        previous_secrets:
            - '%env(PREVIOUS_APP_SECRET)%'
```

Each entry must be an `%env()%` placeholder (a literal fails the build). Previous keys only verify; new values are
always signed with the current key. Remove the entry after `value_ttl`.

### Legacy unsigned values: legacy_value_key, legacy_action_map, legacy_until

For buttons posted by an in-app integration **before** it signed its values (plain JSON such as
`{"a":"resolve","p":"…"}`):

```yaml
nowo_slack_kit:
    interactions:
        legacy_value_key: a
        legacy_action_map:
            resolve: acme.issue.resolve
            link_confirm: nowo_slack_kit.link.confirm
        legacy_until: '%env(SLACK_LEGACY_UNTIL)%'   # e.g. 2026-12-31T00:00:00Z
```

- `legacy_until` is an ISO-8601 UTC instant, **exclusive**: from that instant on, legacy values get the same 403 and
  *This button is no longer valid* as a bad MAC. A literal is parsed at build time (an unparsable value fails the
  build); `%env(SLACK_LEGACY_UNTIL)%` is parsed at runtime and an **empty** value turns legacy off.
- `legacy_value_key` requires `legacy_until` and a non-empty `legacy_action_map` (legacy mode is always time-boxed).
- A value starting with `nsk` is never treated as legacy, even when its signature fails.
- Legacy relaxes only the value MAC and expiry: the request signature, window, replay and workspace checks, and the
  actor rules, still apply. Accepted legacy clicks are logged at `info` so you can watch usage fall to zero.

### Link confirmations without c

Signed confirmation values carry `{acc, c, uid}`; `c` (the claim time) binds the button to one claim. A signed
confirmation **without** `c` (the interim format `{acc, uid}` of symfony-beacon 1.122.1, which has no nonce store) is
accepted only while `legacy_until` is set and in the future, whatever `legacy_value_key` says. See the replay window
in [SECURITY.md](SECURITY.md#residual-risks).

## linking

Requires two host services (ids, not classes) — see [USAGE.md](USAGE.md#account-linking):

- `identity_store`: `SlackIdentityStoreInterface` (your accounts table stores the Slack id, claim time and
  verification time);
- `account_resolver`: `SlackAccountResolverInterface` (current user → account id).

The four limiters are fixed-window Symfony rate limiters stored in `cache.app` (`limiter` keys `acc:<account>` and
`slack:<team>:<user>` for the per-target confirmation limit). A refusal sends nothing, returns `RATE_LIMITED` and
dispatches `SlackConfirmationRateLimitedEvent`.

## test_connection

The *Test connection* action of the settings section (`POST {ui.prefix}/settings/test-connection`, CSRF,
InstanceSettings access checker) calls `auth.test`, flashes the workspace and bot user and fills `team_id` when it is
empty. Rate limited per user.

## rate_limit

`max_retry_after` clamps Slack's `Retry-After` (digits only; 30 s when absent) in `SlackRateLimitedException`. A 429
is remembered per (installation, method) until then and later calls fail fast with the remaining delay.

## notifier

With `symfony/notifier` installed and `notifier.enabled: true`, the chat transport factory for
`nowo-slack://default?app=<app>&team=<T…>` is registered ([USAGE.md](USAGE.md#notifier-transport)). Enabling it
without `symfony/notifier` fails the build.

## ui

`prefix` of the bundle's UI routes: `{prefix}/settings/test-connection` and `{prefix}/account/slack/{claim,find,confirm,unlink}`.

## Messenger

When `symfony/messenger` is installed the bundle registers the middleware service
`nowo_slack_kit.messenger.rate_limit_retry`. Add it to a bus to retry handlers that fail with a Slack rate limit after
`Retry-After` (through the transport's retry strategy):

```yaml
framework:
    messenger:
        buses:
            messenger.bus.default:
                middleware:
                    - nowo_slack_kit.messenger.rate_limit_retry
```

## Build-time refusals

The container build fails when:

- a signing secret or a `previous_secrets` entry is a literal instead of an `%env()%` placeholder;
- a security switch (`ingress.enabled`, `ingress.events_enabled`, `ingress.async`, `linking.enabled`,
  `linking.email_lookup`, `test_connection.enabled`, `notifier.enabled`) is not a literal boolean;
- `default_app` is not a key of `apps`;
- `linking.enabled` without `identity_store` and `account_resolver`;
- `ingress.async` without `symfony/messenger`, `notifier.enabled` without `symfony/notifier`,
  `credentials.store: instance_settings` without InstanceSettingsBundle;
- `legacy_value_key` without `legacy_until` and `legacy_action_map`, or a literal `legacy_until` that is not a date;
- `value_ttl`, `claim_ttl` or `confirmation_ttl` is not an ISO-8601 duration;
- two handlers declare the same `action_id`, a handler's `action_id` is malformed, or a `legacy_action_map` target
  has no handler.

## Environment variables

None is required by the bundle. Common placeholders (values are examples, never real secrets):

```dotenv
# .env.local (never committed)
SLACK_SIGNING_SECRET=testsigningsecretnotreal00000000   # 16-128 letters or digits
SLACK_LEGACY_UNTIL=
PREVIOUS_APP_SECRET=
```

With the default `instance_settings` store the bot token (`xoxb-PLACEHOLDER` in examples) and the signing secret are
entered in the admin section and stored encrypted; no environment variable is needed.
