import Fastify from 'fastify'
import { config, validateConfig } from './config.js'
import { registerAuthHooks } from './auth.js'
import { registerRoutes } from './routes.js'
import { ProxmoxClient } from './proxmoxClient.js'
import { N8nManager } from './n8nManager.js'
import { CallbackClient } from './callbackClient.js'
import { JobRunner } from './jobRunner.js'

async function main() {
  validateConfig()

  const app = Fastify({ logger: true })
  registerAuthHooks(app, config.apiBearerToken)

  const callbackClient = new CallbackClient({
    url: config.callbackUrl,
    bearerToken: config.callbackBearerToken,
    hmacSecret: config.callbackHmacSecret
  })

  const jobRunner = new JobRunner({
    proxmoxClient: new ProxmoxClient(config.proxmox),
    n8nManager: new N8nManager(config.n8n),
    callbackClient,
    retryMax: config.retryMax,
    retryDelayMs: config.retryDelayMs
  })

  registerRoutes(app, jobRunner)

  await app.listen({ host: config.host, port: config.port })
  app.log.info(`Provisioner listening on ${config.host}:${config.port}`)
}

main().catch((err) => {
  console.error(err)
  process.exit(1)
})
