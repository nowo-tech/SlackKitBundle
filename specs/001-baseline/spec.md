# Feature Specification: SlackKitBundle baseline (1.0.0)

**Feature Branch**: `001-baseline`
**Created**: 2026-11-05
**Status**: Baseline (documents the whole product at 1.0.0)
**Input**: Contract v1.1 (`SlackKitBundle 1.0.0 … Contract v1.1 for symfony-beacon`), extraction of an in-app Slack
integration (Web API client, signature verifier, interactions handler, account linker, settings section) into a
reusable kit on top of InstanceSettingsBundle, UiKit and FormKit.

## Summary

`nowo-tech/slack-kit-bundle` (config root `nowo_slack_kit`) guarantees integrators:

- one Web API client (`SlackApiClientInterface`) with a constant base URL, no redirects, timeouts, a response cap,
  header-only tokens and typed errors (`SlackApiException`, `SlackAuthException`, `SlackScopeException`,
  `SlackRateLimitedException`);
- a signed ingress for interactions and Events API callbacks: body cap, HMAC v0 signature, timestamp window, replay
  memory, `event_id` de-duplication, workspace and app checks, `url_verification` only after the signature;
- MAC-signed button values in the normative `nsk1` format (contract §6.5) and a router that dispatches `block_actions`
  to `#[AsSlackActionHandler]` services with a verified actor;
- an account-linking state machine (claim, proven e-mail claim, confirmation DM, signed confirm click, unlink) with
  four rate limiters, on a host-provided identity store;
- a Block Kit builder with mrkdwn escaping, the `slack` InstanceSettings section with *Test connection*, the account
  link card, translations in 7 locales and an optional Notifier chat transport (`nowo-slack://`).

## User Scenarios & Testing

### US-1 — Send a DM through the Web API (P1)

**Given** an installation with a bot token, **when** the host calls `openDirectMessage()` then `postMessage()`,
**then** the calls go to `https://slack.com/api/conversations.open` and `chat.postMessage` with
`Authorization: Bearer` only, JSON bodies, `unfurl_links/media: false`, and return the channel and `ts`.

- **Given** HTTP 429 or `ratelimited`, **then** `SlackRateLimitedException` carries `Retry-After` clamped to
  1…`rate_limit.max_retry_after` (default 30 when absent) and later calls to that method short-circuit until then.
- **Given** `invalid_auth` / `token_revoked`, **then** `SlackAuthException` and one `SlackInstallationUnhealthyEvent`
  per installation per 10 minutes. No exception message or `previous` chain contains the token.

### US-2 — Verify a Slack request (P1)

**Given** a POST to `{ingress.prefix}/interactions` with a valid `X-Slack-Signature` over `v0:<ts>:<body>` and a
timestamp inside `max_skew_seconds`, **then** it is dispatched once; the same signature again → 401 (replay).

- Over the body cap → 413 without reading further; bad content type → 415; bad or missing headers → 401; other
  workspace or app → 403; every refusal has a fixed body that echoes nothing.
- Events API: `url_verification` is answered only after the signature and replay checks; a duplicate `event_id`
  answers 200 without dispatch; `app_uninstalled` / `tokens_revoked` mark the installation revoked.

### US-3 — Route a signed button click (P1)

**Given** a button whose value was produced by `ButtonValueCodec::sign()` (`nsk1.<payload>.<mac>`), **when** a
member clicks it, **then** the router verifies pattern → MAC (current key, then `previous_secrets`) → schema →
`exp` → `t`, resolves the actor and calls the handler of the `action_id`.

- The three §6.5.9 vectors are reproduced byte for byte; every negative check of §6.5.9 is refused with its reason.
- `requiresActor` handlers are called only for a verified, active, same-workspace link; otherwise 403 and the
  ephemeral *Link and confirm your Slack account first*.
- Legacy unsigned JSON values are accepted only with `legacy_value_key`, a mapped action and now < `legacy_until`
  (a strict UTC instant), and never on a request verified with a host-resolved secret.
- A click verified with a host-resolved secret gets no actor (its `team.id` / `user.id` are chosen by whoever holds
  that secret), unless `ingress.resolver_actors: true` and the secret is bound to the click's workspace.

### US-4 — Link a Slack account (P1)

**Given** an active account, **when** it claims `U00000001` and sends the confirmation, **then** a DM carries a
signed *Confirm* button with `d = {acc, c, uid}` (`c` = claim time), `t` = workspace, `exp` = `confirmation_ttl`;
the click by `U00000001` verifies the link and replaces the message with *Linked*.

- A verified link is never taken (`IN_USE`); an unverified one yields after `claim_ttl` or to a proven e-mail claim
  (`SlackLinkReleasedEvent`).
- A confirmation whose `c` differs from the current claim is refused; a value without `c` (Beacon 1.122.1 interim
  format) only while `interactions.legacy_until` is in the future; a nonce `n` is optional and ignored.
- Claim, e-mail lookup, confirmation per account and per target are rate limited; a refusal sends nothing.

### US-5 — Configure Slack in the admin (P2)

**Given** the `slack` section, **when** an admin saves a bot token and signing secret and presses *Test connection*,
**then** `auth.test` runs (POST + CSRF + rate limit), the flash names the workspace and bot, `team_id` is filled when
empty, and the token is never rendered back.

### US-6 — Worker safety (P1)

**Given** a FrankenPHP worker that does not reset the kernel, **then** no token, installation or actor is reused
across requests: services are stateless or `ResetInterface`, igor reports no state, and the replay memory lives in a
shared lock store (a local store fails the build outside debug and test).

## Requirements

| ID | Requirement |
| --- | --- |
| FR-DI-001 | Bundle class, extension alias `nowo_slack_kit`, compiler passes for handlers and CSRF |
| FR-DI-002 | Strict configuration tree (contract §14) and build-time refusals (literal secrets, env booleans, legacy without cutoff, linking without services, async without Messenger) |
| FR-DI-003 | Route loader `nowo_slack_kit` for ingress, *Test connection* and account routes |
| FR-API-001 | Web API client rules of contract §4 |
| FR-API-002 | Validated Slack id value objects (`U/W`, `T/E`, `C/D/G`) |
| FR-API-003 | Rate-limit memory and the opt-in Messenger retry middleware |
| FR-SEC-001 | `SlackSecret` value objects and `SecretRedactor` (contract §7.3, §12) |
| FR-CRED-001 | Installation store interface, InstanceSettings and in-memory stores |
| FR-CRED-002 | `SlackInstallationStoreContractTestCase` for host stores (audit FS-I12) |
| FR-ING-001 | Ingress order of checks, atomic replay protection on `symfony/lock` (local stores refused outside debug and test), Events API with claim release on failure, no actor for resolver-verified clicks unless `ingress.resolver_actors` and a bound matching workspace (contract §5, audit FS-M1, FS-L1, FS-I5) |
| FR-INT-001 | `nsk1` codec, legacy policy, router, actor resolver, replies (contract §6) |
| FR-LINK-001 | Linking state machine, limiters and the confirm handler (contract §8) |
| FR-BK-001 | Block Kit builder and mrkdwn escaping (contract §9) |
| FR-EVT-001 | Events of contract §13, none carrying a secret |
| FR-NOT-001 | Optional Notifier chat transport (contract §11) |
| FR-UI-001 | Account link and *Test connection* controllers (POST + CSRF) |
| FR-UI-002 | `slack` settings section and host notice providers |
| FR-UI-003 | Account link card and Twig functions |
| FR-I18N-001 | Domain `NowoSlackKitBundle` in en, es, it, fr, pt, de, nl with key parity |

## Success criteria

- PHPUnit line coverage 100 % (gate ≥ 99 %), PHPStan level 8 with empty `ignoreErrors`, igor clean, Rector clean.
- The §6.5.9 vectors match Beacon 1.122.1 byte for byte.
- The demo (FrankenPHP worker, fake Slack) passes the Playwright happy path.
