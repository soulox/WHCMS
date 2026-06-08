proxmox_url = "https://10.10.10.26:8006/api2/json"
proxmox_node = "pve26"

token_id = "root@pam!packer"
token_secret = ""

source_template = 9213
vm_id = 9303
vm_name = "build-almalinux9-9303"
template_name = "tpl-whmcs-almalinux9-v1-pve26"

ssh_username = "almalinux"
ssh_host = "10.10.10.65"

playbook_file = "harden-rhel9.yml"
insecure_skip_tls_verify = true
