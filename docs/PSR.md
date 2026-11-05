# PHP-FIG PSR evaluation (REQ-CS-007)

Package: `nowo-tech/slack-kit-bundle` (`symfony-bundle`)

This document records which [PHP-FIG PSRs](https://www.php-fig.org/psr/) apply to this package.
Only contracts that add clear interoperability or maintainability value are **Adopted**.
Others are **N/A** (or already covered by Symfony) so the decision stays auditable.

## Table of contents

- [Baseline (always)](#baseline-always)
- [Interface / contract PSRs](#interface--contract-psrs)
- [Summary](#summary)

## Baseline (always)

| PSR | Decision | How |
| --- | -------- | --- |
| PSR-12 (coding style) | **Adopted** | `@PSR12` in `.php-cs-fixer.dist.php` (Nowo REQ-CS-001). |
| PSR-4 (autoloading) | **Adopted** | `composer.json` `autoload` / `autoload-dev` PSR-4 map for package sources and tests. |

## Interface / contract PSRs

| PSR | Decision | Notes |
| --- | -------- | ----- |
| PSR-3 Logger | **Adopted** | The client, ingress, router, reply dispatcher and linker take a `Psr\Log\LoggerInterface` (default `NullLogger`, wired to `logger`, `slack` channel); context is ids, codes and reasons only. |
| PSR-6 Cache | **Adopted** | `ClientMemory` (429 memory, unhealthy-report throttle) uses a `Psr\Cache\CacheItemPoolInterface` (`cache.app`). The replay memory (`ReplayGuard`) needs an atomic claim, which PSR-6 cannot express, so it uses `symfony/lock` (`ingress.replay_lock_factory`). |
| PSR-16 Simple cache | **N/A** | PSR-6 covers the needs (per-item TTL). |
| PSR-20 Clock | **Adopted** | `SignatureVerifier`, `ButtonValueCodec`, `LegacyValuePolicy`, `AccountLinker`, `EventsHandler` and the client take a `Psr\Clock\ClockInterface` (Symfony `clock`), so time windows and expiries are testable. |
| PSR-14 Event dispatcher | **Adopted** | Events are dispatched through `Psr\EventDispatcher\EventDispatcherInterface` (Symfony `event_dispatcher`). |
| PSR-11 Container | **Adopted (narrow)** | The router resolves handlers from a `Psr\Container\ContainerInterface` service locator built by `ActionHandlerPass`. |
| PSR-7 / PSR-17 HTTP messages | **N/A** | HttpFoundation for the ingress and the UI; Slack calls use Symfony HttpClient options. |
| PSR-18 HTTP client | **N/A — Symfony HttpClient is the SPI** | The client needs per-request `timeout`, `max_duration`, `max_redirects: 0` and an `on_progress` size cap, which PSR-18 cannot express; `Symfony\Contracts\HttpClient\HttpClientInterface` is the documented client (`MockHttpClient` in tests and the demo). |
| PSR-15 HTTP middleware | **N/A** | Controllers only; no middleware pipeline. |

## Summary

- **Adopted beyond baseline:** PSR-3, PSR-6, PSR-11 (locator), PSR-14, PSR-20, type-hinted and wired.
- **Rule:** do not add `psr/*` Composer dependencies without matching type-hints and DI wiring.
- **Re-evaluate** when the package gains new SPIs (OAuth, Doctrine store).

---

_REQ-CS-007 evaluation date: 2026-11-05._
