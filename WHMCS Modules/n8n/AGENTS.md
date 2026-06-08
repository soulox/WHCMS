# AGENTS.md

Guidance for coding agents working in this repository.

## Repository Overview

- `modules/servers/n8nproxmox/`: WHMCS 7.10.2 server provisioning module (PHP).
- `provisioner/`: main Provisioner API (Node.js + Fastify, ESM).
- `provisioner/deploy-hook/`: deploy automation hook service (Node.js + Fastify, ESM).
- `PROJECT_PLAN.md` and `GO_LIVE_CHECKLIST.md`: operational plans.

## Agent Rule Sources

- No `.cursorrules` found.
- No `.cursor/rules/` directory found.
- No `.github/copilot-instructions.md` found.
- Therefore, this file is the primary agent instruction source in-repo.

## Environment Assumptions

- OS in current workspace is Windows/PowerShell.
- WHMCS module runs inside WHMCS PHP runtime.
- Node services target modern Node with built-in `fetch` support.

## Install / Run Commands

### Provisioner

- Install deps: `npm install` (run in `provisioner/`)
- Dev mode: `npm run dev`
- Start mode: `npm run start`

### Deploy Hook

- Install deps: `npm install` (run in `provisioner/deploy-hook/`)
- Dev mode: `npm run dev`
- Start mode: `npm run start`

### WHMCS Module

- No build step.
- Deploy by copying `modules/servers/n8nproxmox` into WHMCS.

## Lint / Static Checks

This repo currently uses lightweight syntax checks (no ESLint/PHPCS configured).

### PHP syntax checks

- `php -l "modules/servers/n8nproxmox/n8nproxmox.php"`
- `php -l "modules/servers/n8nproxmox/callback.php"`
- `php -l "modules/servers/n8nproxmox/lib/ApiClient.php"`
- `php -l "modules/servers/n8nproxmox/lib/WhmcsStore.php"`

### JS syntax checks (Provisioner)

- `node --check "src/server.js"`
- `node --check "src/jobRunner.js"`
- `node --check "src/proxmoxClient.js"`
- `node --check "src/n8nManager.js"`

### JS syntax checks (Deploy Hook)

- `node --check "src/server.js"`
- `node --check "src/pctExec.js"`
- `node --check "src/templates.js"`

## Test Commands

There is no automated test suite yet in this repository.

### Current verification approach

- Run syntax checks above.
- Run health endpoints:
  - Provisioner: `GET /v1/ping`
  - Deploy-hook: `GET /health`
- Run integration smoke flow: create -> suspend -> unsuspend -> change-package -> backup -> terminate.

### Single-test guidance (when tests are added)

If using Node built-in test runner, run a single file:

- `node --test path/to/file.test.js`

Run a single test by name pattern:

- `node --test --test-name-pattern="name substring"`

## Code Style: JavaScript (Provisioner + Deploy Hook)

- Module system: ESM only (`import`/`export`, include `.js` extension in local imports).
- Indentation: 2 spaces.
- Semicolons: omit (match existing codebase style).
- Strings: prefer single quotes.
- Trailing commas: allowed where already used; keep consistent with surrounding code.
- Keep functions focused and small; push logic into helpers when a function grows.
- Prefer early returns over deep nesting.
- Use `const` by default; use `let` only when reassignment is required.
- Avoid introducing classes unless existing code in that area already uses classes.

## Code Style: PHP (WHMCS Module)

- Keep compatibility with WHMCS 7.10.2 conventions.
- Use procedural WHMCS module function names (`n8nproxmox_*`) for module entrypoints.
- Use `array(...)` syntax for consistency with current module files.
- Indentation: 4 spaces.
- Keep guard checks at top (`if (!defined('WHMCS')) { ... }`).
- Avoid advanced PHP features that may reduce compatibility in WHMCS environments.

## Imports and File Organization

- Keep imports/requires grouped at top of file.
- In JS, order imports: external packages first, then local modules.
- In PHP, use `require_once __DIR__` relative includes.
- New files should live in existing domain folders (`lib/`, `src/`, `templates/`, `docs/`).

## Types, Validation, and Data Contracts

- Validate all external inputs (HTTP body, headers, webhook payloads).
- Coerce and validate numeric IDs (`service_id`, `vmid`, etc.) before use.
- Treat API payloads as untrusted; fail fast with clear errors.
- Preserve existing API contract keys in snake_case where already established.
- Do not silently change callback payload fields without updating docs.

## Naming Conventions

- JS files: camelCase names where already used (`jobRunner.js`, `n8nManager.js`).
- JS identifiers: `camelCase` for variables/functions, `PascalCase` for classes.
- PHP module entrypoints must retain exact WHMCS naming pattern.
- Config/env keys remain uppercase snake case.

## Error Handling and Logging

- Throw explicit errors with actionable messages.
- In HTTP handlers, return clear 4xx/5xx responses; avoid ambiguous failures.
- Preserve secure logging practices:
  - WHMCS: use `logModuleCall` via safe wrappers.
  - Never log tokens, passwords, auth headers, or secrets.
- For retries, keep operations idempotent where possible.

## Security Requirements

- Keep Bearer token checks constant-time where feasible (`timingSafeEqual` / `hash_equals`).
- Preserve optional HMAC signature verification behavior for callbacks.
- Never hardcode secrets in source.
- Keep `.env.example` files as placeholders only.
- Do not weaken auth in health-protected endpoints.

## Infrastructure-Specific Constraints

- Proxmox operations are asynchronous; always wait for task completion (`UPID` polling).
- Do not implement disk shrink on package downgrade.
- Ensure region-to-node mapping fallback remains deterministic.
- Keep template clone assumptions explicit (`PROXMOX_LXC_TEMPLATE_VMID` required).

## Documentation Requirements

- Update relevant README/docs when changing endpoints, env vars, or payload contracts.
- Keep examples copy-pasteable.
- Reflect any new operational steps in `PROJECT_PLAN.md` or `GO_LIVE_CHECKLIST.md` when applicable.

## Change Checklist for Agents

Before finishing changes:

- Run relevant `php -l` / `node --check` commands.
- Verify no secrets were added.
- Ensure docs reflect behavior changes.
- Keep edits scoped; do not refactor unrelated files.
- Maintain backward compatibility for existing WHMCS/provisioner payloads unless requested.
