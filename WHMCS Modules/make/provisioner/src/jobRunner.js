import { getPlan } from './plans.js'
import { createJob, updateJob, upsertTenant, getTenant } from './store.js'

export class JobRunner {
  constructor({ proxmoxClient, makeManager, callbackClient, retryMax, retryDelayMs }) {
    this.proxmoxClient = proxmoxClient
    this.makeManager = makeManager
    this.callbackClient = callbackClient
    this.retryMax = retryMax
    this.retryDelayMs = retryDelayMs
    this.queue = []
    this.running = false
  }

  enqueue(action, payload) {
    const plan = getPlan(payload.plan_code)
    if (!plan) {
      throw new Error(`Unknown plan_code: ${payload.plan_code}`)
    }

    const externalId = payload.external_id || `tenant_${payload.service_id}`
    const jobId = `job_${Date.now()}_${Math.floor(Math.random() * 10000)}`
    const job = createJob({
      jobId,
      action,
      payload,
      externalId,
      planCode: payload.plan_code,
      status: 'queued',
      attempts: 0,
      createdAt: new Date().toISOString(),
      updatedAt: new Date().toISOString()
    })

    this.queue.push(job.jobId)
    this.kick()

    return { job_id: job.jobId, external_id: externalId, queued_at: job.createdAt }
  }

  async kick() {
    if (this.running) return
    this.running = true
    while (this.queue.length) {
      const jobId = this.queue.shift()
      await this.process(jobId)
    }
    this.running = false
  }

  async process(jobId) {
    let job = updateJob(jobId, { status: 'running' })
    if (!job) return

    try {
      job = updateJob(jobId, { attempts: job.attempts + 1 })
      const plan = getPlan(job.planCode)
      await this.executeAction(job.action, job.externalId, job.payload, plan)

      updateJob(jobId, { status: 'completed' })
      await this.callbackClient.postStatus({
        service_id: job.payload.service_id,
        product_id: job.payload.product_id,
        job_id: jobId,
        external_id: job.externalId,
        status: this.mapWhmcsStatus(job.action)
      })
    } catch (err) {
      const failed = updateJob(jobId, {
        status: 'failed',
        lastError: err.message
      })
      const shouldRetry = failed && failed.attempts < this.retryMax
      if (shouldRetry) {
        await sleep(this.retryDelayMs)
        this.queue.push(jobId)
        updateJob(jobId, { status: 'queued' })
        return
      }

      await this.callbackClient.postStatus({
        service_id: job.payload.service_id,
        product_id: job.payload.product_id,
        job_id: jobId,
        external_id: job.externalId,
        status: 'failed',
        error_message: err.message
      })
    }
  }

  async executeAction(action, externalId, payload, plan) {
    const current = getTenant(externalId) || { externalId }

    switch (action) {
      case 'create': {
        const infra = await this.proxmoxClient.provisionTenant(payload, plan)
        const runtime = await this.makeManager.deploy(payload, externalId, infra)
        const tenant = upsertTenant(externalId, {
          ...current,
          externalId,
          planCode: payload.plan_code,
          proxmoxNode: infra.proxmoxNode,
          guestId: infra.guestId,
          guestType: infra.guestType,
          resources: infra.resources,
          limits: plan.limits,
          status: 'active',
          instanceUrl: runtime.instanceUrl,
          usage: {
            operationsUsed: 0,
            activeScenarios: 0,
            storageUsedGb: 0
          }
        })
        await this.callbackClient.postStatus({
          service_id: payload.service_id,
          product_id: payload.product_id,
          job_id: 'pending',
          external_id: externalId,
          status: 'active',
          instance_url: tenant.instanceUrl
        })
        return
      }
      case 'change_package': {
        const resized = await this.proxmoxClient.resizeTenant(current, plan)
        const tenant = upsertTenant(externalId, {
          ...current,
          externalId,
          planCode: payload.plan_code,
          limits: plan.limits,
          resources: resized.resources
        })
        await this.makeManager.applyPlanLimits(tenant, plan)
        return
      }
      case 'suspend': {
        await this.proxmoxClient.suspendTenant(current)
        upsertTenant(externalId, { ...current, status: 'suspended' })
        return
      }
      case 'unsuspend': {
        await this.proxmoxClient.unsuspendTenant(current)
        upsertTenant(externalId, { ...current, status: 'active' })
        return
      }
      case 'terminate': {
        await this.proxmoxClient.terminateTenant(current)
        upsertTenant(externalId, { ...current, status: 'terminated' })
        return
      }
      case 'restart': {
        await this.proxmoxClient.restartTenant(current)
        upsertTenant(externalId, { ...current, status: 'active' })
        return
      }
      case 'backup_now': {
        const tenant = upsertTenant(externalId, { ...current, externalId })
        await this.proxmoxClient.snapshotTenant(tenant, 'manual')
        await this.makeManager.runBackup(tenant, payload.backup_retention_days || null)
        return
      }
      default:
        throw new Error(`Unsupported action: ${action}`)
    }
  }

  mapWhmcsStatus(action) {
    if (action === 'suspend') return 'suspended'
    if (action === 'terminate') return 'terminated'
    return 'active'
  }
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}
