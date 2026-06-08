# Phase 3 Implementation Plan

This plan sequences implementation from design docs into safe rollout steps.

## Inputs

- `PHASE3_CLASS_MATRIX.md`
- `PHASE3_DNS_PROVIDER_DECISION.md`
- `PHASE3_FIREWALL_PROFILES.md`
- `PHASE3_EDGE_TOPOLOGY.md`

## Milestone 1: Schema and Policy Extensions

### Goal

Introduce remaining data structures for public DNS + edge + firewall enforcement without breaking current flows.

### Tasks

1. Extend policy data model:
   - add `public_pool_id` and `public_dns_zone` to product policy table.
2. Add provider mapping table for public DNS adapter state (if not present in final form).
3. Add edge route state table:
   - service id, public hostname, backend target, route status.
4. Add firewall action log table (or reuse audit payload convention if sufficient).

### Exit Criteria

- migrations run cleanly on existing installations.
- old provisioning continues with no policy regressions.

## Milestone 2: Public DNS Adapter (Cloudflare v1)

### Goal

Implement real public DNS record lifecycle for `shared_edge` and `dedicated_public` classes.

### Tasks

1. Add provider client wrapper (Cloudflare).
2. Implement idempotent upsert/delete for `A`/`CNAME`/`TXT`.
3. Bind provider to lifecycle events:
   - create -> upsert
   - terminate -> delete
4. Keep internal Technitium DNS path unchanged.

### Exit Criteria

- health check + live create/delete validated in staging zone.
- no secrets exposed in module logs.

## Milestone 3: Shared Edge Routing (Single Node)

### Goal

Route `shared_edge` services through one public edge VM.

### Tasks

1. Stand up edge VM and config management flow.
2. Implement route writer:
   - create route on provision
   - disable/enable on suspend/unsuspend
   - delete on terminate
3. Persist route state in DB.

### Exit Criteria

- pilot product reachable end-to-end via public DNS + edge.
- suspend/unsuspend toggles route state correctly.

## Milestone 4: Firewall Enforcement Stage B->C

### Goal

Move from planned audit events to active rule apply/remove.

### Tasks

1. Implement dry-run diff output per profile (`web_edge`, `general_vps`, `mail_vps`, `custom`).
2. Add active apply/remove in lifecycle hooks for selected products.
3. Preserve rollback switch to planned-only mode.

### Exit Criteria

- rule apply/remove validated on pilot products.
- rollback to planned-only works immediately.

## Milestone 5: Dedicated Public IP Path

### Goal

Enable `dedicated_public` and `hybrid` products with real public IP allocation.

### Tasks

1. Implement public IP lease allocation/release in IPAM.
2. Attach/assign public IP networking profile in create flow.
3. Create public DNS records + reverse DNS workflow binding.

### Exit Criteria

- one dedicated-public pilot product completes full lifecycle.

## Milestone 6: HA Edge Upgrade

### Goal

Add second edge node and failover model.

### Tasks

1. Deploy second edge VM on separate Proxmox node.
2. Add VIP/LB failover front.
3. Keep route config synchronized.

### Exit Criteria

- failover test passes without client-side DNS change.

## Rollout Strategy

1. Pilot only (`n8n`/single web product).
2. Expand to `shared_edge` products.
3. Expand to `private_only` products under strict policy mode.
4. Expand to `dedicated_public` and `hybrid` products.

## Rollback Controls

- Disable product `Enable Policy Engine`.
- Set policy `strict_mode=0`.
- Set policy `enabled=0`.
- Switch firewall enforcement to planned-only.
- Disable public DNS adapter in provider config.

## Validation Pack

- Continue using:
  - `PHASE2_VALIDATION.md`
  - `PHASE2_EXECUTION_LOG.md`
- Add Phase 3 execution log once implementation begins.
