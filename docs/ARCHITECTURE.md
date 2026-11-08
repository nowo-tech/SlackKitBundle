# Architecture

## Table of contents

- [Overview](#overview)
- [Outbound: the Web API client](#outbound-the-web-api-client)
- [Inbound: the signed ingress](#inbound-the-signed-ingress)
- [Interactions: values, router, actor, replies](#interactions-values-router-actor-replies)
- [Account linking](#account-linking)
- [Credentials](#credentials)
- [UI](#ui)
- [Source layout](#source-layout)

## Overview

```
host code ──► SlackApiClientInterface (SlackApiClient) ──► https://slack.com/api/*   (token from the installation store)
          └─► IncomingWebhookClient ─────────────────────► https://hooks.slack.com/services/*

Slack ──► IngressController (/slack/interactions, /slack/events)
            │ body cap → headers + window → secret(s) → HMAC v0 → ReplayGuard → JSON → workspace / app id
            ├─ interactions ─► InteractionRouter ─► ButtonValueCodec / LegacyValuePolicy
            │                        │              ActorResolver ◄── SlackIdentityStoreInterface (host)
            │                        ├─► #[AsSlackActionHandler] handlers (host) / ConfirmLinkHandler (bundle)
            │                        └─► ReplyDispatcher ──(kernel.terminate)──► chat.postEphemeral / update / delete
            └─ events ─────► ReplayGuard (event_id) ─► EventsHandler (inline or Messenger HandleSlackEvent)
                                                        └─► store->markRevoked(), Symfony events
```

The bundle owns transport, verification, routing and the linking state machine. The host owns its accounts, what a
click does, what is sent and when, and (outside the InstanceSettings store) where credentials live.

## Outbound: the Web API client

`SlackApiClient` resolves the installation (`SlackInstallationStoreInterface::find()`; `not_installed` when missing or
revoked), checks the 429 memory (`ClientMemory`), and calls `https://slack.com/api/<method>` with the bot token in the
`Authorization` header, JSON or form body per method (`FORM_METHODS`), `ApiPolicy` timeouts, `max_redirects: 0` and an
`on_progress` size cap. Responses are mapped to typed exceptions; auth errors report the installation unhealthy at
most every 10 minutes; 429 / `ratelimited` are remembered for `Retry-After` seconds per (installation, method).

## Inbound: the signed ingress

`IngressController::interactions()` / `events()` share one verification path (`verify()`): content type, capped body
read, `SignatureVerifier` (header shapes, window on `ClockInterface`, HMAC v0), app secret from `SlackAppRegistry`
(configuration or store), optional `SigningSecretResolverInterface` candidates over `UnverifiedPayloadPeek`, then
`ReplayGuard::claimSignature()` (an atomic, never-released `symfony/lock` key; `ReplayLockPass` hands it the lock
store and, in production, refuses any store off the allowlist of expiring shared stores, `CombinedStore` members
included). After decoding, `checkWorkspace()` maps `team.id` to an installation
key and checks `api_app_id`; resolver-verified clicks skip it, never accept legacy values or link confirmations, and
are parsed with `trustActor: false` (no actor) unless `ingress.resolver_actors` and a `ResolvedSigningSecret` bound
to the click's workspace. Events
also pass `ReplayGuard::claimEvent()` before `EventsHandler` (directly, or through Messenger with `ingress.async`); the
claim is released when handling fails.

## Interactions: values, router, actor, replies

`InteractionRouter::parse()` reads `actions[0]`, verifies a `nsk1` value with `ButtonValueCodec::verify()` (or accepts
a legacy value through `LegacyValuePolicy::resolve()`), computes the `interactionId` and resolves the actor with
`ActorResolver`. `dispatch()` finds the handler in a service locator built by `ActionHandlerPass` (tag
`nowo_slack_kit.action_handler`, from `#[AsSlackActionHandler]`), enforces `requiresActor`, calls the handler, dispatches
`SlackActionHandledEvent` and schedules the reply's Slack calls on `ReplyDispatcher`, which flushes them on
`kernel.terminate` / `console.terminate`.

`ButtonValueCodec` is pure: key derivation (`deriveKey()`, HKDF), canonical JSON, base64url, MAC; current key plus
verify-only keys from `interactions.previous_secrets`.

## Account linking

`AccountLinker` implements claim, find-by-e-mail, send-confirmation, confirm and unlink on top of the host's
`SlackIdentityStoreInterface`, the installation store, the API client, the codec and four `RateLimiterFactory`
instances (`nowo_slack_kit.linking.*`, storage in `linking.rate_limiter_cache_pool`, default `cache.app`). `ConfirmLinkHandler` is the built-in handler of
`nowo_slack_kit.link.confirm` (`requiresActor: false`). `AccountLinkController` exposes the POST actions; the account
id always comes from `SlackAccountResolverInterface`.

## Credentials

`SlackInstallationStoreInterface` returns `SlackInstallation` (key, bot token as `SlackSecret`, team, e-mail lookup
flag, bot user id, revocation) and app signing secrets. `InstanceSettingsInstallationStore` maps the `slack` section;
`InMemoryInstallationStore` is for tests. `SlackAppRegistry` merges configured apps with store secrets.

## UI

`SlackSettingsSection` is an InstanceSettings section (fields, *Test connection* action with a FormKit CSRF-only form,
notices). `TestConnectionController` checks the InstanceSettings access checker, CSRF and a rate limiter.
`SlackKitTwigExtension` provides the account card data and ingress URLs; `@NowoSlackKitBundle/account/_link.html.twig`
renders the card with UiKit. `SlackKitRouteLoader` builds every route from configuration (ingress paths, settings
action, account actions).

## Source layout

| Directory | Responsibility |
| --- | --- |
| `src/Api` | Web API client, incoming-webhook client, message and result value objects, exceptions (incl. `RedactedTransportException`), rate-limit memory |
| `src/BlockKit` | Block Kit builder and mrkdwn helpers |
| `src/Ingress` | ingress controller, signature verifier, replay guard, events handler, resolver interface, resolved secret and peek |
| `src/Interaction` | `nsk1` codec, legacy policy, router, actor resolver, reply dispatcher, handler contract |
| `src/Linking` | account linker, confirm handler, host interfaces, link value object, results |
| `src/Credentials` | installation store interface and stores, installation key, app registry |
| `src/Security` | `SlackSecret`, `SecretRedactor` |
| `src/Id` | validated Slack ids |
| `src/Event` | Symfony events |
| `src/Controller`, `src/Form`, `src/Routing`, `src/Settings`, `src/Twig` | UI: actions, forms, route loader, settings section, Twig functions |
| `src/Notifier` | optional Notifier chat transport |
| `src/Messenger` | rate-limit retry middleware |
| `src/DependencyInjection` | configuration tree, extension, compiler passes (handlers, CSRF, replay lock store) |
| `src/Test` | `SlackInstallationStoreContractTestCase` for host stores (PHPUnit) |
| `src/Resources` | translations (7 locales) and the account card template |
