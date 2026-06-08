# n8n Proxmox Provisioner (Starter)

This is a minimal Fastify-based starter service for the WHMCS `n8nproxmox` server module.

It provides:

- bearer-authenticated endpoints expected by WHMCS
- async job queue/runner (in-memory, for starter use)
- plan matrix validation and mapping
- callback posting to WHMCS `callback.php`
- real Proxmox LXC API actions (clone, configure, resize, suspend, resume, restart, terminate, snapshot)
- n8n deployment workflow (instance URL, optional deploy hook, health check polling)

## Quick start

1. Copy env file:

```bash
cp .env.example .env
```

2. Set these env values:

- `API_BEARER_TOKEN` = same token you set in WHMCS server Access Hash
- `WHMCS_CALLBACK_URL` = `https://<your-whmcs>/modules/servers/n8nproxmox/callback.php`
- `WHMCS_CALLBACK_BEARER_TOKEN` = same token as above
- optional `WHMCS_CALLBACK_HMAC_SECRET` = same value as WHMCS server password
- `PROXMOX_API_URL` = e.g. `https://pve-host:8006/api2/json`
- `PROXMOX_API_TOKEN_ID` = e.g. `root@pam!whmcs`
- `PROXMOX_API_TOKEN_SECRET` = Proxmox token secret
- `PROXMOX_LXC_TEMPLATE_VMID` = source LXC template VMID to clone from
- `PROXMOX_STORAGE`, `PROXMOX_BRIDGE`, `PROXMOX_REGION_NODE_MAP` as needed
- `N8N_PUBLIC_BASE_DOMAIN` = e.g. `n8n.yourdomain.com`
- optional hooks: `N8N_DEPLOY_HOOK_URL`, `N8N_LIMITS_HOOK_URL`, `N8N_BACKUP_HOOK_URL`
- ready-to-run hook implementation is available in `provisioner/deploy-hook/`

3. Install and run:

```bash
npm install
npm run dev
```

## Endpoints

- `GET /v1/ping`
- `POST /v1/jobs/provision`
- `POST /v1/jobs/suspend`
- `POST /v1/jobs/unsuspend`
- `POST /v1/jobs/terminate`
- `POST /v1/jobs/change-package`
- `POST /v1/jobs/restart`
- `POST /v1/jobs/backup`
- `GET /v1/tenants/:externalId/status`
- `GET /v1/tenants/:externalId/usage`

## Proxmox expectations

- Template VM (`PROXMOX_LXC_TEMPLATE_VMID`) must already exist and be clone-ready.
- API token must have permissions for LXC allocate/clone/config/power/snapshot/delete.
- Disk downsizing is not performed; package downgrades only reduce CPU/RAM and limits.

## n8n deployment expectations

- The provisioner derives instance URL as `<external_id>.<N8N_PUBLIC_BASE_DOMAIN>` unless `custom_domain` is provided.
- If `N8N_DEPLOY_HOOK_URL` is set, your automation endpoint should bootstrap n8n inside the container.
- Provisioner waits for `N8N_HEALTH_CHECK_PATH` to return healthy before marking service active.
- A concrete hook service with deploy/limits/backup endpoints is included under `deploy-hook`.

## What to replace next (production)

1. Replace in-memory store with PostgreSQL.
2. Replace in-memory queue with Redis/RabbitMQ/SQS.
3. Add idempotency key handling (`service_id + job_id + status`) for callback retries.
4. Add metrics/tracing and alerting.
