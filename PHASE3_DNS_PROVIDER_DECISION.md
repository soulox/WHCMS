# Phase 3 Public DNS Provider Decision

## Decision

- Primary public DNS provider for first adapter: **Cloudflare**.

## Rationale

- Mature API and token scoping.
- Fast propagation and reliable record lifecycle operations.
- Optional WAF/proxy controls for `shared_edge` services.
- Broad operational familiarity and tooling support.

## Minimum API Scope

Use a dedicated API token with least privilege:

- Zone: specific customer-facing zones only.
- Permissions:
  - `Zone.DNS` (edit)
  - `Zone.Zone` (read)

## Adapter v1 Requirements

- Health check endpoint validation.
- Record lifecycle methods:
  - create/update/delete `A`, `AAAA`, `CNAME`, `TXT`
  - create/update/delete `PTR` only if managed by provider path (else separate reverse DNS workflow)
- Idempotent upsert behavior.
- Safe retries for transient HTTP failures.
- Structured error mapping into `mod_proxmox_audit_events`.

## Non-goals (v1)

- Geo-routing/load-balancing features.
- DNSSEC automation.
- Bulk migration tooling.

## Operational Policy

- Keep internal DNS automation on Technitium unchanged.
- Public DNS adapter only touches configured public zones.
- Never store full provider secret in logs.
