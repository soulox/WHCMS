proxmox_url = "https://10.10.10.27:8006/api2/json"
proxmox_node = "pve27"

# Token format example: "root@pam!packer"
token_id = "root@pam!packer"
token_secret = "REPLACE_WITH_PROXMOX_API_TOKEN_SECRET"

# Existing cloud-init-capable template to clone from.
source_template = 9001

# New VMID used during build.
vm_id = 9201
vm_name = "build-debian12-9201"

# Final Proxmox template name after successful provisioning.
template_name = "tpl-whmcs-debian12-v1"

# Guest credentials for the source template.
ssh_username = "debian"
ssh_password = "REPLACE_WITH_TEMPLATE_SSH_PASSWORD"

insecure_skip_tls_verify = true
