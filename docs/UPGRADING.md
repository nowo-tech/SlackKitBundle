# Upgrading

Instructions for moving between versions of `nowo-tech/slack-kit-bundle`. Breaking changes and required host actions
are listed per target version.

## Table of contents

- [Installing 1.0.0](#installing-100)
  - [From scratch](#from-scratch)
  - [Adopting it from an in-app Slack integration](#adopting-it-from-an-in-app-slack-integration)
  - [Values already posted to Slack](#values-already-posted-to-slack)

## Installing 1.0.0

1.0.0 is the first release; there is no earlier version to upgrade from.

### From scratch

Follow [INSTALLATION.md](INSTALLATION.md): require the package, import the routes, exclude the ingress paths from
the firewall, enter the bot token and signing secret in the **Slack** settings section and press *Test connection*.

### Adopting it from an in-app Slack integration

The bundle was extracted from an application integration, and its defaults let such an app adopt it without
touching its Slack app or its database. The full walkthrough is in
[MIGRATING-FROM-AN-IN-APP-INTEGRATION.md](MIGRATING-FROM-AN-IN-APP-INTEGRATION.md). In short:

1. Keep the published Interactivity URL with `ingress.interactions_path` (for example `/hooks/slack/interactions`).
2. Keep the credentials: `credentials.store: instance_settings` with `settings_section` pointing at the existing
   section (keys `bot_token`, `signing_secret`, `team_id`, `allow_email_lookup`). No data migration.
3. Implement `SlackIdentityStoreInterface` and `SlackAccountResolverInterface` on your accounts and enable `linking`.
4. Turn each click action into an `#[AsSlackActionHandler]` service and delete the app's own signature verification,
   parsing and Web API client.
5. Configure the legacy window (below) so buttons already in Slack keep working until they age out.

### Values already posted to Slack

- **Unsigned JSON values** (posted before the app signed its buttons): set `interactions.legacy_value_key`,
  `legacy_action_map` and `legacy_until`. Use the **same** `legacy_until` instant the app already used (for
  symfony-beacon: its 1.122.1 deploy + 30 days), so the adoption does not reset the window.
- **`nsk1` values signed by the app itself** (symfony-beacon 1.122.1 signs with the same format and the same
  `action_id`s): nothing to do as long as `kernel.secret` is unchanged. If it was rotated, list the old value in
  `interactions.previous_secrets` as an `%env()%` placeholder.
- **Link confirmations without `c`** (the 1.122.1 interim format `{acc, uid}`, no nonce): accepted while
  `legacy_until` is in the future; they expire after 24 h anyway. See the replay window in
  [SECURITY.md](SECURITY.md#residual-risks).
- `interactions.value_ttl` (default `P30D`) should match the lifetime the app used for its own values.
