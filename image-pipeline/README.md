# Proxmox Image Pipeline

This directory contains a Packer + Ansible pipeline for producing Proxmox templates for WHMCS provisioning.

## Files

- `packer/debian12-golden.pkr.hcl` - shared Packer `proxmox-clone` build definition.
- `packer/*.auto.pkrvars.hcl` - per-OS variable files (Debian, Ubuntu, Alma, Rocky, CentOS Stream).
- `ansible/harden-debian12.yml` - Debian hardening and cleanup tasks.
- `ansible/harden-ubuntu.yml` - Ubuntu hardening and cleanup tasks.
- `ansible/harden-rhel9.yml` - RHEL-family (9.x) hardening and cleanup tasks.
- `build-template.sh` - run one build from a chosen vars file.
- `build-all-linux-templates.sh` - run all configured Linux builds.
- `build-debian-template.sh` - convenience wrapper for Debian vars.

## Prerequisites

- Packer installed with `hashicorp/proxmox` plugin support.
- Ansible installed on the build host.
- Existing source templates that are cloud-init-capable and SSH reachable.
- Proxmox API token with enough privileges to clone and convert templates.

## Quick Start

1. Copy vars file and set credentials:

   ```bash
   cp image-pipeline/packer/example.auto.pkrvars.hcl image-pipeline/packer/debian12.auto.pkrvars.hcl
   ```

2. Update the selected `*.auto.pkrvars.hcl` values:
   - `proxmox_url`
   - `proxmox_node` (source template owner node)
   - `token_id` / `token_secret`
   - `ssh_username` / `ssh_host`
   - `source_template`, `vm_id`, `template_name`
   - `playbook_file`

3. Run one build:

   ```bash
   ./image-pipeline/build-template.sh image-pipeline/packer/debian12.auto.pkrvars.hcl
   ```

4. Or run all configured builds:

   ```bash
   ./image-pipeline/build-all-linux-templates.sh
   ```

5. Debian-only wrapper (backward-compatible):

   ```bash
   ./image-pipeline/build-debian-template.sh
   ```

## Validation Only

If you only want local config checks without building:

```bash
packer init image-pipeline/packer/debian12-golden.pkr.hcl
packer validate -syntax-only image-pipeline/packer/debian12-golden.pkr.hcl
ansible-playbook --syntax-check image-pipeline/ansible/harden-debian12.yml
ansible-playbook --syntax-check image-pipeline/ansible/harden-ubuntu.yml
ansible-playbook --syntax-check image-pipeline/ansible/harden-rhel9.yml
```

Or with the wrapper script and real vars:

```bash
./image-pipeline/build-debian-template.sh --validate-only
```

## Current Build Status on pve26

- Success:
  - Debian 12 source `9200` -> golden `9300` (`tpl-whmcs-debian12-v1-pve26`)
  - Ubuntu 22.04 source `9201` -> golden `9301` (`tpl-whmcs-ubuntu2204-v1-pve26`)
  - Ubuntu 24.04 source `9202` -> golden `9302` (`tpl-whmcs-ubuntu2404-v1-pve26`)
- RHEL-family fallback templates created:
  - AlmaLinux 9 source `9213` -> golden `9303` (`tpl-whmcs-almalinux9-v1-pve26`)
  - Rocky 9 source `9214` -> golden `9304` (`tpl-whmcs-rocky9-v1-pve26`)
  - CentOS Stream 9 source `9215` -> golden `9305` (`tpl-whmcs-centosstream9-v1-pve26`)

Note: `9303`/`9304`/`9305` were finalized with a manual clone/force-stop/template flow because SSH provisioning remains unreliable on these RHEL-family cloud images in this environment.

## Current Baseline (Post-Cleanup)

- Retained production VM: `9401` (`n8n-prod-01`).
- Removed debug and smoke test VMs: `9313`, `9314`, `9315`, `9323`, `9324`, `9325`, `9333`, `9334`, `9335`, `9413`, `9415`.
- Approved Linux golden template set for WHMCS mapping remains:
  - `9300` Debian 12
  - `9301` Ubuntu 22.04
  - `9302` Ubuntu 24.04
  - `9303` AlmaLinux 9
  - `9304` Rocky 9
  - `9305` CentOS Stream 9

## Secret Handling (Required)

- Do not commit live `token_secret` values in tracked `*.auto.pkrvars.hcl` files.
- Rotate any token previously stored in repo history.
- Preferred pattern:
  - keep non-secret vars in tracked `.auto.pkrvars.hcl`
  - inject secret at runtime via environment variable and `PKR_VAR_token_secret`

Example:

```bash
export PKR_VAR_token_secret='REDACTED'
./image-pipeline/build-template.sh image-pipeline/packer/debian12.auto.pkrvars.hcl --validate-only
```
