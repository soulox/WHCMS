# Make WHMCS + Proxmox Project Plan

## Objective

Build and deploy a WHMCS 7.10.2 provisioning solution for managed Make packages on Proxmox, with reliable lifecycle automation, package-aware limits, and safe callback synchronization.

## Scope

- WHMCS server module: `modules/servers/makeproxmox`
- Provisioner API service: `provisioner/`
- Deploy hook service: `provisioner/deploy-hook/`
- Proxmox VM/LXC lifecycle integration
- Make runtime bootstrap and health validation
- Backup orchestration and package-based behavior

## Package Matrix (Source of Truth)

- `make-starter`
  - Price: $19/month
  - Disk: 5 GB
  - Operations/month: 10,000
  - Active Scenarios: 5
  - Backups: Daily
- `make-professional`
  - Price: $49/month
  - Disk: 20 GB
  - Operations/month: 100,000
  - Active Scenarios: 25
  - Backups: Daily
  - Support: Priority
- `make-enterprise`
  - Price: $99/month
  - Disk: 50 GB
  - Operations/month: Unlimited
  - Active Scenarios: Unlimited
  - Backups: Hourly
  - Support: Priority
  - Custom Domain: Enabled

## Recommended Provisioning Model

Use a two-layer model to balance isolation, cost, and operational simplicity:

1. **Starter + Professional on Proxmox LXC**
   - Fast provisioning and lower overhead.
   - Dedicated container per customer (no shared app runtime).
   - Resource quotas via Proxmox limits + volume sizing.
2. **Enterprise on Proxmox KVM VM (or privileged LXC if required)**
   - Stronger isolation and easier custom-domain/network controls.
   - Better fit for heavy usage and advanced support requests.

If you prefer one platform only, use LXC for all tiers first, then migrate Enterprise to KVM after launch if support incidents indicate isolation pressure.

## Architecture

1. WHMCS module receives service lifecycle events.
2. Module sends signed job requests to Provisioner API.
3. Provisioner creates/updates Proxmox guest from template.
4. Deploy hook installs and configures Make runtime.
5. Provisioner posts job outcomes to WHMCS callback endpoint.
6. WHMCS service state/custom fields are updated from callback payload.

## WHMCS Module Design

### Product Custom Fields

- `External ID` (Proxmox VMID/CTID)
- `Node`
- `Instance URL`
- `Provisioning Status`
- `Last Job ID`
- `Backup Policy`
- `Package Key`

### Configurable Options

- Billing cycle (monthly/yearly)
- Optional extra disk add-on
- Optional additional backup retention
- Optional managed custom domain add-on

### Lifecycle Actions

- Create: allocate ID, clone template, boot guest, run bootstrap, return URL.
- Suspend: stop guest and optionally lock ingress.
- Unsuspend: start guest and validate health.
- Change Package: resize resources and update package limits.
- Terminate: snapshot/backup (optional), destroy guest, clear DNS.
- Client/Admin custom actions: Rebuild, Backup Now, Reset App Password.

## Provisioner API Design

### Core Endpoints

- `POST /v1/jobs/provision`
- `POST /v1/jobs/suspend`
- `POST /v1/jobs/unsuspend`
- `POST /v1/jobs/change-package`
- `POST /v1/jobs/backup`
- `POST /v1/jobs/terminate`
- `GET /v1/jobs/{id}`
- `GET /v1/ping`

### Security

- WHMCS -> Provisioner with Bearer token + HMAC signature.
- Callback Provisioner -> WHMCS with separate callback secret.
- Idempotency key: `service_id + action + request_id`.

### Reliability

- Persistent DB for jobs/services (PostgreSQL).
- Durable queue (Redis + BullMQ, RabbitMQ, or SQS).
- Retries with backoff for Proxmox and callback operations.
- Dead-letter handling for failed jobs.

## Proxmox Build Standards

- Golden templates:
  - LXC template for Starter/Professional
  - KVM cloud-init template for Enterprise (recommended)
- Dedicated storage pools with tier-aware quotas.
- Bridge/network policy per tenant for isolation.
- Cloud-init or first-boot script installs:
  - Docker + Compose
  - Make runtime components
  - Monitoring agent
  - Backup agent/hooks

## Package Enforcement Strategy

Apply limits at two levels:

1. **Infrastructure limits** (hard): disk, CPU, RAM, backup schedule.
2. **Application limits** (soft/business): operations/month, active scenarios.

Implementation notes:

- Track monthly operations/scenario counts in provisioner DB.
- Sync counters from runtime API/telemetry at scheduled intervals.
- Trigger alerts at 80/95/100% usage for capped tiers.
- Enforce policy on overage (read-only, notifications, or upsell workflow).

## DNS, TLS, and Domains

- Default tenant URL pattern: `{serviceid}.make.yourdomain.com`.
- Wildcard TLS for default subdomains.
- Enterprise custom domain via CNAME + automated certificate issuance.
- Store domain state in service metadata and validate on each deploy.

## Backup and Restore Plan

- Starter/Professional: daily snapshots + daily app data backup.
- Enterprise: hourly app backups + daily full snapshot.
- Retention by tier (for example: 7/14/30 days).
- Quarterly restore drill and documented RTO/RPO.

## Deployment Plan

### Phase 1: Foundation

- Finalize package-to-resource matrix and enforcement policy.
- Prepare Proxmox templates and token permissions.
- Define callback contract and custom field schema.

### Phase 2: MVP Module + Provisioner

- Implement WHMCS module skeleton and lifecycle handlers.
- Build provisioner job API with queue and worker.
- Implement create/suspend/unsuspend/terminate end-to-end.

### Phase 3: Package + Backup + Domain

- Implement change-package logic with safe resize flow.
- Add backup orchestration and admin-triggered backup action.
- Add wildcard-domain routing and enterprise custom domain flow.

### Phase 4: Hardening

- Add idempotency, retries, dead-letter queue, and audit logs.
- Add monitoring/alerts for job failures and callback failures.
- Add rate-limits and security checks around endpoints.

### Phase 5: Staging Validation and Rollout

- Run full lifecycle tests for each package.
- Roll out to internal accounts first, then public rollout.
- Monitor 24-72 hours before broad enablement.

## Testing Checklist

- [ ] `GET /v1/ping` healthy.
- [ ] Create provisions guest and returns reachable URL.
- [ ] Suspend/unsuspend reflected in Proxmox + WHMCS status.
- [ ] Change package correctly updates resources and policies.
- [ ] Backup job produces artifact and retention cleanup works.
- [ ] Terminate removes guest and updates service metadata.
- [ ] Callback idempotency verified under duplicate delivery.

## Rollback Plan

- Disable new provisioning in WHMCS if severe incident occurs.
- Keep existing tenants running unchanged.
- Roll back provisioner/module to last stable release.
- Replay failed jobs once the fix is deployed.

## Open Items Before Production

- Confirm whether Enterprise starts on KVM or LXC.
- Finalize operations/scenario metering source for enforcement.
- Confirm backup retention durations per package.
- Define overage policy for capped tiers.
- Confirm DNS provider and certificate automation method.

## Suggested Initial Repository Layout

- `modules/servers/makeproxmox/makeproxmox.php`
- `modules/servers/makeproxmox/callback.php`
- `modules/servers/makeproxmox/README.md`
- `provisioner/src/server.js`
- `provisioner/src/queue.js`
- `provisioner/src/proxmoxClient.js`
- `provisioner/src/makeManager.js`
- `provisioner/deploy-hook/src/server.js`
