# Slack Kit Bundle – Symfony 8 demo

Symfony 8.1 application on FrankenPHP (worker mode by default) and MySQL showing `nowo-tech/slack-kit-bundle` on top of
`nowo-tech/instance-settings-bundle`, against an **in-app fake Slack** (no real workspace, token or member):

- the `slack` instance settings section contributed by the bundle (bot token, signing secret, workspace id, e-mail
  lookup; secrets encrypted with DoctrineEncryptBundle) and its **Test connection** action;
- the account link card (`@NowoSlackKitBundle/account/_link.html.twig`) for the demo account: claim a member id, find
  it by e-mail, send the confirmation DM, unlink;
- the fake Slack log: every Web API call the bundle made, and the buttons it posted. *Click* signs a `block_actions`
  request with the fake signing secret and sends it to the bundle's real ingress (`POST /slack/interactions`), so the
  signature, replay, workspace, `nsk1` MAC and actor checks all run;
- a host handler (`App\Slack\PingHandler`, action `demo.ping`) that answers only for a verified actor.

All values are fakes: bot token `xoxb-test-not-a-real-token`, workspace `T00000000`, member `U00000001`.

## Setup

```bash
make up      # containers + composer install, prints "Demo started at: http://localhost:8086"
make setup   # key, schema (doctrine:schema:update — demo only, use migrations in an application), seed
```

## Commands

- `php bin/console app:demo:seed` – seed the fake Slack settings (idempotent)
- `make test` – `lint:container` + `lint:twig`
- `make test-e2e` / `make demo-screenshots` – Playwright (REQ-DEMO-013)
