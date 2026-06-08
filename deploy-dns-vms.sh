#!/usr/bin/env bash
set -euo pipefail

# =========================
# Proxmox DNS VM Auto-Deploy
# =========================
# This script ONLY creates DNS VMs. It does NOT modify Proxmox node IP config.

# ---- Required existing template ----
TEMPLATE_VMID="${TEMPLATE_VMID:-9001}"   # Debian cloud-init template VMID

# ---- DNS VM definitions ----
DNS1_VMID="${DNS1_VMID:-9101}"
DNS1_NAME="${DNS1_NAME:-dns1}"
DNS1_IP="${DNS1_IP:-10.10.10.53/24}"
DNS1_GW="${DNS1_GW:-10.10.10.254}"
DNS1_TARGET_NODE="${DNS1_TARGET_NODE:-pve26}"
DNS1_TARGET_NODE_IP="${DNS1_TARGET_NODE_IP:-10.10.10.14}"

DNS2_VMID="${DNS2_VMID:-9102}"
DNS2_NAME="${DNS2_NAME:-dns2}"
DNS2_IP="${DNS2_IP:-10.10.10.54/24}"
DNS2_GW="${DNS2_GW:-10.10.10.254}"
DNS2_TARGET_NODE="${DNS2_TARGET_NODE:-pve27}"
DNS2_TARGET_NODE_IP="${DNS2_TARGET_NODE_IP:-10.10.10.15}"

# ---- VM resources ----
BRIDGE="${BRIDGE:-vmbr0}"
STORAGE="${STORAGE:-local-lvm}"   # target disk storage for clone
CORES="${CORES:-2}"
MEMORY_MB="${MEMORY_MB:-2048}"
SOURCE_NODE="${SOURCE_NODE:-$(hostname -s)}"  # node that currently holds TEMPLATE_VMID

# ---- Cloud-init user ----
CIUSER="${CIUSER:-debian}"
# Set this or export CI_PASSWORD before run
CI_PASSWORD="${CI_PASSWORD:-ChangeMeNow!123}"

# Optional SSH public key file injected into VM
SSH_PUBKEY_FILE="${SSH_PUBKEY_FILE:-/root/.ssh/id_ed25519.pub}"

# Upstream resolver for cloud-init guest DNS (single IP expected by qm --nameserver)
UPSTREAM_DNS="${UPSTREAM_DNS:-1.1.1.1}"

need_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "Missing command: $1"; exit 1; }; }
need_cmd qm
need_cmd ssh
need_cmd scp
need_cmd awk
need_cmd mktemp
need_cmd ping

if [[ ! -f "$SSH_PUBKEY_FILE" ]]; then
  echo "Warning: SSH key file not found: $SSH_PUBKEY_FILE (continuing without ssh key injection)"
fi

if ! qm config "$TEMPLATE_VMID" >/dev/null 2>&1; then
  echo "Template VMID $TEMPLATE_VMID not found."
  exit 1
fi

write_user_data() {
  local out_file="$1"
  cat > "$out_file" <<'EOF'
#cloud-config
package_update: true
package_upgrade: true
runcmd:
  - bash -lc 'for i in 1 2 3 4 5; do apt-get update && break; sleep 10; done'
  - bash -lc 'for i in 1 2 3 4 5; do apt-get install -y curl ca-certificates && break; sleep 10; done'
  - bash -lc 'for i in 1 2 3 4 5; do curl -fsSL https://download.technitium.com/dns/install.sh | bash && break; sleep 15; done'
  - bash -lc 'if systemctl list-unit-files | grep -q "^dns.service"; then systemctl enable --now dns; fi'
  - bash -lc 'sleep 2; systemctl status dns --no-pager || true'
EOF
}

prepare_snippet_on_node() {
  local node_ip="$1"
  local snippet_name="$2"
  local local_file="$3"

  ssh -o StrictHostKeyChecking=accept-new root@"$node_ip" "mkdir -p /var/lib/vz/snippets"
  scp -o StrictHostKeyChecking=accept-new "$local_file" root@"$node_ip":"/var/lib/vz/snippets/${snippet_name}"
}

prepare_sshkey_on_node() {
  local node_ip="$1"
  local local_file="$2"
  local remote_file="$3"

  ssh -o StrictHostKeyChecking=accept-new root@"$node_ip" "mkdir -p \"$(dirname "$remote_file")\""
  scp -o StrictHostKeyChecking=accept-new "$local_file" root@"$node_ip":"$remote_file"
}

qm_on_target() {
  local target_node="$1"
  local target_node_ip="$2"
  shift 2

  if [[ "$target_node" == "$SOURCE_NODE" ]]; then
    qm "$@"
  else
    ssh -o StrictHostKeyChecking=accept-new root@"$target_node_ip" qm "$@"
  fi
}

qm_capture_on_target() {
  local target_node="$1"
  local target_node_ip="$2"
  shift 2

  if [[ "$target_node" == "$SOURCE_NODE" ]]; then
    qm "$@"
  else
    ssh -o StrictHostKeyChecking=accept-new root@"$target_node_ip" qm "$@"
  fi
}

ensure_cloudinit_attached() {
  local vmid="$1"
  local target_node="$2"
  local target_node_ip="$3"
  local config ci_ref

  config="$(qm_capture_on_target "$target_node" "$target_node_ip" config "$vmid")"

  if printf '%s\n' "$config" | awk -F': ' '$1 == "ide2" && $2 ~ /cloudinit/ { found=1 } END { exit(found ? 0 : 1) }'; then
    return
  fi

  ci_ref="$(printf '%s\n' "$config" | awk -F': ' '$2 ~ /cloudinit/ { print $2; exit }')"
  ci_ref="${ci_ref%%,*}"

  if [[ -n "$ci_ref" ]]; then
    qm_on_target "$target_node" "$target_node_ip" set "$vmid" --ide2 "${ci_ref},media=cdrom"
  else
    qm_on_target "$target_node" "$target_node_ip" set "$vmid" --ide2 "${STORAGE}:cloudinit,media=cdrom"
  fi
}

create_dns_vm() {
  local vmid="$1"
  local name="$2"
  local ipcidr="$3"
  local gw="$4"
  local target_node="$5"
  local target_node_ip="$6"
  local snippet_name="$7"
  local sshkey_remote_file="${8:-}"
  local nameserver_csv
  local nameserver_primary

  nameserver_csv="${UPSTREAM_DNS// /,}"
  nameserver_csv="${nameserver_csv//,,/,}"
  nameserver_primary="${nameserver_csv%%,*}"

  echo "=== Deploying ${name} (VMID ${vmid}) on node ${target_node} ==="

  if qm status "$vmid" >/dev/null 2>&1; then
    echo "VMID ${vmid} already exists, skipping."
    return
  fi

  # local-lvm is not shared; clone on source then migrate with local disks when needed
  if [[ "$target_node" == "$SOURCE_NODE" ]]; then
    qm clone "$TEMPLATE_VMID" "$vmid" \
      --name "$name" \
      --full 1 \
      --storage "$STORAGE"
  else
    qm clone "$TEMPLATE_VMID" "$vmid" \
      --name "$name" \
      --full 1 \
      --storage "$STORAGE"

    qm migrate "$vmid" "$target_node" \
      --with-local-disks 1 \
      --targetstorage "$STORAGE"
  fi

  # Base VM settings
  qm_on_target "$target_node" "$target_node_ip" set "$vmid" \
    --cores "$CORES" \
    --memory "$MEMORY_MB" \
    --net0 "virtio,bridge=${BRIDGE}" \
    --agent enabled=1

  ensure_cloudinit_attached "$vmid" "$target_node" "$target_node_ip"

  # Cloud-init settings
  qm_on_target "$target_node" "$target_node_ip" set "$vmid" --ciuser "$CIUSER" --cipassword "$CI_PASSWORD"
  qm_on_target "$target_node" "$target_node_ip" set "$vmid" --ipconfig0 "ip=${ipcidr},gw=${gw}"
  qm_on_target "$target_node" "$target_node_ip" set "$vmid" --nameserver "$nameserver_primary" --searchdomain "infra.local"

  if [[ -n "$sshkey_remote_file" ]]; then
    qm_on_target "$target_node" "$target_node_ip" set "$vmid" --sshkeys "$sshkey_remote_file"
  fi

  # Attach custom cloud-init user-data from target node local snippets storage
  qm_on_target "$target_node" "$target_node_ip" set "$vmid" --cicustom "user=local:snippets/${snippet_name}"

  qm_on_target "$target_node" "$target_node_ip" cloudinit update "$vmid"
  qm_on_target "$target_node" "$target_node_ip" cloudinit dump "$vmid" user >/dev/null
  qm_on_target "$target_node" "$target_node_ip" cloudinit dump "$vmid" network >/dev/null

  qm_on_target "$target_node" "$target_node_ip" start "$vmid"

  echo "Started ${name}. Waiting 20s for first boot..."
  sleep 20

  # Quick reachability check
  local vm_ip
  vm_ip="$(echo "$ipcidr" | awk -F/ '{print $1}')"
  if ping -c 2 -W 2 "$vm_ip" >/dev/null 2>&1; then
    echo "${name} (${vm_ip}) responds to ping."
  else
    echo "Warning: ${name} (${vm_ip}) did not respond to ping yet."
  fi

  echo "Done: ${name}"
}

main() {
  local tmp1 tmp2
  local dns1_sshkey_remote=""
  local dns2_sshkey_remote=""
  tmp1="$(mktemp)"
  tmp2="$(mktemp)"
  trap 'rm -f "${tmp1:-}" "${tmp2:-}"' EXIT

  write_user_data "$tmp1"
  write_user_data "$tmp2"

  local snip1="dns1-userdata.yaml"
  local snip2="dns2-userdata.yaml"

  echo "Preparing snippets on target nodes..."
  prepare_snippet_on_node "$DNS1_TARGET_NODE_IP" "$snip1" "$tmp1"
  prepare_snippet_on_node "$DNS2_TARGET_NODE_IP" "$snip2" "$tmp2"

  if [[ -f "$SSH_PUBKEY_FILE" ]]; then
    dns1_sshkey_remote="/root/.ssh/dns-provisioning-key.pub"
    dns2_sshkey_remote="/root/.ssh/dns-provisioning-key.pub"
    prepare_sshkey_on_node "$DNS1_TARGET_NODE_IP" "$SSH_PUBKEY_FILE" "$dns1_sshkey_remote"
    prepare_sshkey_on_node "$DNS2_TARGET_NODE_IP" "$SSH_PUBKEY_FILE" "$dns2_sshkey_remote"
  fi

  create_dns_vm "$DNS1_VMID" "$DNS1_NAME" "$DNS1_IP" "$DNS1_GW" "$DNS1_TARGET_NODE" "$DNS1_TARGET_NODE_IP" "$snip1" "$dns1_sshkey_remote"
  create_dns_vm "$DNS2_VMID" "$DNS2_NAME" "$DNS2_IP" "$DNS2_GW" "$DNS2_TARGET_NODE" "$DNS2_TARGET_NODE_IP" "$snip2" "$dns2_sshkey_remote"

  echo
  echo "=== Completed ==="
  echo "DNS1: http://$(echo "$DNS1_IP" | awk -F/ '{print $1}'):5380"
  echo "DNS2: http://$(echo "$DNS2_IP" | awk -F/ '{print $1}'):5380"
  echo "Default Technitium UI port: 5380"
  echo
  echo "Next:"
  echo "1) Open Technitium UI on DNS1 and set admin password"
  echo "2) Create zone infra.local + reverse zone 10.10.10.in-addr.arpa"
  echo "3) Point all Proxmox nodes DNS to 10.10.10.53 and 10.10.10.54"
}

main
