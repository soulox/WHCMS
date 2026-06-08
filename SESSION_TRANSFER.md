# Session Transfer - Proxmox + WHMCS + DNS + Image Pipeline

Use this file to continue in a new session without re-discovery.

## Current State

- Internal DNS VMs are deployed and reachable:
  - DNS1: `10.10.10.53` (`:5380` reachable)
  - DNS2: `10.10.10.54` (`:5380` reachable)
- Technitium admin passwords were set on both.
- Zones were created/imported and replication setup was completed by user.
- Proxmox node DNS/search settings were fixed after initial misconfiguration.

## Key Infra Facts

- Proxmox nodes:
  - `localhost110` -> `10.10.10.110`
  - `pve` -> `10.10.10.24`
  - `pve5` -> `10.10.10.25`
  - `pve26` -> `10.10.10.26`
  - `pve27` -> `10.10.10.27`
- Gateway: `10.10.10.254`
- Search domain should be exactly: `infra.local` (not `*.infra.local`).

## DNS Records/Files Created

- Zone files generated in repo:
  - `dns-zones/infra.local.zone`
  - `dns-zones/10.10.10.rev.zone`
- A records include: `dns1`, `dns2`, `pve26`, `localhost110`, `pve`, `pve27`, `pve5`.
- Matching PTR records were included.

## Script Work Completed

Updated `deploy-dns-vms.sh` with major reliability fixes:

- Clone/migrate flow handles non-shared clone path and target-node ownership.
- `qm` actions run on correct node after migration.
- Cloud-init disk (`ide2`) is enforced/reattached when missing.
- `qm cloudinit update` + `cloudinit dump` validations before VM start.
- DNS nameserver handling fixed for Proxmox cloud-init format.
- Default gateway corrected to `10.10.10.254`.
- SSH key handling fixed for remote target nodes.
- `cloud-final` robustness improved with retry logic for apt/curl install steps.

## Root Causes Encountered (Resolved)

1. `qm set` 400 too many arguments:
   - Cause: nameserver format invalid in one path.
   - Fix: normalized nameserver handling.
2. Cloud-init settings not applying:
   - Cause: `ide2` cloud-init disk not attached after workflow interruption/migration edge case.
   - Fix: explicit cloud-init attachment verification and correction.
3. Guest DNS/network final-stage failures:
   - Cause: malformed nameserver value and timing in `cloud-final` app install.
   - Fix: corrected nameserver + retries.

## WHMCS Module Status

`modules/servers/proxmox/proxmox.php` was updated earlier with:

- KVM cloud-init DHCP behavior (`ipconfig0=ip=dhcp`).
- SSH key extraction/injection support:
  - LXC uses `ssh-public-keys`
  - KVM uses `sshkeys`
- New option: disable password auth when SSH key present.
- Skip LXC/KVM password injection when SSH-key-only mode applies.

## Template/Packer/Ansible Progress

- Packer and Ansible installed on automation host.
- Packer plugin initialized successfully (`hashicorp/proxmox`).
- Initial `proxmox-clone` smoke build failed with:
  - `vm '9001' not found`
- Reason: template visibility/node-context mismatch during clone lookup.

Important template detail now confirmed:

- `qm config 9001` on `pve27` shows template exists and uses shared storage `synology-thin`.
- Even with shared disks, clone/build context may still need correct source node ownership awareness.

Current retained template baseline on `pve26`:

- Source templates: `9200`, `9201`, `9202`, `9213`, `9214`, `9215`
- Golden templates: `9300`, `9301`, `9302`, `9303`, `9304`, `9305`

Cleanup completed:

- Removed debug VMs: `9313`, `9314`, `9315`, `9323`, `9324`, `9325`, `9333`, `9334`, `9335`
- Removed n8n smoke VMs: `9413`, `9415`
- Kept production app VM: `9401` (`n8n-prod-01`)

Security note:

- Packer API token secret was present in repo vars files during prior sessions.
- Rotate/revoke old token and keep future secrets out of tracked vars files.

## Recommended Next Actions

1. Rotate Proxmox API token and remove secrets from tracked `*.auto.pkrvars.hcl` usage.
2. Run `./image-pipeline/build-all-linux-templates.sh --validate-only`.
3. Verify WHMCS product mappings target approved template VMIDs only (`9300`-`9305`, plus app templates as intended).
4. Run one canary full rebuild (Debian first), then one RHEL-family canary.

## Operational Notes

- WHMCS host is on a different IP block; cross-subnet ping works.
- Resolver fallback to public DNS caused confusion before; internal records should be queried via internal resolvers/FQDNs.
- Prefer FQDNs (`*.infra.local`) in automation to avoid short-name ambiguity.

## Session Persistence Guidance

- Run OpenCode on automation host inside tmux:
  - `tmux new -s opencode`
  - run `opencode`
  - detach with `Ctrl+b`, then `d`
  - reattach: `tmux attach -t opencode`
- Keep this file + `INFRASTRUCTURE.md` updated for next session handoff.
