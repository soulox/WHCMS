#!/usr/bin/env bash
set -euo pipefail

# Option A: hardcode hosts
HOSTS=("pve1" "pve2" "pve3" "pve4")

# Option B (auto from cluster), uncomment if running on a Proxmox node with jq:
# mapfile -t HOSTS < <(pvesh get /nodes --output-format json | jq -r '.[].node')

SSH_USER="${SSH_USER:-root}"
SSH_OPTS="-o BatchMode=yes -o ConnectTimeout=8"

run_remote_audit() {
  local host="$1"
  echo
  echo "==================== ${host} ===================="
  ssh ${SSH_OPTS} "${SSH_USER}@${host}" 'bash -s' <<'REMOTE'
set -euo pipefail
echo "Node: $(hostname)"
echo "-- pveversion --"
pveversion | head -n 1 || true

echo "-- bridges --"
ip -br link | awk '/vmbr|UP|DOWN/'
echo "-- ipv4 --"
ip -4 -br addr
echo "-- routes --"
ip route

echo "-- /etc/network/interfaces (non-empty) --"
grep -v "^[[:space:]]*$" /etc/network/interfaces || true

echo "-- storage --"
pvesm status || true

echo "-- templates (VM) --"
qm list | awk 'NR==1 || /template|9000|9001|9002|9003|9004/'

echo "-- cloud-init markers on template VMIDs --"
for id in 9000 9001 9002 9003 9004; do
  if qm status "$id" >/dev/null 2>&1; then
    echo "VMID $id"
    qm config "$id" | awk '/^name:|^agent:|^ide2:|^net0:|^ipconfig0:|^template:/'
  fi
done

echo "-- lxc templates on local --"
pveam list local 2>/dev/null | awk 'NR<25 || /almalinux|rocky|centos|ubuntu|debian/' || true

echo "-- firewall --"
pve-firewall status || true
REMOTE
}

for h in "${HOSTS[@]}"; do
  if ! ssh ${SSH_OPTS} "${SSH_USER}@${h}" "echo ok" >/dev/null 2>&1; then
    echo "==================== ${h} ===================="
    echo "ERROR: SSH failed"
    continue
  fi
  run_remote_audit "$h"
done
