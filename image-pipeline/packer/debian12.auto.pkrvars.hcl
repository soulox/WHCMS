proxmox_url = "https://10.10.10.26:8006/api2/json"
proxmox_node = "pve26"

token_id = "root@pam!packer"
token_secret = ""

source_template = 9200

vm_id = 9300
vm_name = "build-debian12-9300"
template_name = "tpl-whmcs-debian12-v1-pve26"

ssh_username = "debian"
ssh_host = "10.10.10.62"

insecure_skip_tls_verify = true
