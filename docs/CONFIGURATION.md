# Configuration

All keys live under `nowo_slack_kit`. The tree is strict: unknown keys fail the build, numbers are range-checked and
security switches must be literal booleans (REQ-SF-006).

## Table of contents

- [Full reference with defaults](#full-reference-with-defaults)
- [api](#api)
- [apps and default_app](#apps-and-default_app)
- [credentials](#credentials)
- [ingress](#ingress)
  - [Lock DSN with a fallback](#lock-dsn-with-a-fallback)
  - [Replay lock store in tests](#replay-lock-store-in-tests)
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
        replay_lock_factory: lock.factory # symfony/lock LockFactory; Redis or DBAL store in production
        max_secret_candidates: 2      # 1..5
        signing_secret_resolver: null # service id implementing SigningSecretResolverInterface
        resolver_actors: false        # resolve actors for resolver-verified clicks bound to a workspace (opt-in)
        async: false                  # Events API callbacks through Messenger (requires symfony/messenger)
        transport: async

    interactions:
        value_ttl: P30D               # lifetime of nsk1 values (ISO-8601 duration)
        previous_secrets: []          # earlier kernel.secret values, %env()% only, verify-only
        legacy_value_key: null        # key of the action name in legacy unsigned JSON values
        legacy_action_map: {}         # legacy action name => action_id
        legacy_until: null            # strict UTC instant 2026-12-05T00:00:00Z (exclusive) or '%env(SLACK_LEGACY_UNTIL)%'

    linking:
        enabled: false
        identity_store: null          # service id implementing SlackIdentityStoreInterface (required when enabled)
        account_resolver: null        # service id implementing SlackAccountResolverInterface (required when enabled)
        claim_ttl: PT24H              # age after which an unverified claim yields
        confirmation_ttl: PT24H       # exp of the confirmation button
        email_lookup: false           # "Find me by e-mail"; also needs the installation flag and users:read.email
        return_route: null            # route the account actions redirect to (null: /)
        rate_limiter_cache_pool: cache.app # cache pool service id holding the four limiters' counters
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
- `max_skew_seconds` is the timestamp window. A verified signature is claimed **atomically** with a `symfony/lock`
  key (`createLock(<sha256 of the signature>, 2 × max_skew_seconds, autoRelease: false)->acquire(false)`) that is
  never released, so a second copy is refused even when both arrive at once. The key is the signature alone, so apps
  that share one signing secret cannot replay a request once per `{app}` route. Events API `event_id`s are claimed
  the same way for one hour and released when handling fails, so Slack's retry of a failed event goes through (the
  failed delivery answers 500 `Event handling failed`).
- `replay_lock_factory` is the `LockFactory` service id (default `lock.factory`, FrameworkBundle's default lock
  resource, whose own default store is `semaphore` when `ext-sysvsem` is loaded and `flock` otherwise). Prefer a dedicated resource shared by every worker and server, so the app's own locks are untouched:

  ```yaml
  framework:
      lock:
          resources:
              default: ['%env(LOCK_DSN)%']              # the app's own locks, unchanged
              slack_kit: ['%env(SLACK_KIT_LOCK_DSN)%']  # redis://… or a DBAL / PDO DSN (no +advisory)
  nowo_slack_kit:
      ingress:
          replay_lock_factory: lock.slack_kit.factory
  ```

  Outside debug and test (`kernel.debug: false` and an environment other than `test`) only an **allowlist** of
  expiring shared stores builds (`ReplayGuard::EXPIRING_STORES`: Redis, Memcached, PDO, Doctrine DBAL, MongoDB, DynamoDB, or a `StoreFactory` connection
  service of Redis / RedisArray / RedisCluster / Relay / Predis / Memcached / PDO / DBAL `Connection` / MongoDB
  `Collection` / `DynamoDbClient`). `CombinedStore` members are checked one by one (all must pass; an empty one is
  refused). Refused: local stores (`flock`, `semaphore`, `in-memory`, `null`), session-scoped ones whose locks ignore
  the TTL (`pgsql+advisory:`, `pgsql+advisory://`, `postgres(ql)+advisory://` advisory locks, `zookeeper://`
  ephemeral nodes) and any unknown DSN or store class. Plain `pgsql:` / `postgresql://` are fine. A store the build
  cannot see (an `%env()%` DSN, a factory-built service) is checked at runtime with `ReplayGuard::isSharedStore()` /
  `isSharedDsn()`; a refused one makes every ingress request fail (500). A custom factory whose store is not visible
  at all is trusted as configured. `ingress.replay_cache_pool` no longer exists.
- Events API delivery is **at-least-once**: a failed delivery releases its `event_id`, so Slack's retry runs the
  handler again (partial side effects repeat), and a slow handler that fails after Slack's retry was already answered
  200 loses the event (Slack does not retry again). Keep event handlers idempotent and prefer `async: true`.
- `signing_secret_resolver`: a host service that proposes extra secrets for the default interactions route from a
  bounded, unverified peek of the payload (per-destination secrets). At most `max_secret_candidates` are tried.
  Whoever holds such a secret can sign any `team.id` and `user.id`, so a click verified with one gets **no actor**
  (handlers with `requiresActor` answer 403), legacy unsigned values are refused on it, and the built-in link
  confirmation refuses it.
- `resolver_actors: true` (opt-in) resolves an actor for a resolver-verified click only when the resolver returned
  the secret as `Ingress\ResolvedSigningSecret($secret, $team)` and the click's `team.id` equals that bound workspace.
  It trusts **every holder of every destination secret** to sign any member id of that workspace; enable it only when
  those secrets are known to your operators alone. The bound team must come from a **host-verified** source
  (`auth.test` with the destination's own bot token, OAuth, or an operator), never from a value typed or chosen by
  whoever holds or pasted the destination secret, or the actor spoofing of FS-M1 returns under the opt-in. The opt-in
  only restores the actor: legacy unsigned values and link confirmations stay refused on resolver-verified clicks.
- `async: true` sends verified `event_callback` envelopes to Messenger as `HandleSlackEvent` on `transport` and
  answers Slack at once; `false` dispatches them inline.

The ingress routes must not require authentication; add a firewall with `security: false` for their paths
([INSTALLATION.md](INSTALLATION.md#firewall)).

### Lock DSN with a fallback

To reuse an existing variable (for example `REDIS_URL`) when `SLACK_KIT_LOCK_DSN` is not set, do **not** write
`%env(default:REDIS_URL:SLACK_KIT_LOCK_DSN)%`. The first argument of Symfony's `default:` env var processor is the
name of a **container parameter**, not of an environment variable
([EnvVarProcessor](https://symfony.com/doc/current/configuration/env_var_processors.html): `env(default:fallback_param:BAR)`
returns the parameter `fallback_param` when `BAR` is missing or empty). With no parameter called `REDIS_URL`, Symfony
throws `Invalid env fallback in "default:REDIS_URL:SLACK_KIT_LOCK_DSN": parameter "REDIS_URL" not found.` when the
lock store is built, so every ingress request fails. Working patterns:

```yaml
# 1. A fallback parameter that reads the other variable
parameters:
    slack_kit_lock_dsn_fallback: '%env(REDIS_URL)%'

framework:
    lock:
        resources:
            default: ['%env(LOCK_DSN)%']
            slack_kit: ['%env(default:slack_kit_lock_dsn_fallback:SLACK_KIT_LOCK_DSN)%']
```

```dotenv
# 2. Or no processor at all: Dotenv expands variables (the resource stays '%env(SLACK_KIT_LOCK_DSN)%')
SLACK_KIT_LOCK_DSN=${REDIS_URL}
```

Or point the resource straight at the shared variable (`slack_kit: ['%env(REDIS_URL)%']`). Whatever the pattern,
the DSN comes from the environment, so the allowlist checks the resulting store at runtime (a refused store makes
every ingress request fail with 500).

### Replay lock store in tests

In the `test` environment (and whenever `kernel.debug` is true) the allowlist is **not** enforced, so a test suite
needs no Redis. That does not make every local store usable for replay tests:

- **`flock`** (and `semaphore`) remember nothing across claims. `FlockStore` keeps the lock in a file handle held
  by the lock's key; `ReplayGuard::claimSignature()` keeps no reference to the lock, so the handle is closed and the
  OS releases the lock as soon as the claim returns (an Events API claim lives until the request ends). A replayed
  request is accepted again, so a test that expects the second copy to be refused fails. `semaphore` behaves the
  same: the System V semaphore lives in the lock's key and is released when that key is freed (at the latest when
  the request ends).
- **`in-memory`** keeps every claim in the store object until its TTL, independent of the lock object, so the second
  copy of a signature or `event_id` is refused for as long as the container lives. Use it for the SlackKit resource
  in the test environment:

  ```yaml
  # config/packages/test/framework.yaml (or when@test:)
  framework:
      lock:
          resources:
              slack_kit: ['in-memory']   # replaces the production DSN; resources are not deep-merged
  ```

- `KernelBrowser` reboots the kernel between requests by default, which builds a new container and so a new, empty
  `InMemoryStore`. In a test that sends the same signed request twice, call `$client->disableReboot()` before the
  first request (or point the resource at a real shared store, such as a Redis service or a file-backed SQLite DBAL
  connection, when the claims must survive reboots).

Never ship `in-memory` outside tests: each PHP worker or FPM process has its own memory (it is refused outside debug
and test anyway).

## interactions

### value_ttl

ISO-8601 duration (`P30D`, `PT12H`, …), parsed at build time (literal only). `exp` of every signed value is now +
`value_ttl`, except link confirmations, whose `exp` is now + `linking.confirmation_ttl`. A per-call TTL can be passed
to `ButtonValueCodec::sign()`.

### previous_secrets (kernel.secret rotation)

The button key is HKDF-SHA256 of `kernel.secret` (`info = nowo_slack_kit.button.v1`). Anyone who knows
`kernel.secret` can forge every `nsk1` value: use a random value of at least 32 bytes. A shorter value or a known
placeholder (`change_this…`, `demo-secret`, `ThisTokenIsNotSoSecretChangeIt`…) logs a warning with
`reason: weak_kernel_secret` when the key is derived. After rotating `kernel.secret`,
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
        legacy_until: '%env(SLACK_LEGACY_UNTIL)%'   # e.g. 2026-12-31T00:00:00Z
```

- `legacy_until` is a strict UTC instant, **exclusive**: `YYYY-MM-DDTHH:MM:SS` followed by `Z` or `+00:00`
  (`^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|\+00:00)$`, parsed by `LegacyValuePolicy::parseUntil()`). From that
  instant on, legacy values get the same 403 and *This button is no longer valid* as a bad MAC. A literal in any other
  form (relative strings such as `+30 days` or `tomorrow`, which would roll forward on every boot, a date without a
  zone, another offset) fails the build; an `%env(SLACK_LEGACY_UNTIL)%` value in any other form (empty included)
  turns legacy mode **off** at runtime.
- A legacy action cannot map to `nowo_slack_kit.link.confirm` (the build fails): confirmations are bound to the claim
  time and unsigned confirmations are never accepted.
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

The four limiters are fixed-window Symfony rate limiters stored in the `rate_limiter_cache_pool` cache pool (default
`cache.app`; `@` prefix accepted). Point it at `cache.rate_limiter` (FrameworkBundle's pool), a Redis pool, or, in
tests, a pool that survives between requests when `cache.app` is an `ArrayAdapter`. The pool must keep the counters:
a `NullAdapter` pool fails the container compile in every environment, and an `ArrayAdapter` pool (one request, or
one worker) fails it unless `kernel.debug` is true or the environment is `test` (`LinkingLimiterPoolPass`, audit
IL-L2). The *Test connection* limiter keeps `cache.app` whatever this option says. `limiter` keys `acc:<account>` and
`slack:<team>:<user>` for the per-target confirmation limit). A refusal sends nothing, returns `RATE_LIMITED` and
dispatches `SlackConfirmationRateLimitedEvent`.

## test_connection

The *Test connection* action of the settings section (`POST {ui.prefix}/settings/test-connection`, CSRF,
InstanceSettings access checker) calls `auth.test`, flashes the workspace and bot user and fills `team_id` when it is
empty. Rate limited per user.

## rate_limit

`max_retry_after` clamps Slack's `Retry-After` (digits only; 30 s when absent) in `SlackRateLimitedException`. A 429
is remembered per (installation, method) until then and later calls fail fast with the remaining delay.

`max_attempts` (default 3, 1..20) bounds the Messenger retry middleware: it counts deliveries with Messenger's
`RedeliveryStamp` and, at the limit, rethrows the original failure so the transport's own retry limit and failure
transport apply.

## notifier

With `symfony/notifier` installed and `notifier.enabled: true`, the chat transport factory for
`nowo-slack://default?app=<app>&team=<T…>` is registered ([USAGE.md](USAGE.md#notifier-transport)). Enabling it
without `symfony/notifier` fails the build.

## ui

`prefix` of the bundle's UI routes: `{prefix}/settings/test-connection` and `{prefix}/account/slack/{claim,find,confirm,unlink}`.

## Messenger

When `symfony/messenger` is installed the bundle registers the middleware service
`nowo_slack_kit.messenger.rate_limit_retry`. Add it to a bus to retry handlers that fail with a Slack rate limit after
`Retry-After` (through the transport's retry strategy), at most `rate_limit.max_attempts` deliveries:

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
- `legacy_value_key` without `legacy_until` and `legacy_action_map`, a literal `legacy_until` that is not a strict
  UTC instant (`2026-12-05T00:00:00Z`), or a `legacy_action_map` target `nowo_slack_kit.link.confirm`;
- the replay lock factory (`ingress.replay_lock_factory`) does not exist, or, while `kernel.debug` is false outside
  the `test` environment, its store is not an expiring shared store (Redis, Memcached, PDO, Doctrine DBAL, MongoDB, DynamoDB): the message reads "the
  replay lock store is not an expiring shared store (…) … Point nowo_slack_kit.ingress.replay_lock_factory at a
  dedicated framework.lock resource.";
- `value_ttl`, `claim_ttl` or `confirmation_ttl` is not an ISO-8601 duration;
- two handlers declare the same `action_id`, a handler's `action_id` is malformed, or a `legacy_action_map` target
  has no handler.

## Environment variables

None is required by the bundle. Common placeholders (values are examples, never real secrets):

```dotenv
# .env.local (never committed)
SLACK_SIGNING_SECRET=testsigningsecretnotreal00000000   # 16-128 letters or digits
SLACK_LEGACY_UNTIL=                                     # empty = legacy off; else e.g. 2026-12-05T00:00:00Z
PREVIOUS_APP_SECRET=
SLACK_KIT_LOCK_DSN=redis://redis:6379                   # framework.lock.resources.slack_kit: replay memory
# SLACK_KIT_LOCK_DSN=${REDIS_URL}                       # reuse another variable (not %env(default:REDIS_URL:…)%)
```

For a fallback to another variable see [Lock DSN with a fallback](#lock-dsn-with-a-fallback); for the test
environment see [Replay lock store in tests](#replay-lock-store-in-tests).

With the default `instance_settings` store the bot token (`xoxb-PLACEHOLDER` in examples) and the signing secret are
entered in the admin section and stored encrypted; no environment variable is needed.
