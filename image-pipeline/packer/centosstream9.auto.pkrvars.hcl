proxmox_url = "https://10.10.10.26:8006/api2/json"
proxmox_node = "pve26"

token_id = "root@pam!packer"
token_secret = ""

source_template = 9215
vm_id = 9305
vm_name = "build-centosstream9-9305"
template_name = "tpl-whmcs-centosstream9-v1-pve26"

ssh_username = "cloud-user"
ssh_host = "10.10.10.67"

playbook_file = "harden-rhel9.yml"
insecure_skip_tls_verify = true
