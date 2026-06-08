# Phase 2 Execution Log

Use this log while running `PHASE2_VALIDATION.md` so pass/fail evidence is captured consistently.

## Session Info

- Date:
- Operator:
- Environment (prod/stage):
- WHMCS host:
- Proxmox node(s):
- Addon version:
- Server module version:

## Pre-check

- [ ] Addon reactivated successfully
- [ ] New tables/columns verified
- [ ] Test pool exists and enabled
- [ ] Test policy exists and enabled
- [ ] Product has `Enable Policy Engine = on`

Evidence:

```
<paste SQL/UI evidence>
```

## Test A: Strict Mode Off Fallback

- Result: PASS / FAIL
- Notes:

Evidence:

```
<paste relevant WHMCS/API/audit output>
```

## Test B: Strict Mode On Enforcement

- Result: PASS / FAIL
- Notes:

Evidence:

```
<paste relevant WHMCS/API/audit output>
```

## Test C: Lease Allocation + Release

- Result: PASS / FAIL
- Notes:

Evidence:

```
<paste lease/service_state/audit rows>
```

## Test D: Suspend/Unsuspend Planned Events

- Result: PASS / FAIL
- Notes:

Evidence:

```
<paste planned event rows>
```

## Test E: Pool Guardrail

- Result: PASS / FAIL
- Notes:

Evidence:

```
<paste UI error/guardrail message>
```

## Test F: Policy Disable Guardrail

- Result: PASS / FAIL
- Notes:

Evidence:

```
<paste UI error and force-disable behavior>
```

## SQL Health Snapshot

```sql
SELECT status, COUNT(*) cnt FROM mod_proxmox_ip_leases GROUP BY status;
SELECT * FROM mod_proxmox_service_state ORDER BY updated_at DESC LIMIT 50;
SELECT id, service_id, event_type, status, error_message, created_at
FROM mod_proxmox_audit_events ORDER BY id DESC LIMIT 100;
```

Output:

```
<paste query output>
```

## Defects / Follow-ups

1.
2.
3.

## Rollback Actions Taken (if any)

- [ ] `Enable Policy Engine` disabled on product(s)
- [ ] `strict_mode` set to `0`
- [ ] policy disabled
- [ ] other:

Details:

```
<paste exact actions>
```

## Sign-off

- Validation outcome: GO / NO-GO
- Approved by:
- Date/time:
