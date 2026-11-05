# Slack Kit Bundle demos

## Symfony 8 (FrankenPHP + MySQL)

```bash
cd demo/symfony8
cp .env.example .env   # if needed
make up      # prints "Demo started at: http://localhost:8086"
make setup   # encryption key, schema, fake Slack settings
```

- App: http://localhost:8086 (account link card, fake Slack log, simulated signed clicks)
- Slack settings: http://localhost:8086/admin/settings/sections/slack (bot token, signing secret, workspace id,
  "Test connection")
- Default `FRANKENPHP_MODE=worker` (change in `.env` and recreate)
- `make test-e2e` / `make demo-screenshots` (Playwright, REQ-DEMO-013)

The demo opens the settings UI without login (`nowo_instance_settings.security.allow_unauthenticated: true`, accepted
only because the demo runs with `kernel.debug`), always acts as one demo account, and sends every Slack call to an
in-app fake. Never copy that to production.

See [DEMO-FRANKENPHP.md](../docs/DEMO-FRANKENPHP.md).
