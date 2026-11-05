# Security

How SlackKitBundle protects the Slack integration of a Symfony app, what the host must do, and the risks that remain.
Report vulnerabilities as described in [.github/SECURITY.md](../.github/SECURITY.md).

## Table of contents

- [Audit status](#audit-status)
- [Threat model and controls](#threat-model-and-controls)
- [Ingress: order of checks](#ingress-order-of-checks)
- [Button values (nsk1)](#button-values-nsk1)
- [Account linking](#account-linking)
- [Secrets and logging](#secrets-and-logging)
- [Installation store contract](#installation-store-contract)
- [Residual risks](#residual-risks)
- [Host responsibilities](#host-responsibilities)
- [Testing with fakes only](#testing-with-fakes-only)

## Audit status

The `v1.0.0` tag waits for an **independent REQ-SEC-004 audit** whose scope is the threat table below (contract §15),
recorded as an appendix of `BUNDLES_SECURITY_ANALYSIS.md`. Until then the code on `main` is a release candidate.

## Threat model and controls

| Area | Threat | Control |
| --- | --- | --- |
| Forged interaction | An attacker POSTs a fake click to the public endpoint | HMAC v0 over the raw body with the app's signing secret, `hash_equals`, 401 with a fixed body |
| Replay | A captured signed request is sent again within the window | Signature memory for 2 × `max_skew_seconds` (`ReplayGuard`); `event_id` de-duplication for 1 h; window ≤ 300 s on `ClockInterface` |
| Stale request | An old request replayed after the memory TTL | The timestamp window refuses it before the memory matters |
| Forged buttons via a leaked incoming webhook | A message with buttons posted to a channel, clicked by a real user, signed by Slack | `nsk1` values MAC-signed with a key derived from `kernel.secret`, bound to the `action_id`, with expiry and optional workspace; legacy mode time-boxed by `legacy_until` |
| Cross-workspace / cross-app click | A click from another workspace or app that only shares the URL | `team.id` must be the installation's workspace (a missing workspace id refuses every click) and `api_app_id` must match `apps.*.app_id` when set; `t` inside signed values |
| Actor impersonation | Typing someone else's Slack id; a suspended account keeps acting | Actor = verified link + active account + same workspace (`ActorResolver`); handlers authorise with the actor's own access |
| Id squatting | An unverified typed id blocks its owner | Claims expire after `claim_ttl` (24 h), an e-mail-proven claim releases them at once, verified links are never released |
| DM harassment / tier exhaustion | Unlimited confirmation DMs to any member | `confirmation_per_account` and `confirmation_per_target` limiters (3/h each); a refusal sends nothing |
| Linking enumeration | Learning which account holds a Slack id | `IN_USE` names nobody; `claim_per_account` limiter (10/h) |
| Confirmation hijack | Clicking someone else's confirmation, or an old one | Account + value id + clicker + current link id + workspace + claim time `c` + expiry + active account must all match (see [residual risks](#residual-risks) for values without `c`) |
| Open reflector | `url_verification` echoing an unsigned challenge | The challenge is answered only after the signature and replay checks and must match `^[A-Za-z0-9_-]{1,128}$` |
| DoS on ingress | Huge or many bodies | `Content-Length` and a capped read before any parsing (413); cheap header checks first; at most `max_secret_candidates` secrets tried; put a proxy rate limit in front |
| Secret selection by unverified input | The payload picks a secret; the attacker probes | Bounded `UnverifiedPayloadPeek` exposing only host-declared fields matching a regex; candidate cap; identical 401 for every failure |
| SSRF | The client pointed elsewhere | Constant `https://slack.com/api/`; `IncomingWebhookClient` accepts only `https://hooks.slack.com/services/…`; `max_redirects: 0` |
| Token leak | Token in logs, exceptions, profiler, queues, URLs | `SlackSecret` value objects, `SecretRedactor`, `Authorization` header only, no secrets in Messenger messages or events |
| Token at rest | A database dump reveals tokens | Store contract (encryption at rest); the InstanceSettings store encrypts secrets |
| Revoked token | The app keeps using a dead token | `app_uninstalled` / `tokens_revoked` mark the installation revoked; auth errors dispatch `SlackInstallationUnhealthyEvent` |
| mrkdwn injection | `<!channel>` or fake links in user text | `Mrkdwn::escape()` on builder text by default; links built only from checked parts |
| CSRF | Account link POSTs, *Test connection* | FormKit CSRF tokens on every state-changing POST (REQ-SEC-005); the ingress is CSRF-free because it is signed |
| Admin UI authorisation | Low-privilege access to the settings | InstanceSettings access checker on the section and *Test connection* (deny by default) |
| Worker state | Token, installation or actor reused across requests | Stateless services; the reply buffer is reset on `kernel.reset`; Igor audit (REQ-CS-008) |
| Personal data | Slack ids and e-mails sent to Slack | No bundle tables for people; e-mail lookup is opt-in; the host discloses it and handles export / erasure |

## Ingress: order of checks

1. Content type (`application/x-www-form-urlencoded` for interactions, `application/json` for events), else 415.
2. `Content-Length` and a capped read of the body (`ingress.max_body_bytes`), else 413. Nothing is parsed before.
3. `X-Slack-Request-Timestamp` (digits) and `X-Slack-Signature` (`v0=` + 64 hex), and the timestamp window, else 401.
4. The app's signing secret; for the default interactions route, then the host resolver candidates.
5. `v0=` HMAC-SHA256(secret, `v0:<ts>:<raw body>`) with `hash_equals`, else 401.
6. Replay memory (an atomic-enough check-then-set on the pool), else 401 with the same body.
7. JSON decode (depth 32), else 400.
8. Workspace and app id, else 403.
9. Routing; button value verification (§ below), else 403.

Logs carry the route, the app name and a reason code (`headers`, `signature`, `replay`, `team`, `app_id`), never a
body, header or signature.

## Button values (nsk1)

- Key: HKDF-SHA256(`kernel.secret`, salt empty, info `nowo_slack_kit.button.v1`, 32 bytes), held in a `SlackSecret`.
  Rotating `kernel.secret` invalidates posted buttons unless the old value is listed in
  `interactions.previous_secrets` (verify-only, `%env()%` only).
- MAC: first 16 bytes (128 bits) of HMAC-SHA256 over `nsk1\n<action_id>\n<payload_b64>`, compared with `hash_equals`
  on bytes; all keys are tried without an early exit. Forgery is only possible online, one Slack-signed click at a
  time, with a 2⁻¹²⁸ chance per try.
- Check order: pattern → MAC → payload schema (unknown keys refused) → `exp` → `t`. Every failure gives the same 403
  and ephemeral, logged with a reason code only.
- Values are visible to everyone in the channel: they are integrity-protected, not confidential.

## Account linking

- A typed id or an e-mail match is only a claim. Verification needs a click on the signed confirmation button by the
  claimed member.
- The confirmation is bound to the claim by `c` (claim time): a re-claim, an unlink and re-claim, or a squat release
  makes every older button useless. Confirming twice is a no-op. No nonce store is needed.
- Limiters: claim 10/h per account, e-mail lookup 5/h per account, confirmation 3/h per account and 3/h per target
  Slack id. A refusal sends nothing.
- Results never reveal the account that holds an id.

## Secrets and logging

- `SlackSecret` (bot token, signing secret, webhook URL, derived keys): `#[SensitiveParameter]` constructors,
  `reveal()` is the only accessor; `__toString()` and `__debugInfo()` give `[redacted]` (VarDumper and the profiler
  use it); `serialize()`, `json_encode()` and `var_export()` throw. Format checks name the kind of secret, never the
  value. Bot tokens must be `xoxb-`; user (`xoxp-`), app-level (`xapp-`) and refresh tokens are refused.
- Allowed log context: `app`, `team_id`, `installation`, `method`, `error`, `status`, `retry_after`, `action_id`,
  `reason`, `account_id`. Never tokens, secrets, signatures, raw bodies, payload JSON, message text, e-mails or webhook
  URLs.
- `SecretRedactor::redact()` / `redactArray()` scrub `xox?-…`, `xoxe…`, `xapp-…`, `Bearer …`,
  `hooks.slack.com/…` paths and `client_secret=` / `signing_secret=` / `token=` / `code=` / `state=` values.
- Exception messages are `Slack API error: <code>`; transport exceptions are kept as `previous` and never carry the
  token (it lives only in a request header).
- If you log HTTP traffic (for example with HttpLogBundle), mask the `Authorization` and `X-Slack-Signature` headers.

## Installation store contract

Host stores (`SlackInstallationStoreInterface`) must:

1. encrypt tokens and secrets at rest with an AEAD and a key that is not stored with the database; never write
   plaintext to a column, cache, log, queue or dump;
2. return `SlackSecret` objects, never strings;
3. scope reads: a key returns only its own installation (no "first match");
4. make `markRevoked()` idempotent;
5. clear the verification when a link's Slack id changes (identity store).

`InstanceSettingsInstallationStore` relies on InstanceSettings' encrypted secrets; `markRevoked()` clears the stored
bot token.

## Residual risks

- **Replay memory is best effort** on cache adapters without an atomic add: two workers can both accept the same
  request in the same instant. With an `ArrayAdapter` (or a per-host filesystem pool) the memory is per process or
  per host; use a shared pool (Redis, PDO) in production (the bundle logs a warning, `reason: array_pool`, for an
  `ArrayAdapter` in `prod`). Handlers must be idempotent anyway.
- **Two replay memories during an adoption.** A host that already remembered signatures in its own store does not
  share that memory with the bundle, so a request captured in the minutes before the deploy could be replayed once
  after it. Accepted because handlers are idempotent.
- **Slack user ids are not secret.** The limiters, not secrecy, protect linking.
- **Legacy unsigned values** are accepted while `interactions.legacy_until` is in the future (actor and ingress
  checks still apply). Set it to a fixed instant and let it pass.
- **Link confirmations without `c` (interim decision, aligned with symfony-beacon 1.122.1).** Beacon has no nonce
  store, so its 1.122.1 confirmation buttons carry `{acc, uid}` without a nonce `n` and without `c`. The bundle
  accepts such signed values only while `legacy_until` is set and in the future. During that window a `c`-less value
  is **not bound to one claim**: until its `exp` (`confirmation_ttl`, 24 h), a button from an earlier claim of the
  **same** Slack id by the **same** account — for example one sent before an unlink and re-claim — still verifies
  the current link. The exposure is narrow: the clicker must be the Slack member in the value, who must also be the
  link's current Slack id in the same workspace, the account must be active, and a second confirm is a no-op, so the
  worst case is that the rightful member confirms with an older button. Request-level replay of the click is refused
  by `ReplayGuard`; value replay is bounded by `exp` and by `legacy_until`. New confirmations always carry `c`.
- **Signed values are bearer data for the clicker's workspace.** A valid value can be clicked many times until `exp`
  by anyone who can see the message; handlers must re-check the actor's access and be idempotent.

## Host responsibilities

- Put the ingress paths under a firewall with `security: false` and a proxy rate limit.
- Use a shared `ingress.replay_cache_pool` in production.
- Keep `team_id` set (the settings notice warns when it is missing; clicks are refused without it).
- Authorise every handler with the actor's own access; keep handlers idempotent.
- Keep incoming-webhook URLs and per-destination signing secrets encrypted.
- Disclose what goes to Slack (DMs, e-mail lookup) in the privacy notice; export and erase the Slack columns of your
  accounts with the rest of the personal data.

## Testing with fakes only

No test, fixture, demo, screenshot or document uses a real Slack workspace, app, token, signing secret, user id or
e-mail. Fixture values are obviously fake and do not match secret-scanner shapes: bot token
`xoxb-test-not-a-real-token`, signing secret `testsigningsecretnotreal00000000`, team `T00000000`, users `U00000001`…,
channels `C00000001` / `D00000001`, app `A00000000`, e-mails `@example.test`. Documentation examples use placeholders
such as `xoxb-PLACEHOLDER`. Recorded Slack responses are hand-written from Slack's public documentation; signatures
are computed at test time. No test reaches the network (`MockHttpClient`); the demo answers the Web API with an
in-app fake Slack. `FixturesContainNoRealSecretsTest` scans `src/`, `tests/`, `docs/`, `demo/` and the README for real
token shapes (`xox[abpre]-<digits>-`, `xapp-`, real-looking incoming-webhook URLs, 32-hex values next to "signing")
and fails the build. Real secrets come only from the owner and never enter the repository.
