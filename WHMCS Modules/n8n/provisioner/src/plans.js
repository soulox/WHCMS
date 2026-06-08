export const planMatrix = {
  starter_5g: {
    resources: { cpuCores: 1, memoryMb: 2048, diskGb: 5 },
    limits: { activeWorkflows: 5, executionsPerMonth: 2500 },
    features: { backupFrequency: 'daily', customDomain: false, prioritySupport: false }
  },
  pro_20g: {
    resources: { cpuCores: 2, memoryMb: 4096, diskGb: 20 },
    limits: { activeWorkflows: 25, executionsPerMonth: 15000 },
    features: { backupFrequency: 'daily', customDomain: false, prioritySupport: true }
  },
  scale_50g: {
    resources: { cpuCores: 4, memoryMb: 8192, diskGb: 50 },
    limits: { activeWorkflows: 'unlimited', executionsPerMonth: 50000 },
    features: { backupFrequency: 'hourly', customDomain: true, prioritySupport: true }
  }
}

export function getPlan(planCode) {
  return planMatrix[planCode] || null
}
