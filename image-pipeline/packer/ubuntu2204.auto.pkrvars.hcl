proxmox_url = "https://10.10.10.26:8006/api2/json"
proxmox_node = "pve26"

token_id = "root@pam!packer"
token_secret = ""

source_template = 9201
vm_id = 9301
vm_name = "build-ubuntu2204-9301"
template_name = "tpl-whmcs-ubuntu2204-v1-pve26"

ssh_username = "ubuntu"
ssh_host = "10.10.10.63"

playbook_file = "harden-ubuntu.yml"
insecure_skip_tls_verify = true
