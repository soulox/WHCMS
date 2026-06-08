import crypto from 'crypto'

export function buildComposeYaml({ n8nImage, postgresImage }) {
  return `services:
  postgres:
    image: ${postgresImage}
    restart: unless-stopped
    env_file:
      - .env
    environment:
      POSTGRES_USER: \\${DB_POSTGRESDB_USER}
      POSTGRES_PASSWORD: \\${DB_POSTGRESDB_PASSWORD}
      POSTGRES_DB: \\${DB_POSTGRESDB_DATABASE}
    volumes:
      - postgres_data:/var/lib/postgresql/data
    healthcheck:
      test: [\"CMD-SHELL\", \"pg_isready -U \\${DB_POSTGRESDB_USER}\"]
      interval: 10s
      timeout: 5s
      retries: 6

  n8n:
    image: ${n8nImage}
    restart: unless-stopped
    env_file:
      - .env
    depends_on:
      postgres:
        condition: service_healthy
    ports:
      - \"5678:5678\"
    volumes:
      - n8n_data:/home/node/.n8n

volumes:
  postgres_data:
  n8n_data:
`
}

export function buildEnvFile({ instanceUrl, timezone, customerEmail }) {
  const url = new URL(instanceUrl)
  const dbUser = `n8n_${randomToken(6)}`
  const dbPass = randomToken(24)
  const dbName = `n8n_${randomToken(6)}`

  return [
    `GENERIC_TIMEZONE=${timezone}`,
    `TZ=${timezone}`,
    `N8N_HOST=${url.hostname}`,
    `N8N_PROTOCOL=${url.protocol.replace(':', '')}`,
    `N8N_PORT=5678`,
    `WEBHOOK_URL=${instanceUrl}/`,
    `N8N_EDITOR_BASE_URL=${instanceUrl}/`,
    `N8N_ENCRYPTION_KEY=${randomToken(32)}`,
    `N8N_USER_MANAGEMENT_DISABLED=false`,
    `N8N_DIAGNOSTICS_ENABLED=false`,
    `N8N_PERSONALIZATION_ENABLED=false`,
    `N8N_BASIC_AUTH_ACTIVE=false`,
    `N8N_SECURE_COOKIE=true`,
    `N8N_LOG_LEVEL=info`,
    `N8N_DEFAULT_LOCALE=en`,
    `DB_TYPE=postgresdb`,
    `DB_POSTGRESDB_HOST=postgres`,
    `DB_POSTGRESDB_PORT=5432`,
    `DB_POSTGRESDB_USER=${dbUser}`,
    `DB_POSTGRESDB_PASSWORD=${dbPass}`,
    `DB_POSTGRESDB_DATABASE=${dbName}`,
    `N8N_OWNER_EMAIL=${customerEmail || ''}`
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
