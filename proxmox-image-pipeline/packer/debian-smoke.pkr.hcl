packer {
  required_plugins {
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
  type = string
}
variable "token_id" {
  type = string
}
variable "token_secret" {
  type      = string
  sensitive = true
}
variable "source_template" {
  type = number
}
variable "vm_id" {
  type = number
}

source "proxmox-clone" "debian_smoke" {
  proxmox_url              = var.proxmox_url
  username                 = var.token_id
  token                    = var.token_secret
  insecure_skip_tls_verify = true
  task_timeout             = "15m"
  qemu_agent               = false
  scsi_controller          = "virtio-scsi-pci"
  node         = var.proxmox_node
  clone_vm_id  = var.source_template
  vm_id        = var.vm_id
  vm_name      = "smoke-debian-${var.vm_id}"
  template_name = "tpl-smoke-debian-${var.vm_id}"
  full_clone   = true
  communicator = "none"
}
build {
  sources = ["source.proxmox-clone.debian_smoke"]
}
