import Fastify from 'fastify'
import crypto from 'crypto'
import { config, validateConfig } from './config.js'
import { PctExec } from './pctExec.js'
import { buildComposeYaml, buildEnvFile, buildLimitsFile } from './templates.js'

async function main() {
  validateConfig()

  const app = Fastify({ logger: true })
  const pct = new PctExec({ pctBin: config.pctBin })

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
    const instanceUrl = asRequired(body.instance_url, 'instance_url')

    const compose = buildComposeYaml({
      n8nImage: config.n8nImage,
      postgresImage: config.postgresImage
    })
    const envFile = buildEnvFile({
      instanceUrl,
      timezone: config.timezone,
      customerEmail: body.customer?.email || ''
    })

    await ensureContainerRuntime(pct, vmid)
    await pct.exec(vmid, 'mkdir -p /opt/n8n')
    await pct.push(vmid, '/opt/n8n/docker-compose.yml', compose)
    await pct.push(vmid, '/opt/n8n/.env', envFile)

    await pct.exec(vmid, 'cd /opt/n8n && docker compose pull')
    await pct.exec(vmid, 'cd /opt/n8n && docker compose up -d')

    return {
      ok: true,
      vmid,
      message: 'n8n deployed in container'
    }
  })

  app.post('/hooks/limits', async (request) => {
    const body = request.body || {}
    const vmid = asPositiveInt(body.vmid, 'vmid')

    const limitsDoc = buildLimitsFile({
      limits: body.limits || {},
      features: body.features || {}
    })

    await pct.exec(vmid, 'mkdir -p /opt/n8n')
    await pct.push(vmid, '/opt/n8n/plan-limits.json', limitsDoc)

    return {
      ok: true,
      vmid,
      message: 'limits file updated'
    }
  })

  app.post('/hooks/backup', async (request) => {
    const body = request.body || {}
    const vmid = asPositiveInt(body.vmid, 'vmid')
    const retentionDays = Number(body.retention_days || config.backupRetentionDays)
    const stamp = new Date().toISOString().replace(/[:.]/g, '-')
    const backupFile = `/opt/n8n/backups/db-${stamp}.sql.gz`

    await pct.exec(vmid, 'mkdir -p /opt/n8n/backups')
    await pct.exec(
      vmid,
      `cd /opt/n8n && docker compose exec -T postgres sh -lc "pg_dump -U \"$DB_POSTGRESDB_USER\" \"$DB_POSTGRESDB_DATABASE\"" | gzip > '${backupFile}'`
    )
    await pct.exec(
      vmid,
      `find /opt/n8n/backups -type f -name '*.sql.gz' -mtime +${Math.max(retentionDays, 1)} -delete`
    )

    return {
      ok: true,
      vmid,
      backup_id: `db-${stamp}`,
      backup_file: backupFile
    }
  })

  await app.listen({ host: config.host, port: config.port })
  app.log.info(`Deploy-hook listening on ${config.host}:${config.port}`)
}

async function ensureContainerRuntime(pct, vmid) {
  await pct.exec(vmid, 'apt-get update')
  await pct.exec(vmid, 'DEBIAN_FRONTEND=noninteractive apt-get install -y ca-certificates curl gnupg lsb-release')
  await pct.exec(vmid, 'command -v docker >/dev/null 2>&1 || (curl -fsSL https://get.docker.com | sh)')
  await pct.exec(vmid, 'mkdir -p /usr/local/lib/docker/cli-plugins')
  await pct.exec(
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
