import crypto from 'crypto'

export function buildComposeYaml({ makeImage, postgresImage }) {
  return `services:
  postgres:
    image: ${postgresImage}
    restart: unless-stopped
    env_file:
      - .env
    environment:
      POSTGRES_USER: \${DB_POSTGRESDB_USER}
      POSTGRES_PASSWORD: \${DB_POSTGRESDB_PASSWORD}
      POSTGRES_DB: \${DB_POSTGRESDB_DATABASE}
    volumes:
      - postgres_data:/var/lib/postgresql/data

  make:
    image: ${makeImage}
    restart: unless-stopped
    env_file:
      - .env
    depends_on:
      - postgres
    ports:
      - "8080:8080"
    volumes:
      - make_data:/opt/make/data

volumes:
  postgres_data:
  make_data:
`
}

export function buildEnvFile({ instanceUrl, timezone, customerEmail, runtimeChannel }) {
  const url = new URL(instanceUrl)
  const dbUser = `make_${randomToken(6)}`
  const dbPass = randomToken(24)
  const dbName = `make_${randomToken(6)}`

  return [
    `TZ=${timezone}`,
    `MAKE_HOST=${url.hostname}`,
    `MAKE_PROTOCOL=${url.protocol.replace(':', '')}`,
    'MAKE_PORT=8080',
    `MAKE_PUBLIC_URL=${instanceUrl}/`,
    `MAKE_RUNTIME_CHANNEL=${runtimeChannel || 'stable'}`,
    `MAKE_ENCRYPTION_KEY=${randomToken(32)}`,
    'DB_TYPE=postgresdb',
    'DB_POSTGRESDB_HOST=postgres',
    'DB_POSTGRESDB_PORT=5432',
    `DB_POSTGRESDB_USER=${dbUser}`,
    `DB_POSTGRESDB_PASSWORD=${dbPass}`,
    `DB_POSTGRESDB_DATABASE=${dbName}`,
    `MAKE_OWNER_EMAIL=${customerEmail || ''}`
  ].join('\n') + '\n'
}

export function buildLimitsFile({ limits, features }) {
  return JSON.stringify(
    {
      generated_at: new Date().toISOString(),
      limits,
      features
    },
    null,
    2
  )
}

function randomToken(length) {
  return crypto.randomBytes(length).toString('hex').slice(0, length * 2)
}
