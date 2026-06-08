# makeproxmox (WHMCS 7.10.2)

Server provisioning module scaffold for managed Make instances on Proxmox.

## Folder layout

Copy this directory into your WHMCS installation:

`modules/servers/makeproxmox`

Contains:

- `makeproxmox.php` - module entry points (Create/Suspend/Terminate/ChangePackage/ClientArea)
- `lib/ApiClient.php` - JSON HTTP client to provisioner API
- `lib/WhmcsStore.php` - WHMCS DB helper for custom fields/status updates
- `callback.php` - secure webhook endpoint for async job status updates
- `templates/clientarea.tpl` - client status and usage view

## WHMCS setup

1. Go to **System Settings > Servers**, add a new server with module `makeproxmox`.
2. Server fields:
   - Hostname/IP: internal provisioner API host
   - Port: API port (for example `443`)
   - Secure: enabled for HTTPS
   - Access Hash: API bearer token used by module and callback auth
   - Password: callback HMAC secret (optional but recommended)
3. Create products and assign this module.
4. Set product config options:
   - Package Key (`make-starter`, `make-professional`, `make-enterprise`)
   - Region
   - Runtime Channel
   - Backup Retention Days
5. Add product custom fields (exact names):
   - `Custom Domain`
   - `External ID`
   - `Last Job ID`
   - `Instance URL`
   - `Provisioning Status`
   - `Last Error`

## Tie Module to Product for Auto Provisioning

For each WHMCS product:

1. Open **Product/Service > Module Settings**.
2. Select module **makeproxmox**.
3. Set **Package Key** to match that product tier exactly:
   - Starter product -> `make-starter`
   - Professional product -> `make-professional`
   - Enterprise product -> `make-enterprise`
4. Save changes.

Then enable WHMCS automatic provisioning behavior:

1. Open **Product/Service > Details**.
2. Set **Module Settings > Automatically setup the product as soon as...**
   - Recommended: `the first payment is received`
3. Ensure the product is assigned to a server/server group that uses `makeproxmox`.

Fallback behavior in module if Package Key is blank:

- It tries to map from product name (`starter`, `professional`, `enterprise`).
- If still unresolved, it checks service custom field `Package Key`.
- If no plan can be resolved, provisioning is blocked with a clear error.

## Callback endpoint

Provisioner callback URL:

- `https://<your-whmcs-host>/modules/servers/makeproxmox/callback.php`

Method and auth:

- `POST` with JSON body
- `Authorization: Bearer <same token as server Access Hash>`
- Optional: `X-MAKE-Signature: <hex hmac sha256(body, server password secret)>`

Minimum callback payload:

```json
{
  "service_id": 123,
  "job_id": "job_01JXYZ",
  "status": "active",
  "external_id": "tenant_abc123",
  "instance_url": "https://tenant-abc.make.example.com"
}
```

## Provisioner API contract

All module requests are JSON with:

- `Authorization: Bearer <token>`
- `X-Service-Id: <whmcs service id>`

Endpoints used:

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

## Staging Checklist

1. Confirm WHMCS custom fields exist exactly:
   - `Custom Domain`
   - `External ID`
   - `Last Job ID`
   - `Instance URL`
   - `Provisioning Status`
   - `Last Error`
2. From WHMCS server settings, run **Test Connection**.
3. Verify provisioner ping manually:

```bash
curl -H "Authorization: Bearer <token>" https://<provisioner-host>/v1/ping
```

4. Trigger lifecycle actions in staging product:
   - Create
   - Suspend
   - Unsuspend
   - Change Package
   - Run Backup Now
   - Terminate
5. Verify callback updates service status and custom fields after each action.
