# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## Table of contents

- [[Unreleased]](#unreleased)
  - [Added](#added)
  - [Security (REQ-SEC-004 audit, appendix FS)](#security-req-sec-004-audit-appendix-fs)
  - [Interim decisions (aligned with symfony-beacon 1.122.1)](#interim-decisions-aligned-with-symfony-beacon-11221)
  - [Notes / deviations from contract v1.1](#notes--deviations-from-contract-v11)

## [Unreleased]

Initial import of **SlackKitBundle 1.0.0** (not tagged yet: the independent REQ-SEC-004 audit comes before the
`v1.0.0` tag). Slack for Symfony: a hardened Web API client, a signed interactions and Events API ingress with replay
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
