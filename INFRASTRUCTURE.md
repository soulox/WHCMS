# Infrastructure Handoff

This document captures current infrastructure and repository state so a new session can continue without re-discovery.

## Environment

- Platform: Proxmox cluster + WHMCS module repository.
- Repository path (current Linux host): `/home/support/WHMCS Modules/Proxmox`
- Primary app context: WHMCS Proxmox server module + addon module + hook integration.
- Last updated: 2026-04-09.

## Proxmox Cluster and Network

Known nodes and IPs:

- `localhost110` -> `10.10.10.110`
- `pve` -> `10.10.10.24`
- `pve5` -> `10.10.10.25`
- `pve26` -> `10.10.10.26`
- `pve27` -> `10.10.10.27`

Network baselines:

- Gateway: `10.10.10.254`
- Search domain must be exactly: `infra.local`
- Do not assume short hostnames resolve in every context; prefer FQDNs or explicit IPs in automation.

## DNS Platform Status (Technitium)

Internal DNS VMs are deployed and reachable:

- DNS1: `10.10.10.53` (admin UI reachable on `:5380`)
- DNS2: `10.10.10.54` (admin UI reachable on `:5380`)

Completed state:

- Technitium admin passwords set on both DNS VMs.
- Zones created/imported by user.
- Replication configured by user.
- Proxmox node DNS/search settings corrected after earlier misconfiguration.

## DNS Records and Artifacts

Generated zone files in repo:

- `dns-zones/infra.local.zone`
- `dns-zones/10.10.10.rev.zone`

Included records:

- A records: `dns1`, `dns2`, `pve26`, `localhost110`, `pve`, `pve27`, `pve5`
- Matching PTR records for reverse lookups.

## Script Status

### `deploy-dns-vms.sh`

Major reliability updates completed:

- Clone/migrate flow handles non-shared clone paths and target-node ownership.
- `qm` actions run on the correct owning node after migration.
- Cloud-init disk (`ide2`) is enforced/reattached when missing.
- `qm cloudinit update` and `cloudinit dump` validation run before VM start.
- Nameserver handling normalized for Proxmox cloud-init format.
- Default gateway corrected to `10.10.10.254`.
- SSH key handling fixed for remote target nodes.
- `cloud-final` app-install steps made more robust with retry logic.

### `proxmox-multihost-audit.sh`

- SSH-driven cluster/node audit utility remains available.
- Ensure target hostnames are resolvable (or use explicit IPs).

## Root Causes Encountered (Resolved)

1. `qm set` 400 "too many arguments"
   - Cause: invalid nameserver format in one path.
   - Fix: normalized nameserver handling.
2. Cloud-init settings not applying
   - Cause: missing `ide2` cloud-init disk after interruption/migration edge cases.
   - Fix: explicit cloud-init attachment verification and correction.
3. Guest DNS/network final-stage failures
   - Cause: malformed nameserver value and cloud-final timing during package installs.
   - Fix: corrected nameserver handling and retry logic.

## WHMCS Module Status

Updated file:

- `modules/servers/proxmox/proxmox.php`

Implemented behavior:

- KVM cloud-init DHCP behavior (`ipconfig0=ip=dhcp`).
- SSH key extraction/injection support:
  - LXC uses `ssh-public-keys`
  - KVM uses `sshkeys`
- Product option to disable password auth when SSH key is present.
- Skip password injection for SSH-key-only mode:
  - LXC skips `password`
  - KVM skips `cipassword`

Validation completed earlier:

- `php -l modules/servers/proxmox/proxmox.php` passed.

## Template/Packer/Ansible Pipeline

Current progress:

- Packer and Ansible installed on automation host.
- Packer plugin initialized (`hashicorp/proxmox`).
- Initial `proxmox-clone` smoke build failed with `vm '9001' not found`.

Current understanding:

- `qm config 9001` on `pve27` confirms template exists on shared storage (`synology-thin`).
- Clone/build context still depends on correct source node ownership visibility.

## Recommended Next Actions

1. Standardize image pipeline:
   - Build fresh seed templates from vendor cloud images.
   - Use Packer clone + Ansible hardening to produce certified WHMCS templates.
2. Define mapping model:
   - `os_choice -> template_vmid -> source_node` (or move template ownership for consistency).
3. Validate one full lifecycle (Debian first):
   - build -> harden -> certify -> switch WHMCS mapping.
4. Keep old templates until new templates pass real WHMCS order smoke tests.

## Application Templates and Deployments

n8n image/deployment created on `pve26`:

- Template v4 (current recommended): `9414` (`tpl-whmcs-n8n-v4-pve26`)
- Deployed VM: `9401` (`n8n-prod-01`) at `10.10.10.71`

Cleaned up deprecated test assets:

- Removed old templates/smoke VMs: `9400`, `9406`, `9410`, `9412`, `9413`, `9415`

n8n stack details:

- Runtime: Docker + docker-compose
- Services: `n8n` + `postgres`
- Compose path: `/opt/n8n/docker-compose.yml`
- Env file: `/opt/n8n/.env`
- HTTP port: `5678`
- Basic auth enabled (`admin` user configured in env)
- HTTPS enabled with Caddy reverse proxy on `443` (`tls internal`).

TLS trust bootstrap:

- Exported Caddy local root CA cert to `certs/n8n-caddy-local-root-2026.crt`.
- Import this cert into client trust stores to remove browser warnings for:
  - `https://n8n.infra.local`
  - `https://m8n.infra.local`

## Operational Notes

- WHMCS host is on a different IP block; cross-subnet ping works.
- Public DNS fallback previously caused confusion; query internal records via internal resolvers and FQDNs.
- Prefer FQDNs (`*.infra.local`) in automation to avoid short-name ambiguity.

## Session Persistence

Run OpenCode in `tmux` on the automation host:

- `tmux new -s opencode`
- run `opencode`
- detach: `Ctrl+b`, then `d`
- reattach: `tmux attach -t opencode`

Keep this file and `SESSION_TRANSFER.md` updated together to avoid handoff drift.
