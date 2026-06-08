# Make Deploy Hook Service

This service receives hook calls from the main provisioner and runs guest-level runtime operations.

## Endpoints

- `POST /hooks/deploy`
- `POST /hooks/limits`
- `POST /hooks/backup`
- `GET /health`

All hook endpoints require:

- `Authorization: Bearer <HOOK_BEARER_TOKEN>`

## Guest Types

- `guest_type: "lxc"` executes commands using `pct exec`
- `guest_type: "qemu"` executes commands using `qm guest exec` (requires QEMU guest agent)

## Quick Start

1. Copy env file:

```bash
cp .env.example .env
```

2. Install and run:

```bash
npm install
npm run dev
```

3. Wire into provisioner `.env`:

- `MAKE_DEPLOY_HOOK_URL=http://127.0.0.1:8090/hooks/deploy`
- `MAKE_LIMITS_HOOK_URL=http://127.0.0.1:8090/hooks/limits`
- `MAKE_BACKUP_HOOK_URL=http://127.0.0.1:8090/hooks/backup`
- `MAKE_*_HOOK_TOKEN=<HOOK_BEARER_TOKEN>`

## Example Deploy Payload

```json
{
  "service_id": 123,
  "external_id": "tenant_123",
  "vmid": 1101,
  "node": "pve-node-01",
  "guest_type": "lxc",
  "instance_url": "https://tenant-123.make.example.com",
  "runtime_channel": "stable",
  "customer": { "email": "client@example.com" }
}
```
