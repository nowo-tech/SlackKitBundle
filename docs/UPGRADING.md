# Upgrading

Instructions for moving between versions of `nowo-tech/slack-kit-bundle`. Breaking changes and required host actions
are listed per target version.

## Table of contents

- [Installing 1.0.0](#installing-100)
  - [From scratch](#from-scratch)
  - [Adopting it from an in-app Slack integration](#adopting-it-from-an-in-app-slack-integration)
  - [Production lock store](#production-lock-store)
  - [Values already posted to Slack](#values-already-posted-to-slack)

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
