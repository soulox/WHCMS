proxmox_url = "https://10.10.10.26:8006/api2/json"
proxmox_node = "pve26"

token_id = "root@pam!packer"
token_secret = ""

source_template = 9202
vm_id = 9302
vm_name = "build-ubuntu2404-9302"
template_name = "tpl-whmcs-ubuntu2404-v1-pve26"

ssh_username = "ubuntu"
ssh_host = "10.10.10.64"

playbook_file = "harden-ubuntu.yml"
insecure_skip_tls_verify = true
