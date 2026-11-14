# Upgrading

Instructions for moving between versions of `nowo-tech/slack-kit-bundle`. Breaking changes and required host actions
are listed per target version.

## Table of contents

- [Unreleased](#unreleased)
- [To 1.3.0](#to-130)
  - [Several workspaces](#several-workspaces)
  - [Message updates](#message-updates)
- [To 1.2.1](#to-121)
- [To 1.2.0](#to-120)
- [To 1.2.0](#to-120-1)
- [To 1.1.0](#to-110)
- [Installing 1.0.0](#installing-100)
  - [From scratch](#from-scratch)
  - [Adopting it from an in-app Slack integration](#adopting-it-from-an-in-app-slack-integration)
  - [Production lock store](#production-lock-store)
  - [Values already posted to Slack](#values-already-posted-to-slack)

## Unreleased

## To 1.3.0

From **1.2.x**:

```bash
composer update nowo-tech/slack-kit-bundle
```

Target **1.3.0** (minor). Nothing to change for a single-workspace host: the `instance_settings` store keeps serving
its one installation as before (it becomes "the default installation"), no data migration, no new table, and every
new option is off by default. The bundle still ships no Doctrine mapping or migrations: multi-workspace data lives
behind your own stores.

Behaviour changes to check:

- `SlackInstallationRevokedEvent` is dispatched **once** per installation: Slack sends `app_uninstalled` and
  `tokens_revoked` together on uninstall, and only the first one now dispatches it. `tokens_revoked` without bot
  tokens (or naming another bot than the installation's `botUserId`) no longer revokes.
- `new SlackInstallation(...)` with a token that is not a bot token now throws `InvalidArgumentException`.
- When an installation records its own Slack app id (`appId`), requests for it must carry that `api_app_id`; the
  configured `apps.<name>.app_id` applies to installations without one.
- Rate limits remembered by the API client are now keyed by the installation's workspace, so a 429 for one workspace
  never blocks another (a key without a workspace still shares the app bucket).
- For an Enterprise Grid workspace, `SlackInteraction::$installation->key()` (and the event keys) now include the
  enterprise: `default:E…:T…` instead of `default:T…`. Compare installations with `sameWorkspace()` or
  `workspaceKey()`, not with the `key()` string.
- `new SlackInstallationKey($app, null, $enterprise)` (an enterprise without a workspace) throws
  `InvalidArgumentException`.
- Own-app installations (new in 1.3) need their Slack `appId`, and their clicks get no actor unless
  `installations.own_app_actors: true` (see USAGE "Own apps").

### Several workspaces

Opt in with your own stores (a standard Symfony host can implement them with Doctrine entities in a few lines):

1. Implement `SlackInstallationStoreInterface` for several installations, keyed by app + workspace (the enterprise
   is informative: workspace ids are unique), and persist the new optional `SlackInstallation` fields you need. Encrypt
   the bot token, refresh token and own-app signing secret at rest; compare ids exactly (binary collation on
   MySQL / MariaDB). Extend `SlackMultiInstallationStoreContractTestCase` (and `SlackInstallationStoreContractTestCase`).
   Point `credentials.store` at it. To keep today's instance app as the default installation, let `find()` fall back to
   it for its workspace.
2. Implement `SlackInstallationBindingStoreInterface` (extend `SlackInstallationBindingStoreContractTestCase`) and set
   `installations.binding_store`.
3. For "Add to Slack": implement `SlackInstallAuthorizerInterface`, set `installations.oauth` and
   `apps.<name>.app_id`, `client_id` and `client_secret: '%env(SLACK_CLIENT_SECRET)%'`, register
   `nowo_slack_kit_install_redirect_url()` as the app's OAuth redirect URL, turn **token rotation off** in the Slack app
   (rotating grants are refused in 1.3), and render `nowo_slack_kit_install_action(type, id)` where admins manage
   integrations.
4. Listen to `SlackInstallationRevokedEvent` (its `bindings`) to disable destinations and notify the admins.

### Message updates

No action needed. Handlers may now return `->withSpentButtons($interaction, [...], 'Assigned to …')` and
`->viaResponseUrl()`; existing replies behave as before.

## To 1.2.1

From **1.2.0**:

```bash
composer update nowo-tech/slack-kit-bundle
```

Target **1.2.1**.

- `credentials.store: memory` is for tests and demos only. The container build now fails with a `LogicException` when
  it is used with `kernel.debug` false outside the `test` env. If a production config uses it, switch to
  `instance_settings` (default) or your own `SlackInstallationStoreInterface` service; installations in that store
  never survived the process anyway.

## To 1.2.0

From **1.1.0**:

```bash
composer update nowo-tech/slack-kit-bundle
```

## To 1.2.0

From **1.1.0** (target 1.2.0, DDD P2):

```bash
composer update nowo-tech/slack-kit-bundle
```

Nothing to change. New `ButtonValueCodecInterface` and `AccountLinkerInterface` (public aliases of `ButtonValueCodec`
and `AccountLinker`): prefer them in your type hints. The bundle's own services now reference the interface ids, so
if you decorate or replace `ButtonValueCodec` / `AccountLinker`, do it on the interface id (decorating the class id
still works, because the alias points at it). A replacement codec must keep the `nsk1` format if buttons already
posted to Slack must still verify.

## To 1.1.0

From **1.0.1**:

```bash
composer update nowo-tech/slack-kit-bundle
```

No action required: `linking.rate_limiter_cache_pool` defaults to `cache.app`, the pool used before.

Hosts that redefined `nowo_slack_kit.linking.confirmation_per_*.storage` (for example in `when@test`, because
`cache.app` is an `ArrayAdapter` reset between requests) can delete those service definitions and set:

```yaml
nowo_slack_kit:
    linking:
        rate_limiter_cache_pool: app.slack_linking_limiter   # a pool on a persistent adapter

framework:
    cache:
        pools:
            app.slack_linking_limiter:
                adapter: cache.adapter.filesystem   # or Redis / PDO
```

`cache.rate_limiter` inherits `cache.app`'s adapter, so it does not help when `cache.app` is an `ArrayAdapter`.

The pool must persist: the build now fails when the linking limiters' pool (the option, or `cache.app` by default) is a
`NullAdapter`, or an `ArrayAdapter` outside `kernel.debug` / the `test` environment. A production `cache.app` on the
array or null adapter must move the limiters to a persistent pool with this option.

Put it under `when@test` to keep production on `cache.app`, or use it in every env to keep limiter counters in the
rate-limiter pool.

## Installing 1.0.0

1.0.0 is the first release; there is no earlier version to upgrade from.

### From scratch

Follow [INSTALLATION.md](INSTALLATION.md): require the package, import the routes, exclude the ingress paths from
the firewall, enter the bot token and signing secret in the **Slack** settings section and press *Test connection*.

### Adopting it from an in-app Slack integration

The bundle was extracted from an application integration, and its defaults let such an app adopt it without
touching its Slack app or its database. The full walkthrough is in
[MIGRATING-FROM-AN-IN-APP-INTEGRATION.md](MIGRATING-FROM-AN-IN-APP-INTEGRATION.md). In short:

1. Keep the published Interactivity URL with `ingress.interactions_path` (for example `/hooks/slack/interactions`).
2. Keep the credentials: `credentials.store: instance_settings` with `settings_section` pointing at the existing
   section (keys `bot_token`, `signing_secret`, `team_id`, `allow_email_lookup`). No data migration.
3. Implement `SlackIdentityStoreInterface` and `SlackAccountResolverInterface` on your accounts and enable `linking`.
4. Turn each click action into an `#[AsSlackActionHandler]` service and delete the app's own signature verification,
   parsing and Web API client.
5. Configure the legacy window (below) so buttons already in Slack keep working until they age out.
6. Configure a shared lock store for the replay memory (below).

### Production lock store

`symfony/lock` is a hard dependency: `ReplayGuard` claims every verified signature (and every Events API `event_id`)
atomically through `ingress.replay_lock_factory` (default `lock.factory`). In production (`kernel.debug: false`,
environment other than `test`) the container build **fails** unless that factory's store is on the allowlist of
expiring shared stores: Redis, Memcached, PDO, Doctrine DBAL, MongoDB, DynamoDB. FrameworkBundle's defaults (`semaphore`, or `flock` without `ext-sysvsem`) only protect one host; session-scoped
stores (`pgsql+advisory:`, `pgsql+advisory://`, `postgres(ql)+advisory://`, `zookeeper://`) ignore the lock TTL;
both are refused, as is any unknown DSN or store class. Plain `pgsql:` / `postgresql://` DSNs are fine (expiring
PDO / DBAL stores).

Recommended: a **dedicated** lock resource for SlackKit, so the app-wide `framework.lock` keeps its current store:

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

FrameworkBundle's `default` resource is checked only while SlackKit points at it (the default `lock.factory`). A DSN
from an environment variable is checked at runtime by the same allowlist: a refused store then makes every ingress
request fail with 500. There is no `ingress.replay_cache_pool` option.

- **Falling back to another variable** (for example an existing `REDIS_URL`): `%env(default:REDIS_URL:SLACK_KIT_LOCK_DSN)%`
  does **not** work, because the `default:` processor takes a container parameter name as its fallback ("parameter
  "REDIS_URL" not found"). Define `parameters: { slack_kit_lock_dsn_fallback: '%env(REDIS_URL)%' }` and use
  `%env(default:slack_kit_lock_dsn_fallback:SLACK_KIT_LOCK_DSN)%`, or write `SLACK_KIT_LOCK_DSN=${REDIS_URL}` in
  `.env` ([CONFIGURATION.md](CONFIGURATION.md#lock-dsn-with-a-fallback)).
- **Test environment**: the allowlist is not enforced there, but `flock` (and `semaphore`) release each claim as soon
  as it returns, so a replay goes through. Override the resource with `slack_kit: ['in-memory']` in the test config
  and call `$client->disableReboot()` in tests that send the same request twice
  ([CONFIGURATION.md](CONFIGURATION.md#replay-lock-store-in-tests)).

### Values already posted to Slack

- **Unsigned JSON values** (posted before the app signed its buttons): set `interactions.legacy_value_key`,
  `legacy_action_map` and `legacy_until`. Use the **same** `legacy_until` instant the app already used (for
  symfony-beacon: its 1.122.1 deploy + 30 days), so the adoption does not reset the window. It must be a strict UTC
  instant such as `2026-12-05T00:00:00Z` (a literal in another form fails the build; an environment value in another
  form turns legacy off). Mapping a legacy action to `nowo_slack_kit.link.confirm` fails the build.
- **`nsk1` values signed by the app itself** (symfony-beacon 1.122.1 signs with the same format and the same
  `action_id`s): nothing to do as long as `kernel.secret` is unchanged. If it was rotated, list the old value in
  `interactions.previous_secrets` as an `%env()%` placeholder.
- **Link confirmations without `c`** (the 1.122.1 interim format `{acc, uid}`, no nonce): accepted while
  `legacy_until` is in the future; they expire after 24 h anyway. See the replay window in
  [SECURITY.md](SECURITY.md#residual-risks).
- `interactions.value_ttl` (default `P30D`) should match the lifetime the app used for its own values.
