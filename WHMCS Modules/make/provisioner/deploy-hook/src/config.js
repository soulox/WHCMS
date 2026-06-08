import dotenv from 'dotenv'

dotenv.config()

export const config = {
  host: process.env.HOST || '0.0.0.0',
  port: Number(process.env.PORT || 8090),
  hookBearerToken: process.env.HOOK_BEARER_TOKEN || '',
  pctBin: process.env.PCT_BIN || 'pct',
  qmBin: process.env.QM_BIN || 'qm',
  makeImage: process.env.MAKE_IMAGE || 'ghcr.io/timberlandhosting/make-runtime:latest',
  postgresImage: process.env.POSTGRES_IMAGE || 'postgres:16-alpine',
  timezone: process.env.MAKE_TIMEZONE || 'UTC',
  backupRetentionDays: Number(process.env.BACKUP_RETENTION_DAYS || 7)
}

export function validateConfig() {
  const missing = []
  if (!config.hookBearerToken) missing.push('HOOK_BEARER_TOKEN')
  if (missing.length) {
    throw new Error(`Missing env vars: ${missing.join(', ')}`)
  }
}
