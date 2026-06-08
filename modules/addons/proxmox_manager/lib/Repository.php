<?php

namespace WHMCS\Module\Addon\ProxmoxManager;

use WHMCS\Database\Capsule;

class Repository
{
    public function latestServiceMappings($limit = 50)
    {
        return Capsule::table('mod_proxmox_manager_services')
            ->orderBy('updated_at', 'desc')
            ->limit((int) $limit)
            ->get();
    }

    public function getServiceMapping($serviceId)
    {
        return Capsule::table('mod_proxmox_manager_services')
            ->where('service_id', (int) $serviceId)
            ->first();
    }

    public function saveServiceMapping($serviceId, $clientId, $node, $resourceType, $vmid)
    {
        $now = date('Y-m-d H:i:s');

        $payload = [
            'client_id' => $clientId !== null ? (int) $clientId : null,
            'node' => trim((string) $node),
            'resource_type' => strtolower(trim((string) $resourceType)),
            'vmid' => (int) $vmid,
            'updated_at' => $now,
        ];

        $exists = Capsule::table('mod_proxmox_manager_services')
            ->where('service_id', (int) $serviceId)
            ->count();

        if ((int) $exists > 0) {
            Capsule::table('mod_proxmox_manager_services')
                ->where('service_id', (int) $serviceId)
                ->update($payload);

            return (int) $serviceId;
        }

        $payload['service_id'] = (int) $serviceId;
        $payload['created_at'] = $now;

        return Capsule::table('mod_proxmox_manager_services')->insertGetId($payload);
    }

    public function deleteServiceMapping($serviceId)
    {
        return Capsule::table('mod_proxmox_manager_services')
            ->where('service_id', (int) $serviceId)
            ->delete();
    }

    public function latestTasks($limit = 20)
    {
        return Capsule::table('mod_proxmox_manager_tasks')
            ->orderBy('id', 'desc')
            ->limit((int) $limit)
            ->get();
    }

    public function latestTasksByService($serviceId, $limit = 10)
    {
        return Capsule::table('mod_proxmox_manager_tasks')
            ->where('service_id', (int) $serviceId)
            ->orderBy('id', 'desc')
            ->limit((int) $limit)
            ->get();
    }

    public function logTask(array $row)
    {
        $now = date('Y-m-d H:i:s');

        return Capsule::table('mod_proxmox_manager_tasks')->insertGetId([
            'service_id' => isset($row['service_id']) ? (int) $row['service_id'] : 0,
            'client_id' => isset($row['client_id']) ? (int) $row['client_id'] : null,
            'node' => isset($row['node']) ? (string) $row['node'] : null,
            'resource_type' => isset($row['resource_type']) ? (string) $row['resource_type'] : null,
            'vmid' => isset($row['vmid']) ? (int) $row['vmid'] : null,
            'action' => isset($row['action']) ? (string) $row['action'] : 'unknown',
            'status' => isset($row['status']) ? (string) $row['status'] : 'queued',
            'request_payload' => isset($row['request_payload']) ? (string) $row['request_payload'] : null,
            'response_payload' => isset($row['response_payload']) ? (string) $row['response_payload'] : null,
            'error_message' => isset($row['error_message']) ? (string) $row['error_message'] : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
