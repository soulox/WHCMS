# Phase 3 Execution Log

Use this log while executing `PHASE3_IMPLEMENTATION_PLAN.md`.

## Session Info

- Date:
- Operator:
- Environment (prod/stage):
- WHMCS host:
- Proxmox node(s):
- Change window:

## Milestone Tracking

### Milestone 1: Schema and Policy Extensions

- Status: NOT STARTED / IN PROGRESS / DONE / BLOCKED
- Notes:

Evidence:

```
<migration output / SQL snapshots>
```

### Milestone 2: Public DNS Adapter (Cloudflare v1)

- Status: NOT STARTED / IN PROGRESS / DONE / BLOCKED
- Notes:

Evidence:

```
<health checks / create-delete traces / audit rows>
```

### Milestone 3: Shared Edge Routing (Single Node)

- Status: NOT STARTED / IN PROGRESS / DONE / BLOCKED
- Notes:

Evidence:

```
<edge route state / endpoint tests>
```

### Milestone 4: Firewall Enforcement Stage B->C

- Status: NOT STARTED / IN PROGRESS / DONE / BLOCKED
- Notes:

Evidence:

```
<dry-run diff / applied rules / rollback tests>
```

### Milestone 5: Dedicated Public IP Path

- Status: NOT STARTED / IN PROGRESS / DONE / BLOCKED
- Notes:

Evidence:

```
<public lease / DNS / reverse DNS / lifecycle output>
```

### Milestone 6: HA Edge Upgrade

- Status: NOT STARTED / IN PROGRESS / DONE / BLOCKED
- Notes:

Evidence:

```
<failover tests / sync state / health checks>
```

## Pilot Product Results

| Product | Class | Create | Suspend | Unsuspend | Terminate | Notes |
|---|---|---|---|---|---|---|
|  |  | PASS/FAIL | PASS/FAIL | PASS/FAIL | PASS/FAIL |  |

## SQL and State Snapshots

```sql
-- policies
SELECT * FROM mod_proxmox_product_policies ORDER BY product_id;

-- leases
SELECT status, COUNT(*) cnt FROM mod_proxmox_ip_leases GROUP BY status;

-- service state
SELECT * FROM mod_proxmox_service_state ORDER BY updated_at DESC LIMIT 100;

-- audits
SELECT id, service_id, event_type, status, error_message, created_at
FROM mod_proxmox_audit_events
ORDER BY id DESC
LIMIT 200;
```

Output:

```
<paste result>
```

## Defects and Follow-ups

1.
2.
3.

## Rollback Actions (if any)

- [ ] Policy engine disabled on impacted products
- [ ] strict mode disabled
- [ ] policy disabled
- [ ] public DNS adapter disabled
- [ ] firewall enforcement switched to planned-only

Details:

```
<exact actions and timestamps>
```

## Final Sign-off

- Outcome: GO / NO-GO
- Approved by:
- Date/time:
