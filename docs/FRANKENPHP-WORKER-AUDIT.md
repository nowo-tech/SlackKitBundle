# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/slack-kit-bundle` (`symfony-bundle`) |
| Audited revision | `1.2.1` (first audit `1.0.0`) |
| Audit date | 2026-11-05, refreshed 2026-11-12 (REQ-RUNTIME-002) |
| Worker test | [`MemoryStoreWorkerTest`](#worker-test-req-runtime-002): 1,000 requests through `http_kernel`, two workspaces |
| Method | Manual review of every file under `src/`; PHPStan with `nowo-tech/phpstan-frankenphp` (`ruleset-classic`, `ruleset-worker-strict`, `ruleset-hardening`); Igor (`composer igor`); unit tests of `ReplyDispatcher::reset()` and `InMemoryInstallationStore::reset()`; kernel tests with consecutive requests on one container; demo run in worker mode |
| **Verdict** | ✅ **Viable** — services are stateless (`final readonly`) except the reply buffer (filled and flushed within one request, reset by `kernel.reset`), the in-memory test store (restored at every boundary since 1.2.1) and the route loader's load-once flag; credentials are read from the store on every call and never memoised |

## Table of contents

- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [Services reviewed](#services-reviewed)
- [Worker test (REQ-RUNTIME-002)](#worker-test-req-runtime-002)
- [Usage recommendations in worker mode](#usage-recommendations-in-worker-mode)
- [Re-audit triggers](#re-audit-triggers)

## Execution model assumed

FrankenPHP worker mode boots the kernel once per worker and serves many requests with the same container. Two
scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs.**
- **B — no reset at all:** any state kept in a service survives into the next request.

A bundle safe under **B** is safe under **A**, classic mode and PHP-FPM.

Precondition, precisely: with the stock Symfony kernel, `FRANKENPHP_RESET_KERNEL=false` only stops the kernel from being
cloned; `Kernel::handle()` still calls `services_resetter->reset()` before every request after the first (scenario A).
Scenario B happens when a host removes, replaces or bypasses `services_resetter` (or drives `http_kernel` directly),
in Messenger consumers started with `--no-reset`, and in long-running console commands.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | `ReplyDispatcher` pending list (flushed and emptied on `kernel.terminate`, emptied by `kernel.reset`); `InMemoryInstallationStore` (tests / demo; `InMemoryInstallationStoreBoundaryListener` at request, message and console boundaries, plus `kernel.reset`); route loader load-once flag (cache warm-up) |
| Static properties / `static` locals | ✅ | None (`Blocks`, `Mrkdwn`, `SecretRedactor`, codec helpers are pure static functions) |
| `ResetInterface` / `kernel.reset` | ✅ | `ReplyDispatcher::reset()`, `InMemoryInstallationStore::reset()` |
| Request / user captured in services | ✅ | Controllers and the Twig extension read the account through the host resolver per call |
| Credentials | ✅ | Bot token and signing secrets read from the store per call, held in `SlackSecret` only for that call; the button key is derived from `kernel.secret` (a boot-time constant) |
| Cross-request memory | ✅ (by design) | Replay memory, 429 memory, unhealthy-report throttle and rate limiters live in PSR-6 pools, not in services |
| Superglobals, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None |
| Outbound I/O | ✅ | Every Slack call has an idle timeout, a total duration cap, a response cap and no redirects; deferred calls run after the response |
| Resources held open | ✅ | None (HttpClient manages its connection pool) |

## Services reviewed

| Service | State | Verdict |
|---------|-------|---------|
| `SlackApiClient`, `IncomingWebhookClient`, `ApiPolicy`, `ClientMemory` | `readonly`; installation resolved per call | ✅ |
| `IngressController`, `SignatureVerifier`, `ReplayGuard`, `EventsHandler` | `readonly` | ✅ |
| `ButtonValueCodec`, `LegacyValuePolicy`, `ActorResolver`, `InteractionRouter` | `readonly`; keys built at container build | ✅ |
| `ReplyDispatcher` | pending replies of the current request; `kernel.terminate` flush, `kernel.reset` | ✅ (scenario B: the next `kernel.terminate` flushes or the list is empty) |
| `AccountLinker`, `ConfirmLinkHandler`, `AccountLinkController`, `TestConnectionController` | `readonly`; limiters and forms created per call | ✅ |
| `InstanceSettingsInstallationStore`, `SlackAppRegistry` | `readonly`; read InstanceSettings per call | ✅ |
| `InMemoryInstallationStore` | array of installations (tests, demo) | ✅ (restored at the main `kernel.request`, `kernel.terminate`, Messenger received / handled / failed and the outermost `console.terminate`; `kernel.reset`) |
| `InMemoryInstallationStoreBoundaryListener` (with `store: memory`, 1.2.1) | console depth counter and in-flight message / request flags (no request data; deliberately not `kernel.reset`) | ✅ |
| `SlackSettingsSection`, `SlackKitTwigExtension` | no mutable state | ✅ |
| `SlackKitRouteLoader` | load-once flag at router warm-up | ✅ (`@igor-ignore` with reason) |
| `SlackKitTransportFactory`, `SlackKitTransport`, `RateLimitRetryMiddleware` | `readonly` | ✅ |

## Worker test (REQ-RUNTIME-002)

`tests/Integration/Functional/MemoryStoreWorkerTest.php` (runs in `make test` / CI):

- **Kernel:** one `SlackKitTestKernel` with `credentials.store: memory`; requests go through the `http_kernel`
  service and `terminate()`, so the services resetter never runs between them.
- **N and tenants:** **1,000 requests**, workspaces `T00000000` and `T00000001` in alternation. Each request reports
  what a workspace-less lookup returns, then installs the app for its own workspace. Every request sees no
  installation (before 1.2.1 the second request already saw the first workspace's installation).
- **Memory:** within **2 MiB** of the value after request 20 (the test logger's records are dropped per request).
- **Boundaries:** a sub-request keeps the main unit; Messenger received / handled / failed / stopped; a command
  nested in a message, in another command or in an HTTP request does not clear the outer unit, the outermost one
  does; a boundary never builds the store; other stores get no boundary listener.

The bundle has no Doctrine entity manager of its own (the `instance_settings` store reads InstanceSettings per call).
A host that disables or bypasses the services resetter must clear its own entity managers at every boundary (main
request, Messenger message, console command); otherwise the identity map serves stale rows across requests
(REQ-RUNTIME-002 rule 7).

## Usage recommendations in worker mode

- Point `ingress.replay_lock_factory` at a dedicated `framework.lock` resource on an expiring shared store
  (Redis, Memcached, PDO, Doctrine DBAL, MongoDB, DynamoDB). Local stores only protect one worker or host, and
  session-scoped ones (`pgsql+advisory`, `zookeeper`) pile up locks held until the worker restarts; both fail the
  production build.
- Host handlers, identity stores and signing-secret resolvers must be stateless too; read accounts and secrets per
  call.
- Do not cache `SlackInstallation` objects or revealed secrets in host services.
- Keep `api.max_duration` below PHP `max_execution_time`; send bulk messages from Messenger workers.

## Re-audit triggers

- A new service with mutable properties, a cache or a static.
- Token rotation (refresh tokens) or OAuth state handling (1.1).
- Moving deferred replies to another lifecycle hook.
