packer {
  required_plugins {
    proxmox = {
      source  = "github.com/hashicorp/proxmox"
      version = ">= 1.1.0"
    }
  }
}

variable "proxmox_url" { type = string }
variable "proxmox_node" { type = string }
variable "token_id" { type = string }
variable "token_secret" {
  type      = string
  sensitive = true
}
variable "source_template" { type = number }
variable "vm_id" { type = number }
variable "vm_name" { type = string }
variable "template_name" { type = string }

source "proxmox-clone" "rhel9_pass" {
  proxmox_url              = var.proxmox_url
  username                 = var.token_id
  token                    = var.token_secret
  insecure_skip_tls_verify = true

  node            = var.proxmox_node
  clone_vm_id     = var.source_template
  vm_id           = var.vm_id
  vm_name         = var.vm_name
  template_name   = var.template_name
  full_clone      = true
  qemu_agent      = false
  scsi_controller = "virtio-scsi-pci"
  task_timeout    = "20m"

  communicator = "none"
}

build {
  sources = ["source.proxmox-clone.rhel9_pass"]
}
