# AGENTS.md

High-signal guide for OpenCode sessions in this repo.

## What this repo actually contains

- Two workstreams live together: (1) WHMCS Proxmox module code in `modules/` + `includes/hooks/`, and (2) infrastructure automation scripts in repo root + `image-pipeline/`.
- There is no Composer/NPM/PHPUnit/CI task runner in this repo; do not invent `composer test`, `npm test`, or `make` flows.

## Real entrypoints (edit here first)

- Server module callbacks: `modules/servers/proxmox/proxmox.php` (large procedural entrypoint used by WHMCS action lifecycle).
- Addon module + schema bootstrap: `modules/addons/proxmox_manager/proxmox_manager.php`.
- Hook bridge: `includes/hooks/proxmox_manager_module_sync.php`.
- Proxmox API wrappers: `modules/servers/proxmox/lib/ApiClient.php`, `modules/addons/proxmox_manager/lib/ApiClient.php`.
- Image pipeline runner: `image-pipeline/build-template.sh` (invoked by other build wrappers).

## Non-obvious constraints that break things

- Never rename WHMCS callback function names like `proxmox_CreateAccount`; WHMCS resolves these by exact function name.
- Keep `defined('WHMCS')` guard and current return contracts (`'success'` string vs error string/array) at module boundaries.
- In hooks, keep `function_exists` guards; this repo relies on safe re-load behavior.
- `proxmox_manager` activation logic in `modules/addons/proxmox_manager/proxmox_manager.php` is idempotent via `Capsule::schema()->hasTable/hasColumn`; preserve that pattern for schema changes.

## Commands you can actually run

- Lint changed PHP file(s): `php -l "path/to/file.php"`
- Example critical lint targets:
  - `php -l "modules/servers/proxmox/proxmox.php"`
  - `php -l "modules/addons/proxmox_manager/proxmox_manager.php"`
  - `php -l "includes/hooks/proxmox_manager_module_sync.php"`
- Validate image pipeline without building templates:
  - `./image-pipeline/build-debian-template.sh --validate-only`
  - `./image-pipeline/build-all-linux-templates.sh --validate-only`

## Testing reality

- No automated test suite exists. Treat focused manual verification as required.
- For WHMCS module edits, smoke-test exactly the touched flow (for example create, suspend, terminate, or client power action) and confirm no Smarty/PHP warnings in UI.
- For image-pipeline edits, run validate-only first; full `packer build` is expensive and environment-dependent.

## Style and safety (repo-specific)

- Follow existing compatibility-first PHP style: procedural callbacks + selective namespaced classes; avoid introducing strict typing or framework-style rewrites in isolated files.
- Keep input normalization/casting style used in module code (`(int)`, `(string)`, `trim`, `strtolower`) before DB/API calls.
- Use `logModuleCall(...)` for operational failures, but never log secrets/tokens/passwords.
- In Smarty templates, preserve escaping (`|escape`) for user/service-derived values.

## Quick done-check before handoff

- Touched PHP files pass `php -l`.
- Callback/hook entrypoint names and signatures are unchanged.
- Any changed runtime path has a matching manual verification note in your handoff.
