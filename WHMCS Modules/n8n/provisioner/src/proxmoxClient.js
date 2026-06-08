export class ProxmoxClient {
  constructor(options) {
    this.options = options
    this.baseUrl = String(options.apiUrl || '').replace(/\/+$/, '')

    if (options.tlsInsecure) {
      process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0'
    }
  }

  async provisionTenant(payload, plan) {
    const node = this.pickNode(payload.region)
    const vmid = await this.getNextVmid()
    const hostname = buildHostname(payload, vmid)

    const cloneTask = await this.requestNodeTask(
      node,
      'POST',
      `/nodes/${node}/lxc/${this.options.lxcTemplateVmid}/clone`,
      {
        newid: vmid,
        hostname,
        full: 1,
        storage: this.options.storage,
        target: node
      }
    )
    await this.waitForTask(node, cloneTask)

    const configTask = await this.requestNodeTask(node, 'PUT', `/nodes/${node}/lxc/${vmid}/config`, {
      cores: plan.resources.cpuCores,
      memory: plan.resources.memoryMb,
      swap: this.options.swapMb,
      net0: `name=eth0,bridge=${this.options.bridge},ip=dhcp`,
      onboot: 1,
      tags: `whmcs;service-${payload.service_id};plan-${payload.plan_code}`
    })
    await this.waitForTask(node, configTask)

    const diskTask = await this.requestNodeTask(node, 'PUT', `/nodes/${node}/lxc/${vmid}/resize`, {
      disk: 'rootfs',
      size: `${plan.resources.diskGb}G`
    })
    await this.waitForTask(node, diskTask)

    const startTask = await this.requestNodeTask(node, 'POST', `/nodes/${node}/lxc/${vmid}/status/start`)
    await this.waitForTask(node, startTask)

    return {
      proxmoxNode: node,
      containerId: vmid,
      resources: plan.resources,
      status: 'running'
    }
  }

  async resizeTenant(tenant, plan) {
    const { node, vmid } = this.assertTenantRuntime(tenant)

    const cfgTask = await this.requestNodeTask(node, 'PUT', `/nodes/${node}/lxc/${vmid}/config`, {
      cores: plan.resources.cpuCores,
      memory: plan.resources.memoryMb,
      swap: this.options.swapMb
    })
    await this.waitForTask(node, cfgTask)

    const currentDisk = Number(tenant.resources?.diskGb || 0)
    const targetDisk = Number(plan.resources.diskGb || 0)
    if (targetDisk > currentDisk) {
      const delta = targetDisk - currentDisk
      const diskTask = await this.requestNodeTask(node, 'PUT', `/nodes/${node}/lxc/${vmid}/resize`, {
        disk: 'rootfs',
        size: `+${delta}G`
      })
      await this.waitForTask(node, diskTask)
    }

    return { resources: plan.resources }
  }

  async suspendTenant(tenant) {
    const { node, vmid } = this.assertTenantRuntime(tenant)
    const task = await this.requestNodeTask(node, 'POST', `/nodes/${node}/lxc/${vmid}/status/suspend`)
    await this.waitForTask(node, task)
    return { status: 'suspended' }
  }

  async unsuspendTenant(tenant) {
    const { node, vmid } = this.assertTenantRuntime(tenant)
    const task = await this.requestNodeTask(node, 'POST', `/nodes/${node}/lxc/${vmid}/status/resume`)
    await this.waitForTask(node, task)
    return { status: 'running' }
  }

  async terminateTenant(tenant) {
    const { node, vmid } = this.assertTenantRuntime(tenant)

    const stopTask = await this.requestNodeTask(node, 'POST', `/nodes/${node}/lxc/${vmid}/status/stop`, {
      timeout: 30
    })
    await this.waitForTask(node, stopTask, true)

    const delTask = await this.requestNodeTask(node, 'DELETE', `/nodes/${node}/lxc/${vmid}`, {
      purge: 1,
      'destroy-unreferenced-disks': 1
    })
    await this.waitForTask(node, delTask)

    return { status: 'terminated' }
  }

  async restartTenant(tenant) {
    const { node, vmid } = this.assertTenantRuntime(tenant)
    const task = await this.requestNodeTask(node, 'POST', `/nodes/${node}/lxc/${vmid}/status/reboot`)
    await this.waitForTask(node, task)
    return { status: 'running' }
  }

  async snapshotTenant(tenant, label = 'manual') {
    const { node, vmid } = this.assertTenantRuntime(tenant)
    const name = `${label}-${new Date().toISOString().replace(/[:.]/g, '-')}`
    const task = await this.requestNodeTask(node, 'POST', `/nodes/${node}/lxc/${vmid}/snapshot`, {
      snapname: name,
      description: `Created by n8n provisioner (${label})`
    })
    await this.waitForTask(node, task)
    return { snapshotName: name }
  }

  pickNode(region) {
    if (!region) {
      return this.options.defaultNode
    }
    return this.options.regionNodeMap[region] || this.options.defaultNode
  }

  assertTenantRuntime(tenant) {
    const node = tenant.proxmoxNode
    const vmid = tenant.containerId
    if (!node || !vmid) {
      throw new Error('Tenant runtime metadata missing (proxmoxNode/containerId).')
    }
    return { node, vmid }
  }

  async getNextVmid() {
    const response = await this.request('GET', '/cluster/nextid')
    return Number(response.data)
  }

  async requestNodeTask(node, method, path, body) {
    const response = await this.request(method, path, body)
    const upid = response.data
    if (!upid || typeof upid !== 'string') {
      throw new Error(`Unexpected Proxmox task response for ${path}`)
    }
    return upid
  }

  async waitForTask(node, upid, ignoreStopError = false) {
    const encoded = encodeURIComponent(upid)
    const maxAttempts = 120

    for (let i = 0; i < maxAttempts; i += 1) {
      const response = await this.request('GET', `/nodes/${node}/tasks/${encoded}/status`)
      const data = response.data || {}
      if (data.status === 'stopped') {
        const exitStatus = String(data.exitstatus || '')
        if (exitStatus !== 'OK') {
          if (ignoreStopError && /does not exist|not running/i.test(exitStatus)) {
            return
          }
          throw new Error(`Proxmox task failed: ${exitStatus}`)
        }
        return
      }

      await sleep(1000)
    }

    throw new Error('Proxmox task timed out.')
  }

  async request(method, path, body) {
    const url = `${this.baseUrl}${path.startsWith('/') ? path : `/${path}`}`
    const headers = {
      Authorization: `PVEAPIToken=${this.options.apiTokenId}=${this.options.apiTokenSecret}`
    }

    let payload
    if (body && method !== 'GET') {
      payload = new URLSearchParams()
      Object.entries(body).forEach(([key, value]) => {
        if (value === undefined || value === null) return
        payload.append(key, String(value))
      })
      headers['Content-Type'] = 'application/x-www-form-urlencoded'
    }

    const response = await fetch(url, {
      method,
      headers,
      body: payload
    })

    const raw = await response.text()
    let json
    try {
      json = JSON.parse(raw)
    } catch {
      throw new Error(`Invalid Proxmox response (${response.status}): ${raw}`)
    }

    if (!response.ok) {
      const message = json?.errors ? JSON.stringify(json.errors) : json?.message || raw
      throw new Error(`Proxmox API error (${response.status}): ${message}`)
    }

    return json
  }
}

function buildHostname(payload, vmid) {
  const raw = payload.hostname || payload.custom_domain || `n8n-${payload.service_id}`
  const normalized = String(raw)
    .toLowerCase()
    .replace(/[^a-z0-9-]/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '')

  if (normalized.length >= 3) {
    return normalized.slice(0, 63)
  }
  return `n8n-${vmid}`
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}
