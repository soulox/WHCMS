const config = {
  apiUrl: process.env.PROVISIONER_API_URL || 'http://127.0.0.1:8080',
  token: process.env.PROVISIONER_API_TOKEN || '',
  serviceId: Number(process.env.TEST_SERVICE_ID || 10001),
  productId: Number(process.env.TEST_PRODUCT_ID || 20001),
  clientId: Number(process.env.TEST_CLIENT_ID || 30001),
  planCode: process.env.TEST_PLAN_CODE || 'make-starter',
  region: process.env.TEST_REGION || 'default',
  runtimeChannel: process.env.TEST_RUNTIME_CHANNEL || 'stable',
  backupRetentionDays: Number(process.env.TEST_BACKUP_RETENTION_DAYS || 7),
  timeoutMs: Number(process.env.TEST_JOB_TIMEOUT_MS || 300000),
  pollMs: Number(process.env.TEST_JOB_POLL_MS || 2000),
  dryRun: String(process.env.TEST_DRY_RUN || 'false').toLowerCase() === 'true'
}

if (!config.token && !config.dryRun) {
  throw new Error('Set PROVISIONER_API_TOKEN before running lifecycle test.')
}

const basePayload = {
  service_id: config.serviceId,
  client_id: config.clientId,
  product_id: config.productId,
  plan_code: config.planCode,
  region: config.region,
  runtime_channel: config.runtimeChannel,
  backup_retention_days: config.backupRetentionDays,
  hostname: `svc-${config.serviceId}.example.local`,
  username: '',
  password: '',
  email: 'staging@example.local',
  firstname: 'Staging',
  lastname: 'Test',
  custom_domain: ''
}

async function main() {
  console.log('Starting staging lifecycle test...')
  if (config.dryRun) {
    console.log('TEST_DRY_RUN=true, no API calls will be sent.')
    console.log(JSON.stringify({
      create: '/v1/jobs/provision',
      suspend: '/v1/jobs/suspend',
      unsuspend: '/v1/jobs/unsuspend',
      changePackage: '/v1/jobs/change-package',
      backup: '/v1/jobs/backup',
      terminate: '/v1/jobs/terminate',
      payload: basePayload
    }, null, 2))
    console.log('Dry run completed.')
    return
  }

  const queuedCreate = await queue('/v1/jobs/provision', basePayload)
  const externalId = queuedCreate.external_id
  await waitForJob(queuedCreate.job_id)

  const actions = [
    ['/v1/jobs/suspend', 'suspend'],
    ['/v1/jobs/unsuspend', 'unsuspend'],
    ['/v1/jobs/change-package', 'change-package'],
    ['/v1/jobs/backup', 'backup'],
    ['/v1/jobs/terminate', 'terminate']
  ]

  for (const [endpoint, name] of actions) {
    console.log(`Queueing ${name}...`)
    const queued = await queue(endpoint, {
      ...basePayload,
      external_id: externalId
    })
    await waitForJob(queued.job_id)
  }

  console.log('Lifecycle test completed successfully.')
}

async function queue(endpoint, payload) {
  const res = await fetch(`${trimSlash(config.apiUrl)}${endpoint}`, {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(payload)
  })
  const body = await readJson(res)
  if (!res.ok) {
    throw new Error(`Queue request failed (${res.status}): ${JSON.stringify(body)}`)
  }
  if (!body.job_id) {
    throw new Error(`Queue response missing job_id: ${JSON.stringify(body)}`)
  }

  console.log(`Queued ${body.job_id}`)
  return body
}

async function waitForJob(jobId) {
  const deadline = Date.now() + config.timeoutMs
  while (Date.now() < deadline) {
    const res = await fetch(`${trimSlash(config.apiUrl)}/v1/jobs/${encodeURIComponent(jobId)}`, {
      method: 'GET',
      headers: authHeaders()
    })
    const body = await readJson(res)
    if (!res.ok) {
      throw new Error(`Job status failed (${res.status}): ${JSON.stringify(body)}`)
    }

    const status = String(body.status || '')
    if (status === 'completed') {
      console.log(`Job ${jobId} completed.`)
      return body
    }
    if (status === 'failed') {
      throw new Error(`Job ${jobId} failed: ${body.lastError || 'unknown error'}`)
    }

    await sleep(config.pollMs)
  }

  throw new Error(`Timed out waiting for job ${jobId}`)
}

function authHeaders() {
  return {
    'Content-Type': 'application/json',
    Authorization: `Bearer ${config.token}`
  }
}

function trimSlash(v) {
  return String(v).replace(/\/+$/, '')
}

async function readJson(res) {
  const raw = await res.text()
  if (!raw) return {}
  try {
    return JSON.parse(raw)
  } catch {
    return { raw }
  }
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

main().catch((err) => {
  console.error(err.message || err)
  process.exit(1)
})
