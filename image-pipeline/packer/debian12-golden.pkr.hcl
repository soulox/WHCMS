packer {
  required_plugins {
    ansible = {
      source  = "github.com/hashicorp/ansible"
      version = ">= 1.1.0"
    }
    proxmox = {
      source  = "github.com/hashicorp/proxmox"
      version = ">= 1.1.0"
    }
  }
}

variable "proxmox_url" {
  type = string
}

variable "proxmox_node" {
  type    = string
  default = "pve27"
}

variable "token_id" {
  type = string
}

variable "token_secret" {
  type      = string
  sensitive = true
}

variable "source_template" {
  type    = number
  default = 9001
}

variable "vm_id" {
  type    = number
  default = 9201
}

variable "vm_name" {
  type    = string
  default = "build-debian12-9201"
}

variable "template_name" {
  type    = string
  default = "tpl-whmcs-debian12-v1"
}

variable "ssh_username" {
  type    = string
  default = "debian"
}

variable "ssh_host" {
  type    = string
  default = ""
}

variable "insecure_skip_tls_verify" {
  type    = bool
  default = true
}

variable "playbook_file" {
  type    = string
  default = "harden-debian12.yml"
}

variable "memory" {
  type    = number
  default = 2048
}

variable "cores" {
  type    = number
  default = 2
}

source "proxmox-clone" "debian12_golden" {
  proxmox_url              = var.proxmox_url
  username                 = var.token_id
  token                    = var.token_secret
  insecure_skip_tls_verify = var.insecure_skip_tls_verify
  task_timeout             = "15m"
  qemu_agent               = true
  scsi_controller          = "virtio-scsi-pci"

  node        = var.proxmox_node
  clone_vm_id = var.source_template
  vm_id       = var.vm_id

  vm_name       = var.vm_name
  template_name = var.template_name
  full_clone    = true
  memory        = var.memory
  cores         = var.cores
  os            = "l26"

  communicator = "ssh"
  ssh_username = var.ssh_username
  ssh_timeout  = "20m"
}

build {
  sources = ["source.proxmox-clone.debian12_golden"]

  provisioner "ansible" {
    playbook_file = "${path.root}/../ansible/${var.playbook_file}"
    user          = var.ssh_username
    use_proxy     = false
    extra_arguments = [
      "-e",
      "ansible_ssh_common_args='-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null'"
    ]
    ansible_env_vars = [
      "ANSIBLE_HOST_KEY_CHECKING=False"
    ]
  }
}
