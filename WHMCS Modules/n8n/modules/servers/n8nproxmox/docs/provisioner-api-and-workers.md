# Provisioner API and Worker Flow

This document is a concrete blueprint for the external Provisioner service that WHMCS calls.

## 1) Service components

- `api` - receives WHMCS module requests, validates plan, enqueues jobs.
- `queue` - durable queue (Redis + BullMQ / RabbitMQ / SQS).
- `proxmox-worker` - creates/resizes/suspends/terminates tenant runtime.
- `n8n-worker` - configures n8n app, checks health, runs upgrades.
- `usage-worker` - enforces workflow/execution limits.
- `backup-worker` - snapshots and DB dumps based on plan policy.
- `callback-client` - posts job result/status back to WHMCS callback endpoint.

## 2) Suggested API endpoints

All API calls require `Authorization: Bearer <token>`.

### Health

- `GET /v1/ping`
  - Response: `{ "ok": true, "version": "2026.04" }`

### Job queueing

- `POST /v1/jobs/provision`
- `POST /v1/jobs/suspend`
- `POST /v1/jobs/unsuspend`
- `POST /v1/jobs/terminate`
- `POST /v1/jobs/change-package`
- `POST /v1/jobs/restart`
- `POST /v1/jobs/backup`

Request (shared baseline):

```json
{
  "action": "create",
  "service_id": 123,
  "client_id": 456,
  "product_id": 7,
  "plan_code": "starter_5g",
  "region": "default",
  "version_channel": "stable",
  "backup_retention_days": 7,
  "hostname": "customer-hostname.tld",
  "email": "customer@example.com",
  "firstname": "Jane",
  "lastname": "Doe",
  "external_id": "",
  "custom_domain": ""
}
```

Response:

```json
{
  "job_id": "job_01JXYZ",
  "external_id": "tenant_abc123",
  "queued_at": "2026-04-02T10:00:00Z"
}
```

### Tenant read endpoints

- `GET /v1/tenants/{external_id}/status`
  - Response fields: `status`, `instance_url`, `proxmox_node`, `container_id`, `version`
- `GET /v1/tenants/{external_id}/usage`
  - Response fields: `executions_used`, `executions_limit`, `active_workflows`, `active_workflow_limit`, `storage_used_gb`, `storage_limit_gb`

## 3) Plan matrix source of truth

Store plan matrix in Provisioner config (DB/config file), not in WHMCS:

- `starter_5g`: 5GB disk, 5 workflows, 2500 executions/month, daily backups
- `pro_20g`: 20GB disk, 25 workflows, 15000 executions/month, daily backups
- `scale_50g`: 50GB disk, unlimited workflows, 50000 executions/month, hourly backups, custom domain

Each plan should also include CPU/RAM and backup retention policy.

## 4) Worker flow by action

### `create`

1. Validate plan + capacity + region.
2. Allocate tenant record (`external_id`).
3. Clone Proxmox LXC/VM template.
4. Set CPU/RAM/disk/network/firewall tags.
5. Provision Docker stack (n8n + postgres + redis optional).
6. Generate `N8N_ENCRYPTION_KEY`, DB creds, app creds.
7. Configure ingress/TLS and default subdomain.
8. Register backup schedule.
9. Save tenant metadata.
10. Callback WHMCS with `status=active`, `external_id`, `instance_url`, `job_id`.

### `change-package`

1. Load current tenant state.
2. Apply compute/storage resize in Proxmox.
3. Update plan limits and backup frequency.
4. If custom domain entitlement changed, enforce route/policy.
5. Callback WHMCS with `status=active` and new limits snapshot.

### `suspend` / `unsuspend`

- Suspend: stop container or ingress deny policy + pause scheduler.
- Unsuspend: restore runtime and health checks.
- Callback WHMCS with new status.

### `terminate`

1. Final backup (optional grace policy).
2. Remove ingress/SSL routes.
3. Destroy container + volumes according to retention policy.
4. Mark tenant archived.
5. Callback WHMCS with `status=terminated`.

## 5) Usage enforcement service

Run every 1-5 minutes:

1. Query tenant n8n DB/API.
2. Compute active workflow count and month-to-date executions.
3. Compare against plan limits.
4. Apply policy:
   - warn at 80/95%
   - block new executions on hard limit (or suspend according to your policy)
5. Reset monthly counter at billing-cycle boundary.
6. Expose usage via `/v1/tenants/{external_id}/usage`.

## 6) Callback contract to WHMCS

POST to:

- `/modules/servers/n8nproxmox/callback.php`

Headers:

- `Authorization: Bearer <access-hash-token>`
- Optional: `X-N8N-Signature: <hmac sha256 hex>`

Payload example:

```json
{
  "service_id": 123,
  "job_id": "job_01JXYZ",
  "status": "active",
  "external_id": "tenant_abc123",
  "instance_url": "https://n8n-abc.example.com",
  "error_message": ""
}
```

Retry policy:

- Retry on non-2xx with exponential backoff (e.g. 10s, 30s, 90s, 5m, 15m).
- Use idempotency key: `job_id + status`.

## 7) Operational guardrails

- Use one DB transaction around job state transitions.
- Keep job states explicit: `queued`, `running`, `failed`, `completed`.
- Add distributed lock per tenant to avoid conflicting jobs.
- Keep audit logs with who/what/when for every lifecycle action.
- Run canary updates per version channel (`stable` first, then `latest`).
