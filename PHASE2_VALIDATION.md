# Phase 2 Validation Runbook

This runbook validates policy engine Phase 2 behavior (strict mode, guardrails, class planning events) before broad rollout.

## Pre-check

- Addon reactivated so new tables/columns exist.
- At least one enabled IP pool with seeded free leases.
- At least one product policy mapped to that pool.
- Product has `Enable Policy Engine = on`.

## Test A: Strict Mode Off Fallback

1. Set policy `strict_mode = 0`.
2. Temporarily disable pool.
3. Run Module Create.
4. Expected:
   - service still provisions (fallback path)
   - audit includes `policy_resolved`
   - no hard fail due to missing lease

## Test B: Strict Mode On Enforcement

1. Set policy `strict_mode = 1`.
2. Keep pool disabled (or invalid pool id).
3. Run Module Create.
4. Expected:
   - create fails with clear strict-mode message
   - audit includes failed `create`

## Test C: Lease Allocation + Release

1. Enable pool and keep `strict_mode = 1`.
2. Run Module Create.
3. Expected:
   - one lease moves `free -> assigned`
   - `service_state.provision_state = provisioned`
4. Terminate service.
5. Expected:
   - lease returns to `free`
   - service_state row removed
   - terminate audit success

## Test D: Suspend/Unsuspend Planned Events

1. Policy class: `shared_edge` and firewall profile key set.
2. Suspend service, then unsuspend.
3. Expected audit events:
   - `edge_route_disable_planned`, `fw_suspend_planned`
   - `edge_route_enable_planned`, `fw_restore_planned`

## Test E: Pool Guardrail

1. Keep one active assigned lease in pool.
2. Attempt CIDR change or disable pool in addon UI.
3. Expected:
   - blocked with guardrail message

## Test F: Policy Disable Guardrail

1. Keep active `service_state` rows tied to a policy.
2. Attempt to disable policy without force.
3. Expected:
   - blocked
4. Repeat with `Force Disable` checked.
5. Expected:
   - allowed

## SQL Checks

### Table and Column Existence

```sql
SHOW TABLES LIKE 'mod_proxmox_%';
SHOW COLUMNS FROM mod_proxmox_product_policies;
```

### Pool and Lease Health

```sql
SELECT id, pool_key, scope, cidr, enabled FROM mod_proxmox_ip_pools;
SELECT status, COUNT(*) cnt FROM mod_proxmox_ip_leases GROUP BY status;
SELECT * FROM mod_proxmox_ip_leases WHERE status='assigned' ORDER BY updated_at DESC LIMIT 20;
```

### Policy Health

```sql
SELECT id, product_id, resource_type, private_pool_id, service_class, strict_mode, enabled
FROM mod_proxmox_product_policies
ORDER BY product_id;
```

### Service State and Audit

```sql
SELECT * FROM mod_proxmox_service_state ORDER BY updated_at DESC LIMIT 50;

SELECT id, service_id, event_type, status, error_message, created_at
FROM mod_proxmox_audit_events
ORDER BY id DESC
LIMIT 100;
```

### Stale Assigned Leases

```sql
SELECT l.*
FROM mod_proxmox_ip_leases l
LEFT JOIN tblhosting h ON h.id = l.service_id
WHERE l.status='assigned' AND (l.service_id IS NULL OR h.id IS NULL);
```

### Orphan Service State

```sql
SELECT s.*
FROM mod_proxmox_service_state s
LEFT JOIN tblhosting h ON h.id = s.service_id
WHERE h.id IS NULL;
```

## Rollback Toggles

- Product setting: `Enable Policy Engine = off`
- Policy setting: `strict_mode = 0`
- Policy setting: `enabled = 0` (use force-disable when required)
- Keep pool enabled and recover later; policy off prevents enforcement

## Phase 3 Prep

- Choose public DNS provider adapter (`cloudflare` recommended).
- Define service class defaults per product.
- Finalize firewall profile catalog:
  - `web_edge`
  - `general_vps`
  - `mail_vps`
  - `custom`
