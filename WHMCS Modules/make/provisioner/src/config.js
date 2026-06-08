import dotenv from 'dotenv'

dotenv.config()

export const config = {
  host: process.env.HOST || '0.0.0.0',
  port: Number(process.env.PORT || 8080),
  apiBearerToken: process.env.API_BEARER_TOKEN || '',
  callbackUrl: process.env.WHMCS_CALLBACK_URL || '',
  callbackBearerToken: process.env.WHMCS_CALLBACK_BEARER_TOKEN || '',
  callbackHmacSecret: process.env.WHMCS_CALLBACK_HMAC_SECRET || '',
  retryMax: Number(process.env.JOB_RETRY_MAX || 5),
  retryDelayMs: Number(process.env.JOB_RETRY_DELAY_MS || 10000),
  proxmox: {
    apiUrl: process.env.PROXMOX_API_URL || '',
    apiTokenId: process.env.PROXMOX_API_TOKEN_ID || '',
    apiTokenSecret: process.env.PROXMOX_API_TOKEN_SECRET || '',
    tlsInsecure: String(process.env.PROXMOX_TLS_INSECURE || 'false').toLowerCase() === 'true',
    defaultNode: process.env.PROXMOX_NODE_DEFAULT || 'pve-node-01',
    regionNodeMap: safeJson(process.env.PROXMOX_REGION_NODE_MAP, { default: process.env.PROXMOX_NODE_DEFAULT || 'pve-node-01' }),
    lxcTemplateVmid: Number(process.env.PROXMOX_LXC_TEMPLATE_VMID || 0),
    kvmTemplateVmid: Number(process.env.PROXMOX_KVM_TEMPLATE_VMID || 0),
    storage: process.env.PROXMOX_STORAGE || 'local-lvm',
    bridge: process.env.PROXMOX_BRIDGE || 'vmbr0',
    swapMb: Number(process.env.PROXMOX_SWAP_MB || 512)
  },
  make: {
    publicBaseDomain: process.env.MAKE_PUBLIC_BASE_DOMAIN || '',
    defaultScheme: process.env.MAKE_DEFAULT_SCHEME || 'https',
    deployHookUrl: process.env.MAKE_DEPLOY_HOOK_URL || '',
    deployHookToken: process.env.MAKE_DEPLOY_HOOK_TOKEN || '',
    limitsHookUrl: process.env.MAKE_LIMITS_HOOK_URL || '',
    limitsHookToken: process.env.MAKE_LIMITS_HOOK_TOKEN || '',
    backupHookUrl: process.env.MAKE_BACKUP_HOOK_URL || '',
    backupHookToken: process.env.MAKE_BACKUP_HOOK_TOKEN || '',
    healthCheckPath: process.env.MAKE_HEALTH_CHECK_PATH || '/health',
    deployTimeoutMs: Number(process.env.MAKE_DEPLOY_TIMEOUT_MS || 300000),
    deployPollMs: Number(process.env.MAKE_DEPLOY_POLL_MS || 5000)
  }
}

export function validateConfig() {
  const missing = []
  if (!config.apiBearerToken) missing.push('API_BEARER_TOKEN')
  if (!config.callbackUrl) missing.push('WHMCS_CALLBACK_URL')
  if (!config.callbackBearerToken) missing.push('WHMCS_CALLBACK_BEARER_TOKEN')
  if (!config.proxmox.apiUrl) missing.push('PROXMOX_API_URL')
  if (!config.proxmox.apiTokenId) missing.push('PROXMOX_API_TOKEN_ID')
  if (!config.proxmox.apiTokenSecret) missing.push('PROXMOX_API_TOKEN_SECRET')
  if (!config.proxmox.lxcTemplateVmid) missing.push('PROXMOX_LXC_TEMPLATE_VMID')
  if (!config.make.publicBaseDomain) missing.push('MAKE_PUBLIC_BASE_DOMAIN')
  if (missing.length) {
    throw new Error(`Missing required env vars: ${missing.join(', ')}`)
  }
}

function safeJson(value, fallback) {
  if (!value) return fallback
  try {
    const parsed = JSON.parse(value)
    if (parsed && typeof parsed === 'object') {
      return parsed
    }
    return fallback
  } catch {
    return fallback
  }
}
