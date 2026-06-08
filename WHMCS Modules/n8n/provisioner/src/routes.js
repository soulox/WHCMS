import { getTenant } from './store.js'

export function registerRoutes(app, jobRunner) {
  app.get('/v1/ping', async () => ({ ok: true, version: '0.1.0' }))

  app.post('/v1/jobs/provision', async (request) => {
    return jobRunner.enqueue('create', request.body)
  })

  app.post('/v1/jobs/suspend', async (request) => {
    return jobRunner.enqueue('suspend', request.body)
  })

  app.post('/v1/jobs/unsuspend', async (request) => {
    return jobRunner.enqueue('unsuspend', request.body)
  })

  app.post('/v1/jobs/terminate', async (request) => {
    return jobRunner.enqueue('terminate', request.body)
  })

  app.post('/v1/jobs/change-package', async (request) => {
    return jobRunner.enqueue('change_package', request.body)
  })

  app.post('/v1/jobs/restart', async (request) => {
    return jobRunner.enqueue('restart', request.body)
  })

  app.post('/v1/jobs/backup', async (request) => {
    return jobRunner.enqueue('backup_now', request.body)
  })

  app.get('/v1/tenants/:externalId/status', async (request, reply) => {
    const tenant = getTenant(request.params.externalId)
    if (!tenant) {
      reply.code(404)
      return { message: 'Tenant not found' }
    }

    return {
      status: tenant.status,
      instance_url: tenant.instanceUrl || '',
      proxmox_node: tenant.proxmoxNode || '',
      container_id: tenant.containerId || null,
      version: tenant.version || 'stable'
    }
  })

  app.get('/v1/tenants/:externalId/usage', async (request, reply) => {
    const tenant = getTenant(request.params.externalId)
    if (!tenant) {
      reply.code(404)
      return { message: 'Tenant not found' }
    }

    return {
      executions_used: tenant.usage?.executionsUsed || 0,
      executions_limit: tenant.limits?.executionsPerMonth || null,
      active_workflows: tenant.usage?.activeWorkflows || 0,
      active_workflow_limit: tenant.limits?.activeWorkflows || null,
      storage_used_gb: tenant.usage?.storageUsedGb || 0,
      storage_limit_gb: tenant.resources?.diskGb || null
    }
  })
}
