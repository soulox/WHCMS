# n8nproxmox (WHMCS 7.10.2)

Server provisioning module skeleton for managed n8n instances on Proxmox.

## Folder layout

Copy this directory into your WHMCS installation:

`modules/servers/n8nproxmox`

Contains:

- `n8nproxmox.php` - WHMCS module entry points (Create/Suspend/Terminate/ChangePackage/ClientArea)
- `lib/ApiClient.php` - simple JSON HTTP client to your provisioner API
- `lib/WhmcsStore.php` - WHMCS DB persistence helper for custom fields/service status
- `callback.php` - secure webhook endpoint for async job completion updates
- `templates/clientarea.tpl` - client panel status and usage widget

## WHMCS setup

1. Go to **System Settings > Servers**, add a new server using module `n8nproxmox`.
2. Server fields:
   - Hostname/IP: your internal provisioner API host
   - Port: API port (for example `443`)
   - Secure: enabled for HTTPS
   - Access Hash: API bearer token used by this module and callback auth
   - Password: webhook HMAC secret (optional but recommended)
3. Create products and assign this module.
4. For each product, set config options:
   - Plan Code (`starter_5g`, `pro_20g`, `scale_50g`)
   - Region
   - n8n Version Channel
   - Backup Retention Days
6. In each product's **Module Settings**:
   - Enable module: `n8nproxmox`
   - Set **Automatically setup the product as soon as the first payment is received** (or your preferred trigger)
   - Ensure `Plan Code` is set, otherwise Create/ChangePackage actions will fail validation
5. Add optional product custom fields (exact names) if you want these values to be sent in payload:
   - `Custom Domain`
   - `External ID`
   - `Last Job ID`
   - `Instance URL`
   - `Provisioning Status`
   - `Last Error`

## Callback endpoint (async updates)

Provisioner should call:

- `https://<your-whmcs-host>/modules/servers/n8nproxmox/callback.php`

Method and auth:

- `POST` JSON body
- `Authorization: Bearer <same token as server Access Hash>`
- Optional: `X-N8N-Signature: <hex hmac sha256(body, server password secret)>`

Minimum callback payload:

```json
{
  "service_id": 123,
  "job_id": "job_01JXYZ",
  "status": "active",
  "external_id": "tenant_abc123",
  "instance_url": "https://n8n-abc.example.com"
}
```

Status mapping in callback:

- `active` -> WHMCS service status `Active`
- `suspended` -> WHMCS service status `Suspended`
- `terminated` -> WHMCS service status `Terminated`

## Provisioner API contract (expected)

All requests are JSON with header:

- `Authorization: Bearer <token>`
- `X-Service-Id: <whmcs service id>`

Endpoints used by this module:

- `GET /v1/ping`
- `POST /v1/jobs/provision`
- `POST /v1/jobs/suspend`
- `POST /v1/jobs/unsuspend`
- `POST /v1/jobs/terminate`
- `POST /v1/jobs/change-package`
- `POST /v1/jobs/restart`
- `POST /v1/jobs/backup`
- `GET /v1/tenants/{external_id}/status`
- `GET /v1/tenants/{external_id}/usage`

Typical POST body:

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
  "hostname": "example.customerhost.tld",
  "username": "",
  "password": "",
  "email": "customer@example.com",
  "firstname": "Jane",
  "lastname": "Doe",
  "custom_domain": ""
}
```

Expected successful queue response:

```json
{
  "job_id": "job_01JXYZ...",
  "external_id": "tenant_abc123"
}
```

## Plan matrix recommendation

Keep this matrix in the provisioner (source of truth), not in WHMCS:

- `starter_5g`: 5GB disk, 5 active workflows, 2500 executions/month, daily backups
- `pro_20g`: 20GB disk, 25 active workflows, 15000 executions/month, daily backups, priority support
- `scale_50g`: 50GB disk, unlimited active workflows, 50000 executions/month, hourly backups, custom domain

## Notes

- This skeleton queues jobs and assumes asynchronous provisioning.
- Persisting/updating custom fields is implemented through callback payload updates.
- For full API + worker flow, see `modules/servers/n8nproxmox/docs/provisioner-api-and-workers.md`.
- A runnable starter provisioner service is included at `provisioner/`.
