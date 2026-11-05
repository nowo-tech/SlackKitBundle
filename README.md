# Slack Kit Bundle

[![CI](https://github.com/nowo-tech/SlackKitBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/SlackKitBundle/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/slack-kit-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/slack-kit-bundle) [![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/slack-kit-bundle.svg)](https://packagist.org/packages/nowo-tech/slack-kit-bundle) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net) [![Symfony](https://img.shields.io/badge/Symfony-7.4%20%7C%208.0%20%7C%208.1%2B-000000?logo=symfony)](https://symfony.com) [![GitHub stars](https://img.shields.io/github/stars/nowo-tech/SlackKitBundle.svg?style=social&label=Star)](https://github.com/nowo-tech/SlackKitBundle) [![Coverage](https://img.shields.io/badge/PHP%20coverage-100%25-brightgreen)](#tests-and-coverage)

> ⭐ **Found this useful?** [Install from Packagist](https://packagist.org/packages/nowo-tech/slack-kit-bundle) · Give it a **star** on [GitHub](https://github.com/nowo-tech/SlackKitBundle) so more developers can find it.

**Slack for Symfony, hardened.** A Web API client with pinned hosts, timeouts and typed rate-limit errors; a signed
interactions and Events API ingress with a body cap, a time window and **replay protection**; **MAC-signed button
values** (`nsk1`) so buttons posted through a leaked incoming-webhook URL cannot be forged; an interactions router
whose handlers get a **verified actor** (a confirmed, active account) or none; **verified account linking** with rate
limits; a Block Kit builder with mrkdwn escaping; an admin settings section with *Test connection*; and an optional
Symfony Notifier chat transport.

> 📋 **Compatible with Symfony 7.4+ and 8.x** (PHP 8.2+; Symfony 8.x requires PHP 8.4+)

**FrankenPHP worker mode friendly.** Every service is stateless (`final readonly`) or implements `ResetInterface`;
credentials are read from the installation store on each call and never memoised across requests; the only
per-request buffer (deferred Slack answers) is flushed on `kernel.terminate` and reset by `kernel.reset`. See
[docs/FRANKENPHP-WORKER-AUDIT.md](docs/FRANKENPHP-WORKER-AUDIT.md).

## Table of contents

- [Features](#features)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Requirements](#requirements)
- [Configuration](#configuration)
- [Interim decisions shared with symfony-beacon](#interim-decisions-shared-with-symfony-beacon)
- [Demo](#demo)
- [Development](#development)
- [Documentation](#documentation)
- [Tests and coverage](#tests-and-coverage)
- [License and author](#license-and-author)

## Features

- ✅ **Web API client** — `auth.test`, `conversations.open`, `chat.postMessage` / `chat.update` / `chat.delete`,
  `chat.postEphemeral`, `users.lookupByEmail` (opt-in). Constant base URL `https://slack.com/api/`, token only in
  the `Authorization` header, no redirects, 5 s idle / 10 s total by default, 1 MiB response cap.
- ✅ **Typed errors and rate limits** — `SlackApiException`, `SlackAuthException`, `SlackScopeException`,
  `SlackRateLimitedException` (`Retry-After` clamped). Never sleeps; a 429 is remembered per installation and method
  so other workers do not hammer Slack; optional Messenger middleware retries after `Retry-After`.
- ✅ **Signed ingress** — `POST /slack/interactions` and `POST /slack/events` (paths configurable, per-app variants
  `/{app}`): body cap before parsing, HMAC v0 with `hash_equals`, ≤ 300 s window on a PSR-20 clock, signature replay
  memory, `event_id` de-duplication, workspace and app id checks, fixed refusal bodies.
- ✅ **`nsk1` button values** — `nsk1.<payload>.<mac>`: canonical JSON, HKDF-SHA256 key from `kernel.secret`, 128-bit
  MAC bound to the `action_id`, expiry, optional workspace, verify-only keys after a secret rotation. Byte-compatible
  with symfony-beacon 1.122.1 (the contract's three test vectors are in the test suite).
- ✅ **Interactions router** — `#[AsSlackActionHandler(action: 'acme.issue.resolve')]` services; handlers get a
  verified `SlackInteraction` with a resolved `SlackActor` or none; ephemeral answers and message replacement run
  after the response.
- ✅ **Verified account linking** — typed Slack id or *Find me by e-mail*, confirmation DM with a signed button,
  verification only when the clicker is the claimed member; squatting released after 24 h or by a proven claim;
  four rate limiters; a UiKit account card with CSRF-protected actions.
- ✅ **Credentials** — installation store interface; InstanceSettings-backed store (encrypted bot token and signing
  secret, admin section with *Test connection*) or in-memory store; secrets wrapped in `SlackSecret` (never dumped,
  serialised or logged) and a `SecretRedactor` for log strings.
- ✅ **Block Kit builder** — sections, headers, context, actions, buttons, link buttons, fields; Slack's limits
  enforced (truncate text, refuse structure overflows); `Mrkdwn::escape()` by default.
- ✅ **Optional Notifier transport** — `nowo-slack://default?app=default` (no secret in the DSN).
- ✅ **Translations** — `NowoSlackKitBundle` domain in en, es, fr, de, it, pt and nl.
- ✅ **Fakes only** — tests, fixtures and the demo use an in-app fake Slack; no real workspace, token or secret.

## Installation

```bash
composer require nowo-tech/slack-kit-bundle
```

Register the bundle (Flex does it), import the routes and open the ingress paths in the firewall:

```yaml
# config/routes/nowo_slack_kit.yaml
nowo_slack_kit:
    resource: .
    type: nowo_slack_kit
```

```yaml
# config/packages/security.yaml
security:
    firewalls:
        slack:
            pattern: ^/slack/(interactions|events)
            security: false
```

Full guide, including the Slack app manifest with placeholder URLs and the minimum scopes:
[docs/INSTALLATION.md](docs/INSTALLATION.md).

## Quick start

```php
use Nowo\SlackKitBundle\Api\SlackApiClientInterface;
use Nowo\SlackKitBundle\Api\SlackMessage;
use Nowo\SlackKitBundle\BlockKit\Blocks;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Interaction\ButtonValueCodec;

public function __construct(private SlackApiClientInterface $slack, private ButtonValueCodec $codec) {}

$installation = SlackInstallationKey::default();
$channel      = $this->slack->openDirectMessage($installation, SlackUserId::fromString('U00000001'));
$value        = $this->codec->sign('acme.task.done', ['task' => '42']);

$this->slack->postMessage($installation, $channel, new SlackMessage('Task 42 is due', [
    Blocks::section('Task *42* is due'),
    Blocks::actions([Blocks::button('Mark done', 'acme.task.done', $value, 'primary')]),
]));
```

```php
use Nowo\SlackKitBundle\Interaction\AsSlackActionHandler;
use Nowo\SlackKitBundle\Interaction\SlackActionHandlerInterface;
use Nowo\SlackKitBundle\Interaction\SlackInteraction;
use Nowo\SlackKitBundle\Interaction\SlackInteractionReply;

#[AsSlackActionHandler(action: 'acme.task.done')]
final readonly class TaskDoneHandler implements SlackActionHandlerInterface
{
    public function handle(SlackInteraction $interaction): SlackInteractionReply
    {
        // $interaction->actor->accountId is a verified, active account (requiresActor: true by default)
        return SlackInteractionReply::done();
    }
}
```

Open the admin settings section **Slack** (InstanceSettings), paste the bot token and the signing secret, press
**Test connection** (it fills the workspace id) and paste the Interactivity URL shown in the section intro into the
Slack app. More in [docs/USAGE.md](docs/USAGE.md).

## Requirements

- PHP **8.2+** (`>=8.2 <8.6`; Symfony 8.x requires PHP 8.4+)
- Symfony **7.4**, **8.0** or **8.1** (FrameworkBundle, HttpClient, RateLimiter, Routing, Form, Validator, Security
  CSRF, Clock)
- `nowo-tech/ui-kit-bundle` **^2.27**, `nowo-tech/form-kit-bundle` **^2.17**
- Suggested: `nowo-tech/instance-settings-bundle` (bundled credential store and settings section),
  `symfony/security-bundle` (account link routes), `symfony/messenger` (async Events API, rate-limit retries),
  `symfony/notifier` (chat transport)

## Configuration

Every key, its default and its validation: [docs/CONFIGURATION.md](docs/CONFIGURATION.md). A typical single-workspace
setup:

```yaml
# config/packages/nowo_slack_kit.yaml
nowo_slack_kit:
    credentials:
        store: instance_settings      # bot token + signing secret in the encrypted "slack" section
    ingress:
        events_enabled: true          # app_uninstalled / tokens_revoked
    interactions:
        value_ttl: P30D
        previous_secrets: []          # '%env(OLD_APP_SECRET)%' after a kernel.secret rotation
    linking:
        enabled: true
        identity_store: App\Slack\UserSlackIdentityStore
        account_resolver: App\Slack\CurrentUserAccountResolver
```

## Interim decisions shared with symfony-beacon

These match symfony-beacon 1.122.1, so its adoption of the bundle changes nothing for buttons already posted:

1. **Link-confirm values may omit the nonce `n`** (Beacon has no nonce store). The bundle binds each confirmation to
   the claim with `c` (claim time). Signed confirmations without `c` are accepted only while
   `interactions.legacy_until` is in the future; that leaves a bounded replay window (same account, same Slack id,
   at most 24 h) described in [docs/SECURITY.md](docs/SECURITY.md#residual-risks).
2. **`t` (workspace) is in a signed value only when the team id is known**, and omitted otherwise.
3. **Final keys** `nowo_slack_kit.interactions.{legacy_until, previous_secrets, value_ttl}`.

Details and the remaining differences from contract v1.1: [docs/CHANGELOG.md](docs/CHANGELOG.md).

## Demo

- `demo/symfony8` — Symfony **8.1** on FrankenPHP (worker mode) with MySQL; host port **8086**. The Slack settings
  section, the account link card, signed buttons you can "click" through the real ingress, and an in-app
  **fake Slack** that answers the Web API and records every call. Nothing leaves the container.

![Demo home: account link card and fake Slack log](docs/images/demo/home.webp)

![Slack settings section after Test connection](docs/images/demo/slack-settings.webp)

```bash
make -C demo up-symfony8          # http://localhost:8086
make test-e2e                     # Playwright e2e against the running demo
make demo-smoke                   # boot, HTTP 200, down (REQ-TEST-011)
```

See [docs/DEMO-FRANKENPHP.md](docs/DEMO-FRANKENPHP.md).

## Development

```bash
make up
make install
make test
make cs-check
make phpstan
make igor
make test-coverage-100
make validate-translations
make release-check
```

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md)
- [Templates](docs/TEMPLATES.md)
- [Security](docs/SECURITY.md)
- [Upgrading](docs/UPGRADING.md)
- [Changelog](docs/CHANGELOG.md)
- [Moving an in-app Slack integration onto SlackKit](docs/MIGRATING-FROM-AN-IN-APP-INTEGRATION.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)
- [Release](docs/RELEASE.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)
- [GitHub Spec Kit](docs/SPEC-KIT.md)

### Additional documentation

- [Architecture](docs/ARCHITECTURE.md)
- [PSR evaluation (REQ-CS-007)](docs/PSR.md)
- [Demo (FrankenPHP)](docs/DEMO-FRANKENPHP.md)
- [FrankenPHP worker audit (kernel not reset)](docs/FRANKENPHP-WORKER-AUDIT.md)
- [GitHub Actions CI requirements](docs/GITHUB_CI.md)

## Tests and coverage

- Tests: PHPUnit — unit tests (client on `MockHttpClient` for every error row, signature verifier, replay guard,
  ingress order of checks, `nsk1` codec with the contract test vectors and negative checks, legacy policy, router,
  actor resolver, account linker transitions and limiters, secrets and redaction, Block Kit limits, stores, DI and
  build-time refusals, Notifier transport, Messenger middleware) and kernel integration tests (settings section with
  CSRF and *Test connection*, account card end to end with a signed click on the ingress, ingress routes); Playwright
  e2e on the demo.
- PHP: coverage gate in CI (`composer test-coverage-100`), target 100 %.
- Fakes only: bot token `xoxb-test-not-a-real-token`, team `T00000000`, users `U00000001`…, e-mails `@example.test`.
- TS/JS: N/A · Python: N/A

## License and author

MIT · [Nowo.tech](https://nowo.tech) · [Héctor Franco Aceituno](https://github.com/HecFranco)
