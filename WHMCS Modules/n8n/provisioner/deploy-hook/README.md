# Deploy Hook Service

This service receives hooks from the main provisioner and performs container-level n8n actions using `pct exec`.

## Endpoints

- `POST /hooks/deploy` - install Docker/Compose and deploy n8n stack in LXC
- `POST /hooks/limits` - write plan limits file to `/opt/n8n/plan-limits.json`
- `POST /hooks/backup` - run PostgreSQL dump backup in `/opt/n8n/backups`
- `GET /health`

All hook endpoints require:

- `Authorization: Bearer <HOOK_BEARER_TOKEN>`

## Quick start

1. Copy env file and set values:

```bash
cp .env.example .env
```

2. Install and run:

```bash
npm install
npm run dev
```

## Required payloads

### Deploy

```json
{
  "service_id": 123,
  "external_id": "tenant_123",
  "vmid": 1101,
  "node": "pve-node-01",
  "instance_url": "https://tenant-123.n8n.example.com",
  "version_channel": "stable",
  "customer": { "email": "client@example.com" }
}
```

### Limits

```json
{
  "external_id": "tenant_123",
  "vmid": 1101,
  "node": "pve-node-01",
  "limits": { "activeWorkflows": 25, "executionsPerMonth": 15000 },
  "features": { "backupFrequency": "daily", "customDomain": false }
}
```

### Backup

```json
{
  "external_id": "tenant_123",
  "vmid": 1101,
  "node": "pve-node-01",
  "retention_days": 7
}
```

## Wire into main provisioner

In `provisioner/.env`:

- `N8N_DEPLOY_HOOK_URL=http://127.0.0.1:8090/hooks/deploy`
- `N8N_LIMITS_HOOK_URL=http://127.0.0.1:8090/hooks/limits`
- `N8N_BACKUP_HOOK_URL=http://127.0.0.1:8090/hooks/backup`
- `N8N_*_HOOK_TOKEN=<same HOOK_BEARER_TOKEN>`
