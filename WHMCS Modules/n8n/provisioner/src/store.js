const jobs = new Map()
const tenants = new Map()

export function createJob(job) {
  jobs.set(job.jobId, job)
  return job
}

export function getJob(jobId) {
  return jobs.get(jobId) || null
}

export function updateJob(jobId, updates) {
  const current = jobs.get(jobId)
  if (!current) return null
  const next = { ...current, ...updates, updatedAt: new Date().toISOString() }
  jobs.set(jobId, next)
  return next
}

export function upsertTenant(externalId, data) {
  const next = {
    ...(tenants.get(externalId) || {}),
    ...data,
    updatedAt: new Date().toISOString()
  }
  tenants.set(externalId, next)
  return next
}

export function getTenant(externalId) {
  return tenants.get(externalId) || null
}
