# AGENTS.md
Guidance for agentic coding tools working in this repository.

## Scope
- Applies to `WHMCS Modules/make/`.
- Components:
  - WHMCS module: `modules/servers/makeproxmox/`
  - Provisioner API: `provisioner/`
  - Deploy-hook service: `provisioner/deploy-hook/`

## Rule Files Check
No repo-level agent rule files were found:
- `.cursorrules`
- `.cursor/rules/`
- `.github/copilot-instructions.md`
If these are added later, follow them as higher priority.

## Runtime and Tooling
- Node.js + npm for provisioner and deploy-hook.
- PHP for WHMCS module.
- Fastify framework.
- ESM modules (`"type": "module"`).

## Install
```bash
cd provisioner && npm install
cd deploy-hook && npm install
```

## Run
Provisioner:
```bash
cd provisioner
npm run dev
# or
npm start
```
Deploy-hook:
```bash
cd provisioner/deploy-hook
npm run dev
# or
npm start
```

## Build / Lint / Test Commands
There is no explicit build step and no ESLint/Prettier config currently.
Use syntax checks + lifecycle integration checks.

### JavaScript syntax checks
All JS in provisioner + deploy-hook:
```powershell
Get-ChildItem -Path ".\\provisioner" -Recurse -Filter *.js | ForEach-Object { node --check $_.FullName }
node --check ".\\provisioner\\scripts\\staging-lifecycle.mjs"
```
Single JS file:
```bash
node --check provisioner/src/proxmoxClient.js
```

### PHP syntax checks
All PHP files in module:
```powershell
Get-ChildItem -Path ".\\modules\\servers\\makeproxmox" -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```
Single PHP file:
```bash
php -l modules/servers/makeproxmox/makeproxmox.php
```

### Integration test
Full lifecycle integration script:
```bash
cd provisioner
npm run test:lifecycle
```
Dry run (no external calls):
```powershell
$env:TEST_DRY_RUN='true'; npm run test:lifecycle
```

### Running a single test/action
No unit test runner exists yet. Use one lifecycle endpoint call as a targeted test:
```bash
curl -X POST "$PROVISIONER_API_URL/v1/jobs/suspend" \
  -H "Authorization: Bearer $PROVISIONER_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"service_id":10001,"client_id":30001,"product_id":20001,"plan_code":"make-starter","external_id":"tenant_10001"}'
```
Then poll job result:
```bash
curl -H "Authorization: Bearer $PROVISIONER_API_TOKEN" "$PROVISIONER_API_URL/v1/jobs/<job_id>"
```

## Required Environment Variables (Provisioner)
- `API_BEARER_TOKEN`
- `WHMCS_CALLBACK_URL`
- `WHMCS_CALLBACK_BEARER_TOKEN`
- `PROXMOX_API_URL`
- `PROXMOX_API_TOKEN_ID`
- `PROXMOX_API_TOKEN_SECRET`
- `PROXMOX_LXC_TEMPLATE_VMID`
- `MAKE_PUBLIC_BASE_DOMAIN`
Important optional:
- `PROXMOX_KVM_TEMPLATE_VMID` (enterprise/qemu)
- `WHMCS_CALLBACK_HMAC_SECRET`
- `MAKE_DEPLOY_HOOK_URL`, `MAKE_LIMITS_HOOK_URL`, `MAKE_BACKUP_HOOK_URL`
- matching `MAKE_*_HOOK_TOKEN` values

## JavaScript Style
- Use ESM `import`/`export`.
- Use single quotes and no semicolons.
- Prefer `const`; use `let` only when needed.
- camelCase for variables/functions; PascalCase for classes.
- Keep route handlers thin; move operations into classes/services.
- Keep env parsing and defaults in `config.js`.
- Prefer small helpers for validation/parsing.
- Use async/await; avoid nested Promise chains.

## PHP Style (WHMCS)
- Keep WHMCS hook function names prefixed with `makeproxmox_`.
- Match existing `array(...)` style.
- Use 4-space indentation.
- Cast inbound values explicitly (`(int)`, `(string)`).
- Return `'success'` for successful module actions.
- Return error strings for WHMCS-visible failures.

## Naming Conventions
- Plan codes: `make-starter`, `make-professional`, `make-enterprise`.
- Tenant IDs: `tenant_<service_id>`.
- Job IDs: `job_<timestamp>_<rand>`.
- External JSON contracts: snake_case fields.
- Internal JS properties may remain camelCase.

## Error Handling + Security
- Validate required input early and fail fast.
- Throw actionable errors; do not silently ignore failures.
- Keep retries in job runner for transient failures.
- Send callback with `error_message` on terminal failure.
- Require bearer auth on non-health endpoints.
- Use timing-safe comparisons for secrets where possible.
- Support optional callback HMAC (`X-MAKE-Signature`).
- Never log secrets/tokens/passwords.

## Change Discipline
- Preserve existing API routes and callback payload fields.
- Keep WHMCS custom field names stable unless migration is planned.
- Update README/docs when env vars, routes, or setup flows change.
- Prefer additive changes; avoid breaking behavior without migration notes.

## Pre-merge Checklist
- JS syntax checks pass.
- PHP lint passes.
- Lifecycle script dry-run passes.
- If credentials exist: run at least one real staging lifecycle action.
- Verify callback updates `External ID`, `Last Job ID`, `Provisioning Status`.
