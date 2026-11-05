# Moving an in-app Slack integration onto SlackKit

A guide for applications that already talk to Slack with their own code (a Web API client, a signature verifier, an
interactions controller, an account-linking flow) and want to replace that code with SlackKitBundle without breaking
the Slack app, the stored credentials or the buttons already posted.

## Table of contents

- [Before you start](#before-you-start)
- [1. Require and register](#1-require-and-register)
- [2. Keep the published URLs](#2-keep-the-published-urls)
- [3. Keep the stored credentials](#3-keep-the-stored-credentials)
- [4. Accounts: identity store and account resolver](#4-accounts-identity-store-and-account-resolver)
- [5. Per-destination signing secrets](#5-per-destination-signing-secrets)
- [6. Click actions become handlers](#6-click-actions-become-handlers)
- [7. Buttons already in Slack](#7-buttons-already-in-slack)
- [8. Sending](#8-sending)
- [9. Listen to the bundle events](#9-listen-to-the-bundle-events)
- [10. UI and translations](#10-ui-and-translations)
- [11. Delete the old code and adapt the tests](#11-delete-the-old-code-and-adapt-the-tests)
- [Checklist](#checklist)

## Before you start

- Ship your own security fixes first (actor checks, rate limits on confirmations, signed button values) and adopt the
  bundle in a later release, so a regression can be bisected.
- If your app already signs buttons, make sure it uses the `nsk1` format (contract §6.5) and the bundle's
  `action_id`s; otherwise treat its buttons as legacy values (step 7).
- Pin the bundle version exactly if that is your dependency policy.

## 1. Require and register

```bash
composer require nowo-tech/slack-kit-bundle
```

Import the routes (`type: nowo_slack_kit`) once and add the ingress paths to a firewall with `security: false`
([INSTALLATION.md](INSTALLATION.md)).

## 2. Keep the published URLs

Customers and admins have pasted your Interactivity (and Events) URL into Slack apps. Keep it:

```yaml
nowo_slack_kit:
    ingress:
        interactions_path: /hooks/slack/interactions
        events_path: /hooks/slack/events     # if you enable events_enabled
```

## 3. Keep the stored credentials

If your credentials live in an InstanceSettings section with the keys `bot_token`, `signing_secret`, `team_id` and
`allow_email_lookup`, point the bundled store at it — no data migration:

```yaml
nowo_slack_kit:
    credentials:
        store: instance_settings
        settings_section: slack
```

Otherwise implement `SlackInstallationStoreInterface` over your storage and set `credentials.store` to its service
id. Make the workspace id mandatory: the ingress refuses clicks from any workspace when it is missing.

## 4. Accounts: identity store and account resolver

Implement `SlackIdentityStoreInterface` on your user repository (Slack id, claim time, verification time; active =
enabled and not anonymised; keep the unique index) and `SlackAccountResolverInterface` (current user → public id).
A single-workspace app can keep its index on the Slack id alone and take the workspace from the installation.

```yaml
nowo_slack_kit:
    linking:
        enabled: true
        identity_store: App\Slack\UserSlackIdentityStore
        account_resolver: App\Slack\CurrentUserAccountResolver
        email_lookup: true            # if you offered "find me by e-mail"
```

The claim TTL, confirmation TTL and the four limiters have the usual defaults (24 h, 24 h, 10/5/3/3 per hour).

## 5. Per-destination signing secrets

If channel messages go through each customer's own Slack app (incoming webhooks) and the click's signing secret is
chosen from the payload, implement `SigningSecretResolverInterface` (bounded peek of the first action's data, host
regex) and set `ingress.signing_secret_resolver`. Destinations keep the shared URL.

Clicks verified with such a secret get **no actor** (any holder of the secret can sign any `team.id` / `user.id`),
no legacy unsigned values and no link confirmation. Destination handlers must therefore either use
`requiresActor: false` and authorise from the signed `nsk1` data and the destination, or you opt in with
`ingress.resolver_actors: true` and yield `ResolvedSigningSecret($secret, $team)` bound to the destination app's
workspace — which trusts every holder of every destination secret ([USAGE.md](USAGE.md#per-destination-signing-secrets)).
The bound workspace must come from a host-verified source (`auth.test` with the destination's own bot token, OAuth,
or an operator), never from a value typed by whoever pasted the destination secret. The opt-in only restores the
actor: legacy values and link confirmations stay refused on those clicks.

## 6. Click actions become handlers

Each action becomes an `#[AsSlackActionHandler(action: 'acme.issue.resolve')]` service. Keep your domain checks
(project access, permissions, project match) inside the handler, using `$interaction->actor->accountId` — never an
administrator override. Return `SlackInteractionReply::done()`, `gone()->replaceOriginal(…)`, `noAccess()`, … The
link confirmation is built in (`nowo_slack_kit.link.confirm`).

## 7. Buttons already in Slack

- **Unsigned JSON values**: set `interactions.legacy_value_key` (the key holding the action name),
  `legacy_action_map` (old name → new `action_id`) and `legacy_until` as a strict UTC instant
  (`2026-12-05T00:00:00Z`; relative strings fail the build). If your app already started a legacy window (for example
  when it began signing values), reuse **the same instant**: the adoption must not reset it. Do **not** map your old
  confirmation action to `nowo_slack_kit.link.confirm` (the build fails): unsigned confirmations are never accepted;
  members simply send a new confirmation.
- **Values your app signed with `nsk1`**: nothing to do while `kernel.secret` is the same; after a rotation add the old
  value to `interactions.previous_secrets` (`%env()%` only).
- **Link confirmations signed without a nonce or claim time** (`{acc, uid}`): accepted while `legacy_until` is in the
  future; see the replay window in [SECURITY.md](SECURITY.md#residual-risks).

## 8. Sending

Point your DM pipeline at `SlackApiClientInterface` with `SlackInstallationKey::default()`. Keep catching
`SlackRateLimitedException` where you already did (same semantics: `retryAfterSeconds`), or add the
`nowo_slack_kit.messenger.rate_limit_retry` middleware. Use `Mrkdwn::escape()` and `Blocks` in your formatters, sign
new buttons with `ButtonValueCodec`, and post channel messages through `IncomingWebhookClient` for host pinning.

## 9. Listen to the bundle events

- `SlackAccountUnlinkedEvent`: purge your per-user Slack data (DM preferences, digests, delivery history).
- `SlackLinkReleasedEvent`: write your audit row for a released squat.
- `SlackInstallationRevokedEvent` / `SlackInstallationUnhealthyEvent`: alert admins.
- Provide settings notices (for example delivery counts) with `SlackSettingsNoticeProviderInterface`.

## 10. UI and translations

Replace your profile partial with `{{ include('@NowoSlackKitBundle/account/_link.html.twig') }}`. Move translation
keys for "link required", "link refused" and "linked" to the bundle domain (override them in your
`translations/NowoSlackKitBundle.*.yaml` if your wording differs); keep your own domain answers ("gone", "no access").

## 11. Delete the old code and adapt the tests

Remove your Web API client and exceptions, signature verifier, interactions controller, the parsing half of your
interaction handler, the app config reader, the settings section, the test-connection controller, the account linker
and its limiters, and the link actions of your account controller. Keep your functional Slack tests (behaviour must
not change), drop unit tests of removed classes, and add tests for your handlers and your secret resolver. Use fake
values only (`xoxb-test-not-a-real-token`, `T00000000`, `U00000001`).

## Checklist

- [ ] Same Interactivity / Events URLs
- [ ] Same credential storage (or a store implementation), workspace id required
- [ ] Identity store + account resolver, unique index kept
- [ ] Handlers with the host's own authorisation
- [ ] `legacy_until` reuses the instant already in force; `previous_secrets` after any secret rotation
- [ ] Unlink / release / revoke listeners
- [ ] `ingress.replay_lock_factory` on a dedicated `framework.lock` resource with an expiring shared store (Redis or a
      DBAL / PDO DSN without `+advisory`); local, session-scoped and unknown stores fail the production build
- [ ] With `ingress.resolver_actors`, the bound team of each `ResolvedSigningSecret` comes from a host-verified source
- [ ] Destination handlers authorise without an actor, or `ingress.resolver_actors` is a documented decision
- [ ] `kernel.secret` random and ≥ 32 bytes
- [ ] Old code deleted, functional tests unchanged and green
