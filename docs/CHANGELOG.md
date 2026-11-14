# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## Table of contents

- [[Unreleased]](#unreleased)
  - [Added (spec 237, T000a: several workspaces)](#added-spec-237-t000a-several-workspaces)
  - [Added (spec 237, T000c: message updates)](#added-spec-237-t000c-message-updates)
  - [Changed](#changed)
  - [Security](#security)
- [[1.2.1] - 2026-11-12](#121---2026-11-12)
  - [Fixed](#fixed)
  - [Changed](#changed-1)
- [[1.2.0] - 2026-11-10](#120---2026-11-10)
  - [Added](#added)
  - [Documentation](#documentation)
- [[1.1.0] - 2026-11-08](#110---2026-11-08)
  - [Added](#added-1)
- [[1.0.1] - 2026-11-05](#101---2026-11-05)
  - [Documentation](#documentation-1)
- [[1.0.0] - 2026-11-05](#100---2026-11-05)
  - [Added](#added-2)
  - [Security (REQ-SEC-004 audit, appendix FS)](#security-req-sec-004-audit-appendix-fs)
  - [Interim decisions (aligned with symfony-beacon 1.122.1)](#interim-decisions-aligned-with-symfony-beacon-11221)
  - [Notes / deviations from contract v1.1](#notes--deviations-from-contract-v11)

## [Unreleased]

Target: **1.3.0** (minor; no breaking change, see [UPGRADING.md](UPGRADING.md#to-130)). Symfony Beacon spec 237
"Slack multi-workspace with identity", tasks T000a and T000c (T000b, identities per workspace and link from a click,
follows in 1.4.0).

### Added (spec 237, T000a: several workspaces)

- Installations keyed by **(app, enterprise?, workspace)**: `SlackInstallationKey` gains an optional
  `SlackEnterpriseId` (`key()` is `app:team` or `app:enterprise:team`), `workspaceKey()`, `sameWorkspace()` and
  `admits()`. `SlackInstallation` gains optional OAuth fields: enterprise, Slack app id, workspace name, granted scopes,
  installer, install time, refresh token and expiry (token rotation), revocation reason and the signing secret of a
  workspace's **own app** (FR-004), plus `storageKey()`, `withKey()`, `revoked()`, `withWorkspace()`, `hasScope()`,
  `isTokenExpired()`.
- Owner-scope **bindings**: `SlackOwnerScope` (host type + opaque id), `SlackInstallationBinding`, the port
  `SlackInstallationBindingStoreInterface` (`installations.binding_store`), `InMemoryInstallationBindingStore`
  (tests and demos) and the contract test cases `SlackInstallationBindingStoreContractTestCase` and
  `SlackMultiInstallationStoreContractTestCase`.
- **"Add to Slack"** (OAuth v2, `installations.oauth`, off by default): `POST <ui.prefix>/install/{app}/{type}/{id}`
  (CSRF) and `GET <ui.prefix>/install/callback`; the host authoriser port `SlackInstallAuthorizerInterface`
  (`SlackInstallAttempt`) decides who may install for a scope, at the start and again on the way back; signed,
  single-use state bound to the session and the account (10 minutes); grant checks (bot token, same Slack app,
  workspace install, required scopes, no token rotation); re-installing updates the token; `SlackOAuthClientInterface`
  (`oauth.v2.access`), `SlackInstallationCompletedEvent`; `apps.<name>.client_id` / `client_secret` (`%env()%` only);
  Twig `nowo_slack_kit_install_action()` and `nowo_slack_kit_install_redirect_url()`; translations in 7 locales.
- Own-app installations: a click or event whose app secret does not match is verified with the own-app secret of the
  installation named by its (unverified) workspace id, then checked against that installation's Slack app id.
- `SlackInstallationRevokedEvent::$bindings`: the bindings of the revoked installation (FR-006).

### Added (spec 237, T000c: message updates)

- `MessageUpdate::withoutSpentButtons()`: the clicked message without its spent buttons (or every interactive
  element) plus a context line such as "Assigned to Ana"; `SlackInteractionReply::withSpentButtons()` uses it for a
  `chat.update` replacement.
- `SlackInteraction::$message` (`SlackOriginalMessage`, the clicked message from the verified payload) and
  `SlackInteraction::$responseUrl` (a redacted `SlackSecret` of kind `response_url`, `hooks.slack.com/actions` only).
- `SlackInteractionReply::viaResponseUrl()`: ephemeral answers, replacements and deletions through the click's
  `response_url` (no bot token; also for clicks verified by a host resolver); `ResponseUrlClientInterface` (public
  alias) with `SlackResponseUrlReply`.
- `SlackSecret` kinds `refresh_token`, `client_secret` and `response_url`.

### Changed

- The Web API client refuses a store answer whose workspace (or enterprise) is not the key's (`not_installed`) and an
  expired rotating token (`token_expired`), and remembers rate limits per workspace installation.
- `app_uninstalled` / `tokens_revoked` revoke an active installation **once**: a second revocation event for the same
  installation no longer dispatches `SlackInstallationRevokedEvent`, and `tokens_revoked` counts only when it names
  the installation's bot (when the installation knows its bot user id).
- The ingress checks the payload's enterprise against the installation's, and `api_app_id` against the installation's
  own Slack app id when it records one (else `apps.<name>.app_id` as before).
- `SlackInstallation` refuses a token of another kind than a bot token (it only ever worked with one).
- `composer test-coverage-100` now enforces **100%** (its name said 100, its script said 99). The CI coverage job already runs it, so the gate is 100% in CI too; the suite is at 100%.

### Security

- Cross-workspace isolation is enforced at every layer: an installation is found only by its own workspace key, the
  API client double-checks the store's answer, button values stay bound to their workspace, actors resolve only from a
  link of the click's workspace, and replies use the click's own installation. Covered by unit tests and a FrankenPHP
  worker test (one kernel, no resetter, two workspaces alternating).
- The OAuth code, state, client secret and tokens never reach a log, an exception message or the session in clear
  (the session keeps the state's record with an account hash only).

## [1.2.1] - 2026-11-12

Target: **1.2.1** (patch).

### Fixed

- Worker state (REQ-RUNTIME-002): the `memory` installation store (`credentials.store: memory`, tests and demos)
  kept the installations saved or revoked during a request until `kernel.reset`. A workspace-less lookup returns the
  app's first installation, so a later request for another workspace could get the previous workspace's installation
  (bot token). The new `InMemoryInstallationStoreBoundaryListener`, registered with the `memory` store, restores the
  initial state at the main `kernel.request`, `kernel.terminate`, Messenger received / handled / failed, and
  `console.terminate` of the outermost command only, outside any Messenger message or HTTP request (a depth counter
  plus in-flight message and request flags keep a command run in-process from another command, a message handler or
  a controller from clearing the outer unit). The store stays `ResetInterface` / `kernel.reset`; the boundary
  never builds it (`IGNORE_ON_UNINITIALIZED_REFERENCE`). Precondition: only where the services resetter does not run
  between units: a host that removes, replaces or bypasses `services_resetter` (or drives `http_kernel` directly),
  Messenger consumers started with `--no-reset`, and long-running console commands; with the stock kernel
  (`FRANKENPHP_RESET_KERNEL=false` included) `kernel.reset` already restored it before each request. The default
  `instance_settings` store and host stores are not affected. Affected: 1.0.0 to 1.2.0 with `store: memory` only.

### Changed

- `credentials.store: memory` is tests and demos only, never in production: the container build now fails with a
  `LogicException` when it is used with `kernel.debug` false outside the `test` env (as the replay lock store check
  already does). See UPGRADING.
- Worker test: `MemoryStoreWorkerTest` sends 1,000 requests through `http_kernel` (no services resetter) for two
  workspaces in alternation, each installing the app for its own workspace, and checks that no request sees the
  other's installation, the Messenger and nested console boundaries, and flat memory. Worker audit refreshed.

[1.2.1]: https://github.com/nowo-tech/SlackKitBundle/releases/tag/v1.2.1

## [1.2.0] - 2026-11-10

Target: 1.2.0 (DDD P2). See [UPGRADING](UPGRADING.md#to-120).

### Added

- `Nowo\SlackKitBundle\Interaction\ButtonValueCodecInterface` (`sign()`, `signUntil()`, `verify()`) and
  `Nowo\SlackKitBundle\Linking\AccountLinkerInterface` (`linkOf()`, `canFindByEmail()`, `claim()`, `findByEmail()`,
  `sendConfirmation()`, `confirm()`, `unlink()`), implemented by `ButtonValueCodec` / `AccountLinker` (still
  `final readonly`) and registered as public aliases of them. The interaction router, the account linker, the account
  link controller, the Twig extension and the *Confirm* handler now reference the interface ids.

### Documentation

- USAGE: new section "Using it from a DDD / hexagonal application" (where the bundle sits in a hexagonal host, the ports, events and DTOs to build an anti-corruption layer on, and the known limitations).

[1.2.0]: https://github.com/nowo-tech/SlackKitBundle/releases/tag/v1.2.0

## [1.1.0] - 2026-11-08

`linking.rate_limiter_cache_pool` chooses the cache pool of the linking limiters, and a pool that would switch the limits off (`NullAdapter`, or `ArrayAdapter` outside debug/test) fails the build (Beacon C9). Audit: Appendix IL and Delta IL, Pass.

### Added

- `linking.rate_limiter_cache_pool` (default `cache.app`): cache pool service id behind the four linking rate
  limiters, so a host can use `cache.rate_limiter` or a persistent pool in tests instead of redefining
  `nowo_slack_kit.linking.*.storage` services. The default keeps today's storage.
- `LinkingLimiterPoolPass` (audit IL-L2): the linking limiters' pool may not be a `NullAdapter` (any environment) or an
  `ArrayAdapter` (outside debug and test), since either one silently switches the claim, e-mail lookup and
  confirmation limits off. Applies to the default `cache.app` too.

[1.1.0]: https://github.com/nowo-tech/SlackKitBundle/releases/tag/v1.1.0

## [1.0.1] - 2026-11-05

Documentation only: lock DSN env fallback and the replay lock store in tests.

### Documentation

- Lock DSN fallback: `%env(default:REDIS_URL:SLACK_KIT_LOCK_DSN)%` does not work, because Symfony's `default:` env
  var processor takes a container **parameter** name as its fallback. CONFIGURATION ("Lock DSN with a fallback"),
  INSTALLATION and UPGRADING now show the working patterns: a parameter set to `%env(REDIS_URL)%` used as
  `%env(default:slack_kit_lock_dsn_fallback:SLACK_KIT_LOCK_DSN)%`, or `SLACK_KIT_LOCK_DSN=${REDIS_URL}` in `.env`
  (feedback from symfony-beacon adopting 1.0.0).
- Replay lock store in tests: the allowlist is skipped in debug and test, but a `flock` (or `semaphore`) store
  releases each claim as soon as it returns, so replays go through. Hosts are told to override the SlackKit lock
  resource with `in-memory` in the test environment and to call `KernelBrowser::disableReboot()` in tests that send
  the same request twice (a reboot builds a new, empty store). SECURITY residual risks and the demo comment say so;
  FrameworkBundle's default store is documented as `semaphore` (or `flock` without `ext-sysvsem`).

[1.0.1]: https://github.com/nowo-tech/SlackKitBundle/releases/tag/v1.0.1

## [1.0.0] - 2026-11-05

First release of **SlackKitBundle** (independent REQ-SEC-004 audit: appendix FS and its delta, Pass). Slack for Symfony: a hardened Web API client, a signed interactions and Events API ingress with replay
protection, MAC-signed button values (`nsk1`), an interactions router with verified actors, verified account linking,
a Block Kit builder and an optional Notifier chat transport. Built from contract v1.1 (2026-11-05).

### Added

- **Web API client** `Api\SlackApiClient` (`SlackApiClientInterface`): `authTest`, `openDirectMessage`
  (`conversations.open`), `postMessage`, `updateMessage`, `deleteMessage`, `postEphemeral`, `lookupUserByEmail`
  (`users_not_found` → `null`; refused with `EmailLookupDisabledException` unless the installation allows it). Constant
  base URL `https://slack.com/api/`, token only in the `Authorization: Bearer` header, `max_redirects: 0`, idle timeout
  and total duration (`api.timeout` / `api.max_duration`, 1–30 s), response size cap (`api.max_response_bytes`),
  JSON bodies for `chat.*` / `conversations.open` and form bodies for `auth.test` / `users.lookupByEmail`, unfurling
  off by default.
- **Typed errors**: `SlackApiException` (`transport_error`, `response_too_large`, `invalid_response`, `not_installed`, Slack `error` codes matched against
  `^[a-z0-9_]{1,64}$`, else `unknown_error`), `SlackAuthException` (auth errors; dispatches
  `SlackInstallationUnhealthyEvent` at most once per installation per 10 min), `SlackScopeException` (`needed`
  scope), `SlackRateLimitedException` (HTTP 429 or `ratelimited`; `Retry-After` digits only, clamped to
  1…`rate_limit.max_retry_after`, default 30). Messages are `Slack API error: <code>` only and never carry a token.
- **Rate-limit memory**: a 429 is remembered per (installation, method) in `cache.app` until `Retry-After` passes;
  calls in that window fail fast without reaching Slack (no retry storms across workers). Nothing ever sleeps.
- **`IncomingWebhookClient`**: posts to `https://hooks.slack.com/services/…` only (exact host, path pattern), the
  URL held in a `SlackSecret`, no redirects, same timeouts and response cap.
- **Signed ingress** `Ingress\IngressController`: `POST {prefix}/interactions[/{app}]` and, with
  `ingress.events_enabled`, `POST {prefix}/events[/{app}]`; stateless routes. Checks in order: content type (415), body
  cap before parsing (413), header shapes and timestamp window (`max_skew_seconds` ≤ 300, `ClockInterface`), signing
  secret of the app, optional host `SigningSecretResolverInterface` candidates over a bounded `UnverifiedPayloadPeek`
  (at most `max_secret_candidates`; plain `SlackSecret` or workspace-bound `ResolvedSigningSecret`), HMAC v0 with
  `hash_equals`, replay memory, JSON decode, then workspace
  (`team.id` must be the installation's) and `api_app_id` checks (403). Every refusal has a fixed, untranslated body;
  logs carry the app and a reason code only.
- **Replay protection** `Ingress\ReplayGuard`: a verified signature is claimed atomically for 2 × skew with
  `symfony/lock` (`ingress.replay_lock_factory`, default `lock.factory`); Events API `event_id`s are claimed for 1 h
  (a Slack retry answers 200 and is not dispatched; the claim is released when handling fails).
- **Events API**: `url_verification` answered only after signature and replay checks, with a pattern-checked
  challenge; `event_callback` envelopes validated and dispatched as `SlackEventReceivedEvent` (inline, or as
  `HandleSlackEvent` on Messenger with `ingress.async`); `app_uninstalled` and bot `tokens_revoked` mark the
  installation revoked (`SlackInstallationRevokedEvent`); `app_rate_limited` → `SlackEventsRateLimitedEvent`.
- **Store contract test case** `Test\SlackInstallationStoreContractTestCase` for host installation stores.
- **`nsk1` button-value codec** `Interaction\ButtonValueCodec` (contract §6.5, normative): `nsk1.<payload_b64>.<mac_b64>`,
  canonical JSON (keys sorted at every level, no whitespace, UTF-8 and `/` unescaped), HKDF-SHA256 key from
  `kernel.secret` with `info = nowo_slack_kit.button.v1`, MAC = first 16 bytes of HMAC-SHA256 over
  `nsk1\n<action_id>\n<payload_b64>`, base64url without padding, constant-time comparison, strict decoding
  (non-canonical base64url refused), payload ≤ 1400 bytes. Verification order pattern → MAC (current key, then every
  `previous_secrets` key) → schema → `exp` → `t`; reason codes `malformed`, `bad_mac`, `bad_payload`, `expired`,
  `team_mismatch`. The three §6.5.9 test vectors and every listed negative check are part of the test suite and match
  symfony-beacon 1.122.1 byte for byte.
- **Legacy values** `Interaction\LegacyValuePolicy`: unsigned JSON values with `interactions.legacy_value_key`
  mapped through `legacy_action_map`, accepted only while `legacy_until` is in the future, never for a value of the
  `nsk` family; marked `legacy: true` and logged at `info`.
- **Interactions router** `Interaction\InteractionRouter`: routes `block_actions` by `action_id` to services tagged
  with `#[AsSlackActionHandler(action: …, requiresActor: true)]` (`SlackActionHandlerInterface`); two handlers for one
  action fail the container build; unknown action → 400; bad value → 403 and the ephemeral *This button is no longer
  valid*; handler exceptions → 500 with a generic ephemeral (logged with the class only). Other interaction types →
  200 and `SlackInteractionReceivedEvent`. Stable `interactionId` idempotency key.
- **Replies** `SlackInteractionReply` (`done()`, `gone()`, `noAccess()`, `forbidden()`, `withEphemeral()`,
  `replaceOriginal()`, `deleteOriginal()`); the Slack calls run on `kernel.terminate` through `ReplyDispatcher`
  (best effort, failures logged with the error code).
- **Actor contract** `Interaction\ActorResolver` (OTHER REQ-SEC-001): a `SlackActor` only for a verified link of an
  active account in the click's workspace; `requiresActor` handlers otherwise answer 403 *Link and confirm your Slack
  account* with a translated ephemeral.
- **Account linking** `Linking\AccountLinker` (contract §8): typed claim, proven claim by e-mail
  (`users.lookupByEmail`, opt-in), confirmation DM with a signed button (`nowo_slack_kit.link.confirm`, data
  `{acc, c, uid}`, `t` = workspace, `exp` = `linking.confirmation_ttl`), confirm, unlink. Verified links are never
  taken; unverified ones yield after `claim_ttl` (`SlackLinkReleasedEvent` reason `expired`) or to a proven claim
  (`claimed_by_email`). Four fixed-window limiters (`claim_per_account` 10/h, `email_lookup_per_account` 5/h,
  `confirmation_per_account` 3/h, `confirmation_per_target` 3/h); results never reveal the holder.
  Host contracts `SlackIdentityStoreInterface` and `SlackAccountResolverInterface`.
- **Credentials**: `SlackInstallationStoreInterface` with `InstanceSettingsInstallationStore` (section `slack`, keys
  `bot_token`, `signing_secret`, `team_id`, `allow_email_lookup`; secrets encrypted by InstanceSettings) and
  `InMemoryInstallationStore` (tests and demo); `credentials.store` also accepts a service id. Named apps
  (`apps`, `default_app`) with optional `app_id` and `%env()%` signing secrets.
- **Secret value objects** `Security\SlackSecret` (bot token `xoxb-` only, signing secret, webhook URL, derived keys):
  `#[SensitiveParameter]`, `reveal()` only, `[redacted]` for `__toString` / `__debugInfo`, serialisation, JSON and
  `var_export` refused; format errors name the kind, never the value. `SecretRedactor` scrubs tokens, bearer values,
  webhook paths and secret form / query values.
- **Validated ids** `SlackUserId` (`^[UW]…`), `SlackTeamId` (`^[TE]…`), `SlackChannelId` (`^[CDG]…`).
- **Block Kit** `BlockKit\Blocks` (section, header, context, divider, actions, button with style and confirm dialog,
  link button, fields; truncates text with an ellipsis, refuses structure overflows with `BlockKitLimitException`) and
  `BlockKit\Mrkdwn` (`escape()`, `link()` for `https:` URLs only by default, `mention()`).
- **Admin settings section** `Settings\SlackSettingsSection` (InstanceSettings, icon `tabler:brand-slack`): bot token,
  signing secret, workspace id, e-mail lookup; *Test connection* (POST + CSRF, rate limited, `auth.test`, fills the
  workspace id); notices *not configured* / *workspace id missing* plus host notices through
  `SlackSettingsNoticeProviderInterface`.
- **Account link widget** `@NowoSlackKitBundle/account/_link.html.twig` (UiKit card, FormKit forms, CSRF on every POST)
  and `AccountLinkController` (`claim`, `find`, `confirm`, `unlink`; the account comes from the host resolver, never
  from the request). Twig functions `nowo_slack_kit_link_card()` and `nowo_slack_kit_ingress_url(kind, app)`.
- **Notifier chat transport** (optional, `notifier.enabled`): DSN `nowo-slack://default?app=<app>&team=<T…>` without
  credentials, `SlackKitOptions` (channel or user recipient, blocks, thread), message id `channel:ts`.
- **Messenger middleware** `nowo_slack_kit.messenger.rate_limit_retry`: turns a wrapped `SlackRateLimitedException`
  into a `RecoverableMessageHandlingException` with the `Retry-After` delay, at most `rate_limit.max_attempts`
  deliveries (default 3).
- **Events**: `SlackEventReceivedEvent`, `SlackInteractionReceivedEvent`, `SlackActionHandledEvent`,
  `SlackAccountClaimedEvent`, `SlackAccountVerifiedEvent`, `SlackAccountUnlinkedEvent`, `SlackLinkReleasedEvent`,
  `SlackConfirmationRateLimitedEvent`, `SlackInstallationRevokedEvent`, `SlackInstallationUnhealthyEvent`,
  `SlackEventsRateLimitedEvent`. None carries a token, signature or raw body.
- **Translations** in 7 locales (en, es, fr, de, it, pt, nl), domain `NowoSlackKitBundle`.
- **Build-time refusals**: literal (non-`%env()%`) signing secrets and previous secrets, non-literal security switches,
  `linking.enabled` without its two services, `ingress.async` without Messenger, `legacy_value_key` without
  `legacy_until` and a map, a `legacy_until` that is not a strict UTC instant, a legacy action mapped to
  `nowo_slack_kit.link.confirm`, an unknown `default_app`, a missing replay lock factory, and a replay lock store
  that is not an expiring shared store outside debug and test.
- **Demo** `demo/symfony8` (Symfony 8.1, FrankenPHP worker mode, MySQL, port 8086) against an in-app fake Slack.
- Tooling: Makefile, Docker dev stack, CI matrix PHP 8.2–8.5 × Symfony 7.4 / 8.1, coverage gate, PHPStan with
  `nowo-tech/phpstan-frankenphp`, PHP-CS-Fixer, Rector, Igor worker-state audit, translation parity, git-history
  check, semantic PR titles.

### Security (REQ-SEC-004 audit, appendix FS)

The first independent audit (`3a490ab`, rated **Medium**) found one Medium, four Low and twelve Info items. Fixed
before the 1.0.0 tag:

- **FS-M1 (actor spoofing through a host-resolved signing secret).** A click verified with a
  `SigningSecretResolverInterface` secret carries signer-chosen `team.id` / `user.id`, yet resolved a verified actor.
  Such clicks now get **no actor** (`requiresActor` handlers answer 403), legacy unsigned values are refused on them,
  and the built-in link confirmation refuses them. Opt-in `ingress.resolver_actors: true` (default `false`) resolves an
  actor only when the resolver returns the secret as `Ingress\ResolvedSigningSecret($secret, $team)` and the click's
  `team.id` equals that bound workspace; it trusts every holder of every such secret and only restores the actor
  (legacy values and link confirmations stay refused). Regression test from the PoC.
- **FS-L1 (non-atomic replay memory).** `ReplayGuard` now claims with `symfony/lock` (new hard dependency
  `symfony/lock ^7.4 || ^8.0`): `createLock(<sha256>, 2 × skew, autoRelease: false)->acquire(false)`, atomic on Redis
  (`SET NX PX`) and DBAL / PDO (primary-key insert). New key `ingress.replay_lock_factory` (default `lock.factory`);
  `ingress.replay_cache_pool` is **removed**. Outside debug and test only an allowlist of expiring shared stores
  builds (see FSD-L1 below); a store DSN from `%env()%` is checked at runtime.
- **FS-L2 (incoming-webhook URL leaks).** `IncomingWebhookClient` uses its own HttpClient
  (`nowo_slack_kit.webhook_http_client`: `HttpClient::create()`, no logger, not traced by the profiler), and
  `transport_error` exceptions of both clients keep only a `RedactedTransportException` (class and
  `SecretRedactor`-redacted message, no further `previous`).
- **FS-L3 (mrkdwn injection in the fallback text).** `SlackMessage` escapes its fallback `text` (`&`, `<`, `>`) when
  sent, capped at 4 000 characters after escaping (FSD-I4); `rawText: true` opts out for `Mrkdwn`-built text. The
  Notifier subject is escaped the same way.
- **FS-L4 (relative `legacy_until`).** `legacy_until` must be a strict UTC instant (`YYYY-MM-DDTHH:MM:SS` + `Z` or
  `+00:00`, `LegacyValuePolicy::parseUntil()`): a literal in another form fails the build, an `%env()%` value in
  another form turns legacy mode off.
- **FS-I2:** the replay key is the signature alone, so apps sharing a signing secret cannot replay a request once per
  `{app}` route.
- **FS-I3 (documented):** during the legacy window, holders of a main-app incoming-webhook URL can post unsigned legacy
  buttons that verified members may click; time-boxed by `legacy_until` (see [SECURITY.md](SECURITY.md#residual-risks)).
- **FS-I4:** mapping a legacy action to `nowo_slack_kit.link.confirm` fails the build; unsigned legacy confirmations
  are never accepted.
- **FS-I5:** an `event_id` claim is released when handling fails (the endpoint answers 500), so Slack's retries work.
- **FS-I7:** `rate_limit.max_attempts` (default 3, 1–20) bounds the Messenger rate-limit retries (counted with
  `RedeliveryStamp`; the original failure is rethrown at the limit).
- **FS-I8 (documented):** the dev profiler's HttpClient panel and its cURL export show the raw `Authorization` header;
  dev only, webhook calls are not traced.
- **FS-I9:** `SecretRedactor` is used for every `transport_error` `previous`.
- **FS-I11:** a `kernel.secret` under 32 bytes or a known placeholder logs `reason: weak_kernel_secret` when the
  button key is derived (`ButtonValueCodec::deriveCheckedKey()`).
- **FS-I12:** `Test\SlackInstallationStoreContractTestCase` ships for host stores and runs against the in-memory and
  InstanceSettings stores.

#### Delta audit (appendix FS addendum, rated Pass / Low)

- **FSD-L1 (session-scoped lock stores).** The replay lock store check is an **allowlist** of expiring shared stores
  (`ReplayGuard::EXPIRING_STORES`: Redis, Memcached, PDO, Doctrine DBAL, MongoDB, DynamoDB), at build
  (`ReplayLockPass`, DSNs, store classes and `StoreFactory` connection services) and at runtime
  (`ReplayGuard::isSharedStore()` / `isSharedDsn()` for `%env()%` DSNs). `CombinedStore` members are checked
  recursively (every member must pass; an empty one is refused). Refused: local stores, PostgreSQL advisory locks
  (`pgsql+advisory:`, `pgsql+advisory://`, `postgres(ql)+advisory://`, whose locks ignore the TTL), `zookeeper://`
  and any unknown DSN or store class. Plain `pgsql:` / `postgresql://` stay allowed.
- **FSD-I1 (documented):** with `ingress.resolver_actors`, the bound team of a `ResolvedSigningSecret` must come from
  a host-verified source (`auth.test` with the destination's bot token, OAuth, an operator), never from the secret
  holder.
- **FSD-I2 (documented):** SECURITY.md now states that the opt-in never re-enables legacy values or link
  confirmations on resolver-verified clicks.
- **FSD-I3 (documented):** Events API delivery is at-least-once (released claims re-run partial side effects; a slow
  handler failing after Slack's retry was answered loses the event); handlers must be idempotent.
- **FSD-I4:** the fallback text cap applies after escaping and never leaves a cut entity.
- **FSD-I5 (documented):** INSTALLATION, UPGRADING, CONFIGURATION and README recommend a dedicated
  `framework.lock.resources.slack_kit` resource with `ingress.replay_lock_factory: lock.slack_kit.factory` instead
  of changing the app-wide `framework.lock`.

### Interim decisions (aligned with symfony-beacon 1.122.1)

Decided by the owner on 2026-11-05 so that Beacon's adoption is a no-op:

1. **Link confirmations carry no nonce `n`.** Beacon has no nonce store. The bundle binds a confirmation to the claim
   instead: the value data is `{acc, c, uid}` where `c` is the link's claim time (Unix seconds), and a click is
   accepted only when `c` equals the current claim time. Signed confirmations **without** `c` (Beacon 1.122.1 format
   `{acc, uid}`) are accepted only while `interactions.legacy_until` is in the future (never on resolver-verified
   clicks; unsigned legacy confirmations are never accepted)
   (`LegacyValuePolicy::isWindowOpen()`, independent of `legacy_value_key`). This leaves a **replay window**: while
   the legacy window is open and until the value's `exp` (24 h), a `c`-less button from an earlier claim of the same
   Slack id by the same account (for example after unlink and re-claim) still verifies the link. Only the Slack member
   whose id is in the value can use it (clicker = `uid` = current link id, same workspace, active account), and a
   second confirm is a no-op. See [SECURITY.md](SECURITY.md#residual-risks).
2. **`t` (workspace) is added to signed values only when the team id is known**; it is omitted (never `null` or `""`)
   otherwise. When present, the verifier refuses a click from another `team.id`.
3. **Final configuration keys** `nowo_slack_kit.interactions.value_ttl` (ISO-8601 duration, default `P30D`, literal),
   `nowo_slack_kit.interactions.previous_secrets` (list of `%env()%` placeholders, literals fail the build) and
   `nowo_slack_kit.interactions.legacy_until` (strict UTC instant such as `2026-12-05T00:00:00Z`, exclusive, or
   `%env(SLACK_LEGACY_UNTIL)%` parsed at runtime; an empty or malformed value turns legacy off), plus `legacy_value_key` and `legacy_action_map`.

### Notes / deviations from contract v1.1

- Twig namespace is `@NowoSlackKitBundle` (house rule REQ-TWIG-002), not `@NowoSlackKit`.
- Not implemented in 1.0 (keys absent from the configuration tree): `enabled`, `apps.*.client_id` /
  `client_secret` / `bot_scopes`, `credentials.settings_role` (the settings section uses InstanceSettings' own
  access checker), `rate_limit.client_throttle` (no client-side token bucket),
  `messenger.retry_on_rate_limit` (the retry middleware is a service the host adds to its bus; retries follow the
  transport's retry strategy), `oauth.*` (1.1), `blocks.allow_http_links` (a per-call `$allowHttp` argument instead),
  `ui.layout`. The four linking limiters are configured as `linking.<name>.{limit, interval}`.
- A response over `api.max_response_bytes` raises `SlackApiException('response_too_large')` (the contract groups it
  under `transport_error`); a missing or revoked installation raises `not_installed` without calling Slack.
- Added keys not in the contract: `linking.return_route`, `test_connection.{enabled, rate_limit}`.
- `ingress.async` defaults to `false` (the contract says `true` when Messenger is installed); enabling it without
  Messenger fails the build.
- Replay memory uses `symfony/lock` (`ingress.replay_lock_factory`) instead of the contract's PSR-6
  `ingress.replay_cache_pool`, which does not exist; the claim is atomic on Redis and DBAL / PDO stores, and stores
  off the expiring-store allowlist fail the build outside debug and test. `rate_limit.max_attempts` is implemented (default 3).
- `LinkResult` cases are `Claimed`, `Found`, `NotFound`, `InUse`, `Invalid`, `AlreadyVerified`, `Unavailable`,
  `RateLimited`, `ConfirmationSent`, `ConfirmationFailed`, `Unlinked` (the contract's `LINKED` is split into
  `Claimed` / `AlreadyVerified`).
- `InstanceSettingsInstallationStore::markRevoked()` clears the stored bot token (the section has no revocation
  column).
- Clicks verified by a host `SigningSecretResolverInterface` secret get no actor unless `ingress.resolver_actors`
  (audit FS-M1; not in the contract). Ephemeral answers and `chat.update` are skipped for them (the bundle has no bot token for that workspace).
- `SlackInstallationStoreContractTestCase` ships under `Nowo\SlackKitBundle\Test` (contract §7.2).
- Not shipped yet: the HttpLogBundle masked-header registration (§12; the headers to mask are documented in [SECURITY.md](SECURITY.md)), `SlackInstallationSavedEvent`
  (OAuth, 1.1). VarDumper and the profiler show `[redacted]` through `SlackSecret::__debugInfo()`; there is no
  separate caster.

[Unreleased]: https://github.com/nowo-tech/SlackKitBundle/compare/v1.2.1...HEAD
[1.0.0]: https://github.com/nowo-tech/SlackKitBundle/releases/tag/v1.0.0
