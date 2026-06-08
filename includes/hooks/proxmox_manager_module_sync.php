<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

$rootDir = defined('ROOTDIR') ? ROOTDIR : dirname(__DIR__, 2);
$repoFile = $rootDir . '/modules/addons/proxmox_manager/lib/Repository.php';
if (!file_exists($repoFile)) {
    return;
}

require_once $repoFile;

if (!function_exists('proxmox_manager_hook_register')) {
    function proxmox_manager_hook_register($hookName, $action)
    {
        add_hook($hookName, 1, function (array $vars) use ($action) {
            proxmox_manager_hook_process($action, $vars);
        });
    }
}

if (!function_exists('proxmox_manager_hook_process')) {
    function proxmox_manager_hook_process($action, array $vars)
    {
        try {
            if (!Capsule::schema()->hasTable('mod_proxmox_manager_tasks')) {
                return;
            }

            $serviceId = proxmox_manager_hook_service_id($vars);
            $params = isset($vars['params']) && is_array($vars['params']) ? $vars['params'] : [];
            $clientId = proxmox_manager_hook_client_id($vars, $params);
            $meta = proxmox_manager_hook_meta($vars, $params, $serviceId);
            $status = proxmox_manager_hook_status($vars);
            $error = proxmox_manager_hook_error($vars);

            $repo = new \WHMCS\Module\Addon\ProxmoxManager\Repository();

            if ($action === 'create' && $status === 'success' && Capsule::schema()->hasTable('mod_proxmox_manager_services')) {
                if (!empty($meta['node']) && !empty($meta['resource_type']) && !empty($meta['vmid'])) {
                    $repo->saveServiceMapping($serviceId, $clientId, $meta['node'], $meta['resource_type'], $meta['vmid']);
                }
            }

            if ($action === 'terminate' && $status === 'success' && Capsule::schema()->hasTable('mod_proxmox_manager_services')) {
                $repo->deleteServiceMapping($serviceId);
            }

            $repo->logTask([
                'service_id' => $serviceId,
                'client_id' => $clientId,
                'node' => isset($meta['node']) ? $meta['node'] : null,
                'resource_type' => isset($meta['resource_type']) ? $meta['resource_type'] : null,
                'vmid' => isset($meta['vmid']) ? (int) $meta['vmid'] : null,
                'action' => $action,
                'status' => $status,
                'request_payload' => json_encode([
                    'hook' => isset($vars['funcName']) ? $vars['funcName'] : null,
                    'module' => isset($params['moduletype']) ? $params['moduletype'] : null,
                    'service_id' => $serviceId,
                ]),
                'response_payload' => json_encode(proxmox_manager_hook_response_payload($vars)),
                'error_message' => $error,
            ]);
        } catch (\Throwable $e) {
            logModuleCall('proxmox_manager', 'hook_sync_error', $vars, [], $e->getMessage());
        }
    }
}

if (!function_exists('proxmox_manager_hook_service_id')) {
    function proxmox_manager_hook_service_id(array $vars)
    {
        if (isset($vars['serviceid'])) {
            return (int) $vars['serviceid'];
        }

        if (isset($vars['params']['serviceid'])) {
            return (int) $vars['params']['serviceid'];
        }

        return 0;
    }
}

if (!function_exists('proxmox_manager_hook_client_id')) {
    function proxmox_manager_hook_client_id(array $vars, array $params)
    {
        if (isset($params['clientsdetails']['userid'])) {
            return (int) $params['clientsdetails']['userid'];
        }

        if (isset($params['userid'])) {
            return (int) $params['userid'];
        }

        if (isset($vars['userid'])) {
            return (int) $vars['userid'];
        }

        return null;
    }
}

if (!function_exists('proxmox_manager_hook_meta')) {
    function proxmox_manager_hook_meta(array $vars, array $params, $serviceId)
    {
        $repo = new \WHMCS\Module\Addon\ProxmoxManager\Repository();
        $existing = $serviceId > 0 ? $repo->getServiceMapping($serviceId) : null;

        $customFields = isset($params['customfields']) && is_array($params['customfields']) ? $params['customfields'] : [];
        $configOptions = isset($params['configoptions']) && is_array($params['configoptions']) ? $params['configoptions'] : [];

        $node = proxmox_manager_hook_pick([
            proxmox_manager_hook_value($customFields, 'proxmox_node'),
            proxmox_manager_hook_value($configOptions, 'Node'),
            $existing ? $existing->node : null,
            isset($params['serverhostname']) ? $params['serverhostname'] : null,
        ]);

        $type = strtolower((string) proxmox_manager_hook_pick([
            proxmox_manager_hook_value($customFields, 'proxmox_type'),
            proxmox_manager_hook_value($configOptions, 'Resource Type'),
            $existing ? $existing->resource_type : null,
        ]));

        if ($type === 'qemu') {
            $type = 'kvm';
        }

        $vmid = (int) proxmox_manager_hook_pick([
            proxmox_manager_hook_value($customFields, 'proxmox_vmid'),
            $existing ? $existing->vmid : null,
        ], 0);

        return [
            'node' => $node ? trim((string) $node) : null,
            'resource_type' => $type !== '' ? $type : null,
            'vmid' => $vmid > 0 ? $vmid : null,
        ];
    }
}

if (!function_exists('proxmox_manager_hook_value')) {
    function proxmox_manager_hook_value(array $source, $key)
    {
        if (isset($source[$key]) && $source[$key] !== '') {
            return $source[$key];
        }

        foreach ($source as $field => $value) {
            if (stripos((string) $field, (string) $key) === 0 && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}

if (!function_exists('proxmox_manager_hook_pick')) {
    function proxmox_manager_hook_pick(array $values, $default = null)
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $default;
    }
}

if (!function_exists('proxmox_manager_hook_status')) {
    function proxmox_manager_hook_status(array $vars)
    {
        if (isset($vars['success'])) {
            return $vars['success'] ? 'success' : 'failed';
        }

        if (isset($vars['isSuccessful'])) {
            return $vars['isSuccessful'] ? 'success' : 'failed';
        }

        if (!empty($vars['failureResponseMessage']) || !empty($vars['error'])) {
            return 'failed';
        }

        return 'success';
    }
}

if (!function_exists('proxmox_manager_hook_error')) {
    function proxmox_manager_hook_error(array $vars)
    {
        if (!empty($vars['failureResponseMessage'])) {
            return (string) $vars['failureResponseMessage'];
        }

        if (!empty($vars['error'])) {
            return (string) $vars['error'];
        }

        if (isset($vars['result']) && is_string($vars['result']) && strtolower($vars['result']) !== 'success') {
            return $vars['result'];
        }

        return null;
    }
}

if (!function_exists('proxmox_manager_hook_response_payload')) {
    function proxmox_manager_hook_response_payload(array $vars)
    {
        $allowed = ['result', 'message', 'failureResponseMessage', 'success', 'isSuccessful'];
        $payload = [];

        foreach ($allowed as $key) {
            if (isset($vars[$key])) {
                $payload[$key] = $vars[$key];
            }
        }

        return $payload;
    }
}

proxmox_manager_hook_register('AfterModuleCreate', 'create');
proxmox_manager_hook_register('AfterModuleSuspend', 'suspend');
proxmox_manager_hook_register('AfterModuleUnsuspend', 'unsuspend');
proxmox_manager_hook_register('AfterModuleTerminate', 'terminate');
proxmox_manager_hook_register('AfterModuleChangePackage', 'change-package');
