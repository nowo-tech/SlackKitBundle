# Installation

## Table of contents

- [Requirements](#requirements)
- [Install the package](#install-the-package)
- [Register the bundle](#register-the-bundle)
- [Routes](#routes)
- [Firewall](#firewall)
- [Credentials](#credentials)
- [Create the Slack app](#create-the-slack-app)
- [Account linking (optional)](#account-linking-optional)
- [Messenger and Notifier (optional)](#messenger-and-notifier-optional)
- [Verify](#verify)

## Requirements

- PHP `>=8.2 <8.6`, Symfony `^7.4 || ^8.0` (Symfony 8 needs PHP 8.4+)
- `nowo-tech/ui-kit-bundle` ^2.27 and `nowo-tech/form-kit-bundle` ^2.17 (installed as dependencies)
- For the default credential store and the admin section: `nowo-tech/instance-settings-bundle` (set it up first:
  Doctrine table, encryption key, admin UI — see its installation guide)
- For the account link routes: `symfony/security-bundle`
- `symfony/lock` (installed as a dependency) with an **expiring shared** store in production for the replay memory.
  Give SlackKit its own lock resource and leave the app-wide `framework.lock` alone:

  ```yaml
  framework:
      lock:
          resources:
              default: ['%env(LOCK_DSN)%']              # the app's own locks, unchanged
              slack_kit: ['%env(SLACK_KIT_LOCK_DSN)%']  # redis://… or a DBAL / PDO DSN (no +advisory)
  nowo_slack_kit:
      ingress:
          replay_lock_factory: lock.slack_kit.factory
  ```

  Only an allowlist of expiring shared stores builds when `kernel.debug` is false outside the `test` environment
  (Redis, Memcached, PDO, Doctrine DBAL, MongoDB, DynamoDB). Local stores (`flock` and `semaphore`, the FrameworkBundle
  defaults, `in-memory`, `null`), session-scoped ones (`pgsql+advisory:`, `postgresql+advisory://`, `zookeeper://`) and
  unknown stores fail the build ([CONFIGURATION.md](CONFIGURATION.md#ingress)).
  To fall back to another variable such as `REDIS_URL`, do not write `%env(default:REDIS_URL:SLACK_KIT_LOCK_DSN)%`:
  `default:` takes a container **parameter** name. Define a parameter (`slack_kit_lock_dsn_fallback: '%env(REDIS_URL)%'`)
  and use `%env(default:slack_kit_lock_dsn_fallback:SLACK_KIT_LOCK_DSN)%`, or set `SLACK_KIT_LOCK_DSN=${REDIS_URL}` in
  `.env` ([Lock DSN with a fallback](CONFIGURATION.md#lock-dsn-with-a-fallback)).
  In the `test` environment use `slack_kit: ['in-memory']`, not `flock`: a `flock` claim is released as soon as it
  returns, so replays go through ([Replay lock store in tests](CONFIGURATION.md#replay-lock-store-in-tests)).

## Install the package

```bash
composer require nowo-tech/slack-kit-bundle
composer require nowo-tech/instance-settings-bundle   # recommended
```

## Register the bundle

Flex registers it. Without Flex:

```php
// config/bundles.php
return [
    // …
    Nowo\UiKitBundle\NowoUiKitBundle::class                       => ['all' => true],
    Nowo\FormKitBundle\NowoFormKitBundle::class                   => ['all' => true],
    Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle::class => ['all' => true],
    Nowo\SlackKitBundle\NowoSlackKitBundle::class                 => ['all' => true],
];
```

Configuration is optional; the defaults use the InstanceSettings store, enable the interactions endpoint and keep
linking, events and the Notifier transport off. See [CONFIGURATION.md](CONFIGURATION.md).

## Routes

```yaml
# config/routes/nowo_slack_kit.yaml
nowo_slack_kit:
    resource: .
    type: nowo_slack_kit
```

The loader adds the ingress routes (`POST /slack/interactions`, and `/slack/events` when enabled), the *Test
connection* action and, when linking is enabled, the account routes under `/slack-kit`. Import it once.

## Firewall

The ingress is authenticated by Slack's signature, not by a session. Exclude its paths from your firewalls (before
the main firewall):

```yaml
# config/packages/security.yaml
security:
    firewalls:
        slack:
            pattern: ^/slack/(interactions|events)
            security: false
        main:
            # …
```

If you change `ingress.prefix` or set `interactions_path` / `events_path`, adapt the pattern.

## Credentials

With the default `credentials.store: instance_settings`, open the admin settings and the **Slack** section:

1. Bot token (`xoxb-PLACEHOLDER` style; user, app-level and refresh tokens are refused) and signing secret. Both are
   stored encrypted and never shown again.
2. Press **Test connection**: it calls `auth.test` and fills the workspace id. Clicks are refused until the
   workspace id is set.
3. Tick *Allow "Find me by e-mail"* only if you added the `users:read.email` scope and your privacy notice mentions it.

To keep the signing secret out of the database instead:

```yaml
nowo_slack_kit:
    apps:
        default:
            signing_secret: '%env(SLACK_SIGNING_SECRET)%'   # literals fail the build
```

## Create the Slack app

Create an app at `https://api.slack.com/apps` → *From an app manifest*. Example (placeholder host):

```yaml
display_information:
  name: Acme Notifications
features:
  bot_user:
    display_name: Acme
    always_online: false
oauth_config:
  scopes:
    bot:
      - chat:write          # post and update messages, ephemeral answers
      - im:write            # open DMs (conversations.open)
      # - users:read.email  # only for "Find me by e-mail"
settings:
  interactivity:
    is_enabled: true
    request_url: https://app.example.test/slack/interactions
  event_subscriptions:      # only with ingress.events_enabled: true
    request_url: https://app.example.test/slack/events
    bot_events:
      - app_uninstalled
      - tokens_revoked
  org_deploy_enabled: false
  socket_mode_enabled: false
```

Install it in your workspace, then copy the **Bot User OAuth Token** and the **Signing Secret** into the settings
section. The section intro (and `{{ nowo_slack_kit_ingress_url() }}`) shows the exact Interactivity URL of your app.

## Account linking (optional)

Implement `SlackIdentityStoreInterface` (where your accounts keep the Slack id, claim time and verification time,
with a unique index) and `SlackAccountResolverInterface` (current user → account id), then:

```yaml
nowo_slack_kit:
    linking:
        enabled: true
        identity_store: App\Slack\UserSlackIdentityStore
        account_resolver: App\Slack\CurrentUserAccountResolver
        return_route: app_profile
```

Include `{{ include('@NowoSlackKitBundle/account/_link.html.twig') }}` in the profile page. Details:
[USAGE.md](USAGE.md#account-linking).

## Messenger and Notifier (optional)

- `composer require symfony/messenger`, then `ingress.async: true` to handle Events API callbacks in a worker, and/or
  add the `nowo_slack_kit.messenger.rate_limit_retry` middleware to a bus.
- `composer require symfony/notifier`, then `notifier.enabled: true` and a `nowo-slack://default` chatter transport.

## Verify

```bash
php bin/console debug:router | grep nowo_slack_kit
php bin/console debug:config nowo_slack_kit
```

Then press **Test connection** in the settings section and click a signed button in Slack.
