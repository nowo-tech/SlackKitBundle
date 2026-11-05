# SlackKitBundle Constitution

## Core Principles

### I. Documented integrator contract
Product behavior lives in `specs/001-baseline/spec.md`, `docs/SPEC-DRIVEN-DEVELOPMENT.md`, and integrator docs (`USAGE.md`, `CONFIGURATION.md`). Demos are illustrative unless promoted in the spec.

### II. Spec-first, test-proven
PHPUnit and PHPStan (and Vitest when frontend exists) are the mechanical proof. Behavioral changes require tests.

### III. 100% code inventory traceability
Every production file under `src/` must appear in `specs/001-baseline/code-inventory.md`. New files require spec updates in the same PR.

### IV. Cursor + Spec Kit
GitHub Spec Kit is initialized with **Cursor Agent** (`cursor-agent`). Skills live in `.cursor/skills/speckit-*`.

### V. Symfony compatibility
Follow declared PHP/Symfony ranges in `composer.json` and README badges.

### VI. Fail closed, secrets never leave in clear
Every Slack request is verified before it is decoded (body cap, HMAC v0, timestamp window, replay memory, workspace and
app checks) and every refusal has a fixed body. Button values are `nsk1` MAC-signed (contract §6.5, frozen). The
*Test connection* and account-link actions are POST + CSRF, access-checked and rate limited; the container refuses
insecure wiring (literal secrets, env placeholders for security switches, legacy mode without a cutoff). Tokens and
signing secrets live in `SlackSecret` objects, travel only in the `Authorization` header and never appear in logs,
flashes, events, Messenger messages or exception messages. Tests, demo and docs use fake values only.

### VII. Worker-safe and bounded
Services are stateless or `ResetInterface`; credentials are read from the installation store on every call. Every
Slack call has an idle timeout, a total duration cap, a response cap and no redirects; the base URLs are constants.

## Governance
Amendments update this file, baseline spec when principles affect behavior, and `CHANGELOG.md` when consumer-visible.

**Version**: 1.0.0 | **Ratified**: 2026-11-05 | **Last Amended**: 2026-11-05
