# Make Provisioner API

Provisioner API for the WHMCS `makeproxmox` module. It accepts lifecycle jobs, executes Proxmox actions, calls runtime hooks, and posts async callbacks to WHMCS.

## Quick Start

1. Copy env template:

```bash
cp .env.example .env
```

2. Set required values in `.env`:

- `API_BEARER_TOKEN`
- `WHMCS_CALLBACK_URL`
- `WHMCS_CALLBACK_BEARER_TOKEN`
- `PROXMOX_API_URL`
- `PROXMOX_API_TOKEN_ID`
- `PROXMOX_API_TOKEN_SECRET`
- `PROXMOX_LXC_TEMPLATE_VMID`
- `MAKE_PUBLIC_BASE_DOMAIN`

3. Install and run:

```bash
npm install
npm run dev
```

## Endpoints

- `GET /v1/ping`
- `GET /v1/jobs/{jobId}`
- `POST /v1/jobs/provision`
- `POST /v1/jobs/suspend`
- `POST /v1/jobs/unsuspend`
- `POST /v1/jobs/change-package`
- `POST /v1/jobs/restart`
- `POST /v1/jobs/backup`
- `POST /v1/jobs/terminate`
- `GET /v1/tenants/{external_id}/status`
- `GET /v1/tenants/{external_id}/usage`

All endpoints except `/v1/ping` require:

- `Authorization: Bearer <API_BEARER_TOKEN>`

## Hook Wiring

By default `.env.example` points to a local hook service:

- `MAKE_DEPLOY_HOOK_URL=http://127.0.0.1:8090/hooks/deploy`
- `MAKE_LIMITS_HOOK_URL=http://127.0.0.1:8090/hooks/limits`
- `MAKE_BACKUP_HOOK_URL=http://127.0.0.1:8090/hooks/backup`

Run `deploy-hook/` and use the same token in:

- `MAKE_DEPLOY_HOOK_TOKEN`
- `MAKE_LIMITS_HOOK_TOKEN`
- `MAKE_BACKUP_HOOK_TOKEN`

## Staging Validation

Use the lifecycle script after both services are running:

```bash
npm run test:lifecycle
```

Script env variables:

- `PROVISIONER_API_URL`
- `PROVISIONER_API_TOKEN`
- `TEST_SERVICE_ID`
- `TEST_PRODUCT_ID`
- `TEST_CLIENT_ID`
- `TEST_PLAN_CODE`
