export class MakeManager {
  constructor(options) {
    this.options = options
  }

  async deploy(payload, externalId, infra) {
    const instanceUrl = this.resolveInstanceUrl(payload, externalId)
    const channel = payload.runtime_channel || 'stable'

    if (this.options.deployHookUrl) {
      await this.callHook(this.options.deployHookUrl, this.options.deployHookToken, {
        action: 'deploy',
        service_id: payload.service_id,
        external_id: externalId,
        node: infra?.proxmoxNode || '',
        vmid: infra?.guestId || null,
        guest_type: infra?.guestType || 'lxc',
        instance_url: instanceUrl,
        runtime_channel: channel,
        customer: {
          email: payload.email,
          firstname: payload.firstname,
          lastname: payload.lastname
        }
      })
    }

    await this.waitForHealth(instanceUrl)

    return {
      instanceUrl,
      channel,
      status: 'healthy'
    }
  }

  async applyPlanLimits(tenant, plan) {
    if (!this.options.limitsHookUrl) {
      return
    }

    await this.callHook(this.options.limitsHookUrl, this.options.limitsHookToken, {
      action: 'apply_limits',
      external_id: tenant.externalId,
      vmid: tenant.guestId,
      guest_type: tenant.guestType,
      node: tenant.proxmoxNode,
      limits: plan.limits,
      features: plan.features
    })
  }

  async runBackup(tenant, retentionDays = null) {
    if (!this.options.backupHookUrl) {
      return { backupId: `bkp_${Date.now()}` }
    }

    const response = await this.callHook(this.options.backupHookUrl, this.options.backupHookToken, {
      action: 'backup_now',
      external_id: tenant.externalId,
      vmid: tenant.guestId,
      guest_type: tenant.guestType,
      node: tenant.proxmoxNode,
      retention_days: retentionDays
    })

    return {
      backupId: response?.backup_id || `bkp_${Date.now()}`
    }
  }

  resolveInstanceUrl(payload, externalId) {
    if (payload.custom_domain) {
      return `${this.options.defaultScheme}://${payload.custom_domain}`
    }

    const base = String(this.options.publicBaseDomain || '').replace(/^\.+|\.+$/g, '')
    const subdomain = normalizeSegment(externalId)
    return `${this.options.defaultScheme}://${subdomain}.${base}`
  }

  async waitForHealth(instanceUrl) {
    const deadline = Date.now() + this.options.deployTimeoutMs
    const path = this.options.healthCheckPath.startsWith('/')
      ? this.options.healthCheckPath
      : `/${this.options.healthCheckPath}`

    while (Date.now() < deadline) {
      const ok = await this.checkHealth(`${instanceUrl}${path}`)
      if (ok) return
      await sleep(this.options.deployPollMs)
    }

    throw new Error(`Make health check timed out for ${instanceUrl}`)
  }

  async checkHealth(url) {
    try {
      const response = await fetch(url, { method: 'GET' })
      return response.ok
    } catch {
      return false
    }
  }

  async callHook(url, token, payload) {
    const headers = { 'Content-Type': 'application/json' }
    if (token) {
      headers.Authorization = `Bearer ${token}`
    }

    const response = await fetch(url, {
      method: 'POST',
      headers,
      body: JSON.stringify(payload)
    })

    const raw = await response.text()
    let parsed = null
    if (raw) {
      try {
        parsed = JSON.parse(raw)
      } catch {
        parsed = { raw }
      }
    }

    if (!response.ok) {
      throw new Error(`Make hook failed (${response.status}): ${raw}`)
    }

    return parsed
  }
}

function normalizeSegment(input) {
  return String(input)
    .toLowerCase()
    .replace(/[^a-z0-9-]/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '')
    .slice(0, 50)
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}
