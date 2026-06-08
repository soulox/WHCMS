# n8n WHMCS + Proxmox Project Plan

## Objective

Build and deploy a WHMCS 7.10.2 provisioning solution for managed n8n VPS/container packages on Proxmox, with automated lifecycle operations, usage-aware plan controls, and reliable callback synchronization.

## Scope

- WHMCS server module: `modules/servers/n8nproxmox`
- Provisioner API service: `provisioner/`
- Deploy hook service: `provisioner/deploy-hook/`
- Proxmox LXC lifecycle integration
- n8n deployment bootstrap and health validation
- Backup orchestration and plan-based behavior

## Current Implementation Snapshot

- WHMCS module skeleton and lifecycle actions implemented.
- Async callback endpoint implemented with Bearer auth + optional HMAC.
- Provisioner API implemented with job queue (currently in-memory).
- Real Proxmox API operations implemented for LXC lifecycle.
- Deploy hook service implemented for in-container Docker Compose deployment.
- Plan matrix and package mapping implemented.

## Package Matrix (Source of Truth)

- `starter_5g`
  - Disk: 5 GB
  - Active Workflows: 5
  - Executions/month: 2,500
  - Backups: Daily
- `pro_20g`
  - Disk: 20 GB
  - Active Workflows: 25
  - Executions/month: 15,000
  - Backups: Daily
- `scale_50g`
  - Disk: 50 GB
  - Active Workflows: Unlimited
  - Executions/month: 50,000
  - Backups: Hourly
  - Custom Domain: Enabled

## Architecture

1. WHMCS module receives service lifecycle events.
2. Module sends job requests to Provisioner API.
3. Provisioner executes Proxmox tasks and n8n deployment hooks.
4. Provisioner posts job outcomes to WHMCS callback endpoint.
5. WHMCS service state/custom fields are updated from callback payload.

## Deployment Plan

### Phase 1: Pre-Deployment Preparation

- Finalize environment variables for `provisioner` and `deploy-hook`.
- Prepare and validate Proxmox LXC template VMID.
- Validate Proxmox API token permissions.
- Configure DNS strategy for tenant URLs.
- Configure ingress/TLS and network ACLs.

### Phase 2: Staging Deployment

- Deploy WHMCS module into staging WHMCS.
- Configure WHMCS server credentials (Access Hash token, optional HMAC secret).
- Deploy `provisioner` service.
- Deploy `deploy-hook` service.
- Verify end-to-end connectivity:
  - WHMCS -> Provisioner
  - Provisioner -> Proxmox
  - Provisioner -> Deploy-hook
  - Provisioner -> WHMCS callback

### Phase 3: Staging Validation Gate

Run full lifecycle test per plan:

- Create
- Suspend
- Unsuspend
- Change Package
- Backup Now
- Terminate

Validate:

- LXC state in Proxmox matches expected action.
- n8n instance URL is reachable and healthy.
- Callback updates WHMCS custom fields/status correctly.
- Backup artifacts are generated and retention works.

Gate to proceed:

- 100% pass rate on lifecycle tests for all plans.
- No silent failures; all errors are observable in logs.

### Phase 4: Production Hardening

- Replace in-memory store/queue with persistent components:
  - PostgreSQL for tenants/jobs
  - Redis/RabbitMQ/SQS for queueing
- Add callback idempotency (`service_id + job_id + status`).
- Add monitoring and alerting:
  - job failures
  - callback failures
  - provisioning latency
- Move secrets to secure secret manager and rotate initial tokens.
- Perform restore drill for backup validation.

### Phase 5: Production Rollout

- Deploy with controlled rollout (internal accounts first).
- Optionally enable plans gradually.
- Monitor for 24-72 hours before broad enablement.

## Rollback Plan

- Disable new provisioning in WHMCS if severe incident occurs.
- Keep existing tenants running.
- Roll back provisioner/deploy-hook to previous stable release.
- Replay failed jobs once fix is deployed.

## Testing Checklist

- [ ] Provisioner `GET /v1/ping` returns healthy.
- [ ] Deploy-hook `GET /health` returns healthy.
- [ ] Create service provisions LXC and deploys n8n.
- [ ] WHMCS custom fields update (`External ID`, `Last Job ID`, `Instance URL`, `Provisioning Status`).
- [ ] Suspend/Unsuspend actions reflect in Proxmox and WHMCS.
- [ ] Change package applies resource + limits changes.
- [ ] Backup creates dump + retention cleanup works.
- [ ] Terminate removes tenant runtime and updates WHMCS.

## Open Items Before Production

- Persistent job/tenant state (critical).
- Durable queue and worker concurrency controls.
- Callback idempotency and replay safety.
- Quota enforcement integration with n8n runtime data.
- SLO dashboard and alert routing.

## Key Paths

- `modules/servers/n8nproxmox/n8nproxmox.php`
- `modules/servers/n8nproxmox/callback.php`
- `modules/servers/n8nproxmox/README.md`
- `provisioner/src/server.js`
- `provisioner/src/proxmoxClient.js`
- `provisioner/src/n8nManager.js`
- `provisioner/deploy-hook/src/server.js`
