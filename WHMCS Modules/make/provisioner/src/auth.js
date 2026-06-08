import crypto from 'crypto'

export function registerAuthHooks(app, token) {
  app.addHook('preHandler', async (request, reply) => {
    if (request.url === '/v1/ping') return

    const authHeader = request.headers.authorization || ''
    if (!authHeader.startsWith('Bearer ')) {
      reply.code(401).send({ message: 'Missing bearer token' })
      return
    }

    const provided = authHeader.slice(7).trim()
    if (!safeEqual(provided, token)) {
      reply.code(401).send({ message: 'Invalid bearer token' })
    }
  })
}

function safeEqual(a, b) {
  const ax = Buffer.from(String(a))
  const bx = Buffer.from(String(b))
  if (ax.length !== bx.length) return false
  return crypto.timingSafeEqual(ax, bx)
}
