# Make Package to Proxmox Mapping

## Production Defaults

| Package Key | Guest Type | vCPU | RAM | Disk | Operations/Month | Active Scenarios | Backup Policy | Custom Domain |
| --- | --- | ---: | ---: | ---: | ---: | ---: | --- | --- |
| `make-starter` | LXC | 1 | 2048 MB | 5 GB | 10,000 | 5 | Daily | No |
| `make-professional` | LXC | 2 | 4096 MB | 20 GB | 100,000 | 25 | Daily | No |
| `make-enterprise` | KVM | 4 | 8192 MB | 50 GB | Unlimited | Unlimited | Hourly | Yes |

## Notes

- Starter and Professional use dedicated LXC containers for fast provisioning and lower host overhead.
- Enterprise defaults to KVM for stronger isolation and easier advanced networking controls.
- Change-package should only support disk growth in-place; shrinking requires rebuild/migration.
- Keep this file aligned with `provisioner/src/plans.js`.
