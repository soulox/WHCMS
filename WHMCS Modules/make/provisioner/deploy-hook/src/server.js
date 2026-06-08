import Fastify from 'fastify'
import crypto from 'crypto'
import { config, validateConfig } from './config.js'
import { PctExec } from './pctExec.js'
import { QmExec } from './qmExec.js'
import { buildComposeYaml, buildEnvFile, buildLimitsFile } from './templates.js'

async function main() {
  validateConfig()

  const app = Fastify({ logger: true })
  const pct = new PctExec({ pctBin: config.pctBin })
  const qm = new QmExec({ qmBin: config.qmBin })

  app.addHook('preHandler', async (request, reply) => {
    if (request.url === '/health') return
    const header = request.headers.authorization || ''
    if (!header.startsWith('Bearer ')) {
      reply.code(401).send({ message: 'Missing bearer token' })
      return
    }

    const token = header.slice(7).trim()
    if (!safeEqual(token, config.hookBearerToken)) {
      reply.code(401).send({ message: 'Invalid bearer token' })
    }
  })

  app.get('/health', async () => ({ ok: true }))

  app.post('/hooks/deploy', async (request) => {
    const body = request.body || {}
    const vmid = asPositiveInt(body.vmid, 'vmid')
    const guestType = normalizeGuestType(body.guest_type)
    const instanceUrl = asRequired(body.instance_url, 'instance_url')
    const runtimeChannel = String(body.runtime_channel || 'stable')
    const execer = guestType === 'qemu' ? qm : pct

    const compose = buildComposeYaml({
      makeImage: config.makeImage,
      postgresImage: config.postgresImage
    })
    const envFile = buildEnvFile({
      instanceUrl,
      timezone: config.timezone,
      runtimeChannel,
      customerEmail: body.customer?.email || ''
    })

    await ensureGuestRuntime(execer, vmid)
    await execer.exec(vmid, 'mkdir -p /opt/make')
    await execer.push(vmid, '/opt/make/docker-compose.yml', compose)
    await execer.push(vmid, '/opt/make/.env', envFile)
    await execer.exec(vmid, 'cd /opt/make && docker compose pull')
    await execer.exec(vmid, 'cd /opt/make && docker compose up -d')

    return {
      ok: true,
      vmid,
      guest_type: guestType,
      message: 'Make runtime deployed'
    }
  })

  app.post('/hooks/limits', async (request) => {
    const body = request.body || {}
    const vmid = asPositiveInt(body.vmid, 'vmid')
    const guestType = normalizeGuestType(body.guest_type)
    const execer = guestType === 'qemu' ? qm : pct

    const limitsDoc = buildLimitsFile({
      limits: body.limits || {},
      features: body.features || {}
    })

    await execer.exec(vmid, 'mkdir -p /opt/make')
    await execer.push(vmid, '/opt/make/plan-limits.json', limitsDoc)

    return {
      ok: true,
      vmid,
      guest_type: guestType,
      message: 'Plan limits file updated'
    }
  })

  app.post('/hooks/backup', async (request) => {
    const body = request.body || {}
    const vmid = asPositiveInt(body.vmid, 'vmid')
    const guestType = normalizeGuestType(body.guest_type)
    const execer = guestType === 'qemu' ? qm : pct

    const retentionDays = Number(body.retention_days || config.backupRetentionDays)
    const stamp = new Date().toISOString().replace(/[:.]/g, '-')
    const backupFile = `/opt/make/backups/db-${stamp}.sql.gz`

    await execer.exec(vmid, 'mkdir -p /opt/make/backups')
    await execer.exec(
      vmid,
      `cd /opt/make && docker compose exec -T postgres sh -lc "pg_dump -U \"$DB_POSTGRESDB_USER\" \"$DB_POSTGRESDB_DATABASE\"" | gzip > '${backupFile}'`
    )
    await execer.exec(
      vmid,
      `find /opt/make/backups -type f -name '*.sql.gz' -mtime +${Math.max(retentionDays, 1)} -delete`
    )

    return {
      ok: true,
      vmid,
      guest_type: guestType,
      backup_id: `db-${stamp}`,
      backup_file: backupFile
    }
  })

  await app.listen({ host: config.host, port: config.port })
  app.log.info(`Deploy-hook listening on ${config.host}:${config.port}`)
}

async function ensureGuestRuntime(execer, vmid) {
  await execer.exec(vmid, 'apt-get update')
  await execer.exec(vmid, 'DEBIAN_FRONTEND=noninteractive apt-get install -y ca-certificates curl gnupg lsb-release')
  await execer.exec(vmid, 'command -v docker >/dev/null 2>&1 || (curl -fsSL https://get.docker.com | sh)')
  await execer.exec(vmid, 'mkdir -p /usr/local/lib/docker/cli-plugins')
  await execer.exec(
    vmid,
    'test -x /usr/local/lib/docker/cli-plugins/docker-compose || (curl -SL https://github.com/docker/compose/releases/download/v2.30.3/docker-compose-linux-x86_64 -o /usr/local/lib/docker/cli-plugins/docker-compose && chmod +x /usr/local/lib/docker/cli-plugins/docker-compose)'
  )
}

function asRequired(value, name) {
  if (!value || String(value).trim() === '') {
    throw new Error(`${name} is required`)
  }
  return String(value).trim()
}

function asPositiveInt(value, name) {
  const int = Number(value)
  if (!Number.isInteger(int) || int <= 0) {
    throw new Error(`${name} must be a positive integer`)
  }
  return int
}

function normalizeGuestType(value) {
  return String(value || 'lxc').toLowerCase() === 'qemu' ? 'qemu' : 'lxc'
}

function safeEqual(a, b) {
  const ax = Buffer.from(String(a))
  const bx = Buffer.from(String(b))
  if (ax.length !== bx.length) return false
  return crypto.timingSafeEqual(ax, bx)
}

main().catch((err) => {
  console.error(err)
  process.exit(1)
})
