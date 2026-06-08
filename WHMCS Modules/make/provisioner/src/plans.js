export const planMatrix = {
  'make-starter': {
    guestType: 'lxc',
    resources: { cpuCores: 1, memoryMb: 2048, diskGb: 5 },
    limits: { activeScenarios: 5, operationsPerMonth: 10000 },
    features: { backupFrequency: 'daily', customDomain: false, prioritySupport: false }
  },
  'make-professional': {
    guestType: 'lxc',
    resources: { cpuCores: 2, memoryMb: 4096, diskGb: 20 },
    limits: { activeScenarios: 25, operationsPerMonth: 100000 },
    features: { backupFrequency: 'daily', customDomain: false, prioritySupport: true }
  },
  'make-enterprise': {
    guestType: 'qemu',
    resources: { cpuCores: 4, memoryMb: 8192, diskGb: 50 },
    limits: { activeScenarios: 'unlimited', operationsPerMonth: 'unlimited' },
    features: { backupFrequency: 'hourly', customDomain: true, prioritySupport: true }
  }
}

export function getPlan(planCode) {
  return planMatrix[planCode] || null
}
