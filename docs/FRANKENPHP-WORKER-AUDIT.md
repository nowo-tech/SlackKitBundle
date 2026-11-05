# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/slack-kit-bundle` (`symfony-bundle`) |
| Audited revision | `1.0.0` (unreleased, branch `main`) |
| Audit date | 2026-11-05 |
| Method | Manual review of every file under `src/`; PHPStan with `nowo-tech/phpstan-frankenphp` (`ruleset-classic`, `ruleset-worker-strict`, `ruleset-hardening`); Igor (`composer igor`); unit tests of `ReplyDispatcher::reset()` and `InMemoryInstallationStore::reset()`; kernel tests with consecutive requests on one container; demo run in worker mode |
| **Verdict** | ✅ **Viable** — services are stateless (`final readonly`) except the reply buffer (filled and flushed within one request, reset by `kernel.reset`), the in-memory test store and the route loader's load-once flag; credentials are read from the store on every call and never memoised |

## Table of contents

- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [Services reviewed](#services-reviewed)
- [Usage recommendations in worker mode](#usage-recommendations-in-worker-mode)
- [Re-audit triggers](#re-audit-triggers)

## Execution model assumed

FrankenPHP worker mode boots the kernel once per worker and serves many requests with the same container. Two
scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs.**
- **B — no reset at all:** any state kept in a service survives into the next request.

A bundle safe under **B** is safe under **A**, classic mode and PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | `ReplyDispatcher` pending list (flushed and emptied on `kernel.terminate`, emptied by `kernel.reset`); `InMemoryInstallationStore` (tests / demo, `kernel.reset`); route loader load-once flag (cache warm-up) |
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
| `InMemoryInstallationStore` | array of installations (tests, demo) | ✅ (`kernel.reset`) |
| `SlackSettingsSection`, `SlackKitTwigExtension` | no mutable state | ✅ |
| `SlackKitRouteLoader` | load-once flag at router warm-up | ✅ (`@igor-ignore` with reason) |
| `SlackKitTransportFactory`, `SlackKitTransport`, `RateLimitRetryMiddleware` | `readonly` | ✅ |

## Usage recommendations in worker mode

- Configure `framework.lock` (used by `ingress.replay_lock_factory`) with a shared Redis or DBAL store: a local store
  (`flock`, `semaphore`, `in-memory`) only remembers signatures per host or process, and fails the build outside debug
  and test. `ReplayGuard` is `readonly`; the replay memory lives entirely in the lock store.
- Host handlers, identity stores and signing-secret resolvers must be stateless too; read accounts and secrets per
  call.
- Do not cache `SlackInstallation` objects or revealed secrets in host services.
- Keep `api.max_duration` below PHP `max_execution_time`; send bulk messages from Messenger workers.

## Re-audit triggers

- A new service with mutable properties, a cache or a static.
- Token rotation (refresh tokens) or OAuth state handling (1.1).
- Moving deferred replies to another lifecycle hook.
