# Go-Live Checklist (WHMCS + Proxmox + n8n)

Use this checklist on deployment day. Mark each item before enabling customer orders.

## 1) Pre-Flight

- [ ] Confirm maintenance window and rollback owner.
- [ ] Confirm backups exist for WHMCS DB and Proxmox config.
- [ ] Confirm production `.env` files are present for:
  - [ ] `provisioner/.env`
  - [ ] `provisioner/deploy-hook/.env`
- [ ] Confirm tokens/secrets are production values (not staging values).
- [ ] Confirm DNS strategy is ready (`*.n8n.yourdomain.com` or per-tenant records).
- [ ] Confirm TLS/ingress is active and valid.

## 2) Deploy WHMCS Module

- [ ] Copy module files to WHMCS:
  - [ ] `modules/servers/n8nproxmox/n8nproxmox.php`
  - [ ] `modules/servers/n8nproxmox/lib/ApiClient.php`
  - [ ] `modules/servers/n8nproxmox/lib/WhmcsStore.php`
  - [ ] `modules/servers/n8nproxmox/callback.php`
  - [ ] `modules/servers/n8nproxmox/templates/clientarea.tpl`
- [ ] In WHMCS, configure server entry for `n8nproxmox`:
  - [ ] Hostname/IP points to provisioner API
  - [ ] Port/protocol correct
  - [ ] Access Hash = provisioner bearer token
  - [ ] Password = callback HMAC secret (if enabled)
- [ ] Configure product module options:
  - [ ] Plan Code
  - [ ] Region
  - [ ] n8n Version Channel
  - [ ] Backup Retention Days
- [ ] Ensure custom fields exist:
  - [ ] `External ID`
  - [ ] `Last Job ID`
  - [ ] `Instance URL`
  - [ ] `Provisioning Status`
  - [ ] `Last Error`
  - [ ] `Custom Domain` (for eligible plans)

## 3) Deploy Provisioner Service

- [ ] Deploy code from `provisioner/`.
- [ ] Install dependencies: `npm install`.
- [ ] Start service with process manager (systemd/pm2/docker).
- [ ] Verify health endpoint: `GET /v1/ping`.
- [ ] Verify logs are being collected.

## 4) Deploy Deploy-Hook Service

- [ ] Deploy code from `provisioner/deploy-hook/`.
- [ ] Install dependencies: `npm install`.
- [ ] Start service with process manager.
- [ ] Verify health endpoint: `GET /health`.
- [ ] Confirm host can run `pct exec` against target containers.

## 5) Proxmox Readiness

- [ ] Confirm API token permissions for LXC lifecycle operations.
- [ ] Confirm template VMID exists and is clone-ready.
- [ ] Confirm target storage pool exists.
- [ ] Confirm bridge/network is correct.
- [ ] Confirm node mapping by region is correct.

## 6) Integration Verification (Production Smoke Test)

- [ ] Run `Test Connection` in WHMCS module settings.
- [ ] Place one internal order for `starter_5g`.
- [ ] Verify create flow:
  - [ ] LXC cloned and started in Proxmox
  - [ ] n8n deployed and reachable
  - [ ] WHMCS fields updated via callback
  - [ ] Service status = `Active`
- [ ] Run `Suspend` + `Unsuspend` from WHMCS and verify Proxmox/WHMCS state.
- [ ] Run `Change Package` to `pro_20g` and verify resource/limit changes.
- [ ] Run `Run Backup Now` and verify backup artifact exists.
- [ ] Run `Terminate` on test service and verify cleanup.

## 7) Enable Customer Access

- [ ] Enable products/order forms for customers.
- [ ] Announce launch to support team.
- [ ] Share runbook links and escalation contacts.

## 8) First 24 Hours Monitoring

- [ ] Monitor provisioning success/failure rate.
- [ ] Monitor callback failures.
- [ ] Monitor average provisioning time.
- [ ] Monitor service health and container restarts.
- [ ] Review and resolve any failed jobs.

## 9) Rollback Steps (If Needed)

- [ ] Disable new orders for n8n products in WHMCS.
- [ ] Keep existing active services running.
- [ ] Roll back `provisioner` and/or `deploy-hook` to previous release.
- [ ] Re-run smoke test on rolled-back release.
- [ ] Re-enable sales only after stability is confirmed.
