# Concurrency (REQ-RUNTIME-003)

## Table of contents

- [Summary](#summary)
- [Write paths](#write-paths)
- [Locks](#locks)
- [Lock store and cache pool](#lock-store-and-cache-pool)
- [Rule 9: a Slack call under the click link locks (deliberate exception)](#rule-9-a-slack-call-under-the-click-link-locks-deliberate-exception)
- [Codes and HTTP answers (rule 6)](#codes-and-http-answers-rule-6)
- [Host options](#host-options)
- [Worker mode](#worker-mode)
- [Tests](#tests)
- [Residual risks](#residual-risks)

## Summary

The bundle has no entities, no Doctrine/DBAL writes and no console commands that write. Its writes go to the host's
stores (installation, binding and identity stores, or InstanceSettings), which own their transactions and concurrency
control. What the bundle owns is a set of **`symfony/lock` claims** on `ingress.replay_lock_factory`:

- single-use claims (never released, they expire by TTL): the Slack request signature, the Events API `event_id`, the
  "Add to Slack" `state`, the link-from-click token;
- short mutual-exclusion locks (released in `finally`): the (workspace, member) and (account, workspace) link locks.

Every claim is **non-blocking** (`acquire(false)`): a second request never waits, it gets an answer at once. Every
claim **fails closed** when the lock store cannot be reached: the request is not handled and the answer is never a
500. Every lock is created with `autoRelease: false`, so no lock object releases (or touches the store) from a
destructor.

## Write paths

| Path | Transaction | Control | Answer to the loser / on failure |
|------|-------------|---------|----------------------------------|
| Ingress, every signed request (`IngressController::verify()` → `ReplayGuard::claimSignature()`) | None (one lock-store insert) | Atomic claim `nowo_slack_kit.replay.sig.<sha256(signature)>`, TTL 2 × `max_skew_seconds`, never released | Replay: `401 Invalid signature`. Store down: `503` + `Retry-After: 5` |
| Events API `event_callback` (`ReplayGuard::claimEvent()`) | None | Atomic claim `nowo_slack_kit.replay.evt.<sha256(event_id)>`, TTL 3600 s; released when handling fails so Slack's retry is handled | Duplicate delivery: `200` (acknowledged, not handled again). Store down: `503` + `Retry-After: 5`. A failed release is logged |
| Events handling (`EventsHandler`, sync or Messenger `HandleSlackEvent`): `app_uninstalled` / `tokens_revoked` → `markRevoked()` on the host installation store | The host store's | Idempotent: re-reads the installation and skips one already revoked; a Messenger replay revokes nothing twice | The handler's error: `500` (sync) after the `event_id` claim is released, or the Messenger retry |
| "Add to Slack" callback (`InstallController::callback()` → `InstallStateStore::consume()`) | None for the claim | The record leaves the session on first use **and** an atomic claim `nowo_slack_kit.install_state.<id>`, TTL `state_ttl` (≥ the state's remaining lifetime), never released | Already used: `303` + flash `install.flash.invalid_state`. Store down: `303` + flash `install.flash.unavailable`; the code is never exchanged, nothing is installed |
| Install save (`SlackInstallationStoreInterface::save()` then `SlackInstallationBindingStoreInterface::bind()`) | The host stores' | Runs once per state (claim above); a re-install of the same workspace replaces the row (last writer wins, both grants are valid) | Host store errors propagate |
| Link from a click (`ClickLinker::confirm()`) | The host identity store's | Two locks taken in sorted order: `link_pair` (workspace, member) and `link_account` (account, workspace), TTL `api.max_duration` + 10 s, released in `finally`; then the single-use token claim `link_click.<sha256(nonce)>` (TTL = the token's remaining lifetime) after every check; the store contract refuses a pair another account holds (`SlackIdentityConflictException`) | Lost lock: `LinkResult::Busy` → `303` + flash + `X-Conflict-Code: CONFLICT_001` + `Retry-After: 1`, the token is not burned. Store down: `LinkResult::Unavailable`. The DM goes out after the locks are released |
| Typed claim / find by e-mail (`AccountLinker::claim()`, `findByEmail()`) | The host identity store's | The same `link_pair` lock, TTL 30 s, released in `finally`; the Slack e-mail lookup runs **before** the lock; store contract as above | Lost lock: `Busy` (as above). Store down: `Unavailable`. Pair held by another account: `InUse` |
| Confirm button (`AccountLinker::confirm()`, a signed click on the ingress) | The host identity store's | No lock: verifying twice is a no-op, and the store contract refuses a pair taken meanwhile | Taken meanwhile: `403` ephemeral "Confirmation refused" |
| Unlink (`AccountLinker::unlink()`, `ClickLinker::unlinkIn()`) | The host identity store's | Idempotent delete | `303` + flash |
| Settings section save, *Test connection* | InstanceSettings' | InstanceSettings' own control (the bundle does not write rows itself) | InstanceSettings' answer |
| Rate limiters (linking, *Test connection*) and the client memory (429 `Retry-After`, unhealthy reports) | None | PSR-6 cache counters (`rate_limiter_cache_pool`, `cache.app`), best effort | See [Residual risks](#residual-risks) |

## Locks

| Lock | TTL | Work done while held | Release |
|------|-----|----------------------|---------|
| `replay.sig` | 2 × `max_skew_seconds` + 1 s (601 s by default): a signature is only accepted inside its ±`max_skew_seconds` window (whole seconds, edges included), so the claim outlives every moment it could be replayed | none | never (expires) |
| `replay.evt` | 3600 s (`ReplayGuard::EVENT_TTL`), longer than Slack's retry schedule | the sync handler, or the Messenger dispatch | only when handling fails |
| `install_state` | `installations.oauth.state_ttl` (at most 600 s), at least the state's remaining lifetime | none | never (expires) |
| `link_click` | the token's remaining lifetime (`linking.from_click.ttl`) | none | never (expires) |
| `link_pair`, `link_account` (click) | `api.max_duration` + 10 s | re-reads, one Slack `users.info` call (bounded by `api.max_duration`), the host e-mail check, the identity store write | `finally`; a failed release is logged and the lock expires |
| `link_pair` (typed claim / e-mail) | 30 s (`ClickLinker::DEFAULT_LOCK_TTL`) | re-read and the identity store write (no Slack call) | `finally`; a failed release is logged and the lock expires |

`symfony/lock` wraps every store failure in `LockAcquiringException` (save) or `LockReleasingException` (delete). The
bundle catches them on its own paths only:

- acquiring fails → fail closed (503 on the ingress, a flash on the HTML routes), logged with `reason: replay_store`,
  `state_store` or `lock_store` and the store exception's class (no key, no value);
- releasing fails → the answer is kept, a warning or error is logged, the lock expires after its TTL. `Lock::release()`
  wraps the store's `delete()` but not the `exists()` check after it, so the bundle catches any `Throwable` there (a raw
  `PDOException` or `RedisException` included) and logs only its class.

## Lock store and cache pool

A claim is single-use only among processes that share the **same lock store**.

| Deploy | Lock store (`ingress.replay_lock_factory`) | Result |
|--------|-------------------------------------------|--------|
| One server (PHP-FPM or FrankenPHP workers) | Expiring shared store: Redis, Memcached, PDO / Doctrine DBAL, MongoDB, DynamoDB | Atomic and single-use: exactly one request wins, also after the winner's process ended |
| One server, debug or test only | `flock` / `semaphore` / in-memory | **Not single-use**: a `flock` or `semaphore` claim ends with its lock object, which the bundle drops right after the claim, so a later copy can pass. Refused outside debug and test |
| Several servers | Shared expiring store (one Redis, one database) | Atomic and single-use across servers |
| Several servers | A store local to each server (a SQLite file per server, a local Redis per server) | **Not single-use**: each server accepts the same signature, event or state once |
| Any | In-memory SQLite (`sqlite::memory:`, `sqlite:///:memory:`, a DBAL connection with `memory: true`) | **Not single-use**: the database lives in one process. Refused outside debug and test, by DSN and, at run time, for a `PdoStore` DSN or a DBAL connection's parameters; a `PDO` object handed to `PdoStore` cannot be inspected without a query and is trusted as configured. The run-time check reads private Symfony Lock properties; when one is missing, renamed or uninitialised it falls back to "cannot tell" (trusted as configured, never an exception) and the build-time DSN check remains the guard |

Outside debug and test, `ReplayLockPass` fails the container build unless the factory's store is on the allowlist of
expiring shared stores (see [CONFIGURATION.md](CONFIGURATION.md#ingress)), and `ReplayGuard` checks it again at run
time. The allowlist checks the store class, not where it points: a multi-server deploy must point every server at the
**same** Redis or database.

The rate limiters and the client memory use cache pools: `linking.rate_limiter_cache_pool` (refused when it is a
`NullAdapter`, or an `ArrayAdapter` outside debug and test) and `cache.app`. On several servers use shared pools (Redis,
database), otherwise each server counts on its own.

Production example:

```yaml
framework:
    lock:
        slack_kit: '%env(SLACK_KIT_LOCK_DSN)%'   # e.g. redis://redis:6379 or the app's database DSN
    cache:
        pools:
            cache.slack_kit_limits:
                adapter: cache.adapter.redis
                provider: '%env(REDIS_DSN)%'

nowo_slack_kit:
    ingress:
        replay_lock_factory: lock.slack_kit.factory
    linking:
        rate_limiter_cache_pool: cache.slack_kit_limits
```

### Rule 9: a Slack call under the click link locks (deliberate exception)

The click link locks (`link_pair`, `link_account`) are held across one Slack `users.info` call, so that the profile
check, the e-mail match and the write see the same state. This is a deliberate exception to rule 9 ("no HTTP while
holding a lock"): these are lock-store keys with a TTL, not database row or advisory locks, so they block no database
connection and no other key; the call is bounded by `api.max_duration`, and the lock TTL is `api.max_duration` + 10 s.
A concurrent request for the same member or account gets `Busy` at once (non-blocking), never waits. The confirmation
DM is sent after the locks are released.

## Codes and HTTP answers (rule 6)

The bundle has no database writes, so it never sees a deadlock, a lock-wait timeout or a serialization failure, and it
needs no `nowo-tech/transaction-*` dependency. Its lock-store claims answer as follows.

| Route (the bundle's own) | Lost claim | Code |
|--------------------------|------------|------|
| `POST /slack/interactions`, `POST /slack/events` (Slack) | Replayed signature: `401`. Duplicate `event_id`: `200` | None: not applicable (see below) |
| `GET …/install/callback` (OAuth) | `303` + flash "invalid or expired, start again" | None: not applicable (see below) |
| `POST …/account/slack/claim`, `…/find`, `…/link/{token}` (HTML forms) | `303` + flash "another request is in progress, try again" | `X-Conflict-Code: CONFLICT_001` + `Retry-After: 1` |

Why the Slack and OAuth routes carry no conflict code:

- **Slack ingress.** A lost signature claim is a replay, not contention: the winning copy was handled, and repeating
  the same request can never succeed. A lost `event_id` claim is a duplicate delivery that Slack must stop retrying,
  and Slack only stops on a 2xx; a 409 would make Slack retry three times and mark the app as failing. Slack does not
  read `X-Conflict-Code`.
- **OAuth callback.** A lost state claim means another request already used that state; the code it carried is
  single-use at Slack too, so a retry cannot succeed. The user starts the install again.
- **HTML link forms.** These are Post/Redirect/Get forms. A request that loses a link lock wrote nothing, so a retry is
  safe: `CONFLICT_001`. The answer stays a `303` with the "try again" flash, which is the branded try-again page of
  these forms (a bare 409 would show the browser's empty page), and carries `X-Conflict-Code: CONFLICT_001` and
  `Retry-After: 1` so that clients and tests can key on the code. `CONFLICT_002` never applies: a request that loses
  a lock has written nothing.
- **Lock store unreachable.** Not a conflict (like a lost database connection): the ingress answers `503` +
  `Retry-After: 5`, the HTML routes a flash; no `X-Conflict-Code`.

The bundle never answers for host routes.

## Host options

| Option | Default | Effect |
|--------|---------|--------|
| `ingress.replay_lock_factory` | `lock.factory` | `LockFactory` of every claim and lock above; must be an expiring store shared by every worker and server |
| `ingress.max_skew_seconds` | 300 | Signature time window; the signature claim lives twice as long |
| `installations.oauth.state_ttl` | 600 | State lifetime and its claim's TTL |
| `linking.from_click.ttl` | 600 | Link token lifetime and its claim's TTL |
| `api.max_duration` | 10.0 s | Bounds the Slack call under the click link locks (TTL = this + 10 s) |
| `linking.rate_limiter_cache_pool` | `cache.app` | Limiter counters; shared across servers |
| `ingress.async` | false | Events handled by a Messenger worker; the `event_id` claim stays after the dispatch |

## Worker mode

FrankenPHP worker mode with kernel reset false: every claim lives in the lock store, never in process memory; the
services are `readonly` and keep no per-request state; lock objects are local variables, created with
`autoRelease: false` and released explicitly. See [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).

## Tests

- `tests/Integration/Race/LockRaceTest.php`: real OS processes (`symfony/process`, 30 s timeout each), each with its
  own `LockFactory`, start at the same instant on the same claim:
  - signature, `event_id` and install state on a shared `FlockStore` and on a shared expiring `PdoStore` (one SQLite
    file): exactly one of four processes wins (on `FlockStore` the race process keeps its lock objects alive, since a
    `flock` claim ends with its lock object; this is why `flock` is refused outside debug and test);
  - a signature replayed after the winning process exited is refused by the expiring store;
  - signature and install state on a `FlockStore` per "server": every server wins once (the multi-server risk above).
- `tests/Unit/Ingress/IngressControllerTest.php`: an unreachable store answers `503` + `Retry-After`, nothing handled,
  no `X-Conflict-Code`; a failed release of a failed event is logged.
- `tests/Unit/Install/InstallStateStoreTest.php`, `tests/Integration/Functional/LockFailureFlowTest.php`: an
  unreachable store spends the state, installs nothing and shows the "temporarily unavailable" flash; a lost link lock
  answers `303` + `X-Conflict-Code: CONFLICT_001` + `Retry-After: 1`, and the retry links.
- `tests/Unit/Linking/ClickLinkerTest.php`, `ClickLinkRound2Test.php`, `AccountLinkerConflictTest.php`: lost locks
  answer `Busy` without burning the token, store failures answer `Unavailable` with nothing written, a failed release
  keeps the result.

## Residual risks

- **Store local to each server**: claims are single-use per server only (see the table). Configuration, not code;
  covered by a race test.
- **`flock` / `semaphore` in debug and test**: a claim ends with its lock object, so replays are not refused there. Refused outside debug and test.
- **Lock TTL shorter than the work**: the click link locks expire after `api.max_duration` + 10 s. If the host e-mail
  checker or identity store is slower than that, a second confirmation can enter; the identity store contract (one
  account per pair, `SlackIdentityConflictException`) still refuses a second owner, so the cost is a refused request,
  not a duplicate.
- **Failed release of a failed event**: the `event_id` claim stays for up to an hour, so Slack's retries of that event
  are acknowledged without being handled. Logged as an error (`Slack event claim could not be released.`).
- **Store outage**: while the lock store is down, every Slack request answers 503 and every link or install attempt is
  refused. This is the intended trade-off of replay protection that fails closed.
- **Rate limiter counters** (fixed window on a cache pool, no lock): concurrent attempts can pass a few over the limit.
  Accepted: the limits slow abuse down, they are not a single-use rule.
- **Unhealthy-installation report** (`cache.app`, check then save): two workers can report the same installation at
  once. Accepted: the event is advisory.
- **Concurrent re-installs of one workspace**: two valid OAuth grants of the same workspace both save; the last one
  wins. The host store decides how rows are written.
