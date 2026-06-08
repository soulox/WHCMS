proxmox_url = "https://10.10.10.26:8006/api2/json"
proxmox_node = "pve26"

token_id = "root@pam!packer"
token_secret = ""

source_template = 9214
vm_id = 9304
vm_name = "build-rocky9-9304"
template_name = "tpl-whmcs-rocky9-v1-pve26"

ssh_username = "rocky"
ssh_host = "10.10.10.66"

playbook_file = "harden-rhel9.yml"
insecure_skip_tls_verify = true
