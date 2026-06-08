<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/ApiClient.php';

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\Proxmox\ApiClient;

function proxmox_MetaData()
{
    return [
        'DisplayName' => 'Proxmox VE',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultNonSSLPort' => '8006',
        'DefaultSSLPort' => '8006',
    ];
}

function proxmox_ConfigOptions()
{
    return [
        'Resource Type' => [
            'Type' => 'dropdown',
            'Options' => 'kvm,lxc',
            'Default' => 'kvm',
            'Description' => 'VM type to provision.',
        ],
        'Node' => [
            'Type' => 'text',
            'Size' => '25',
            'Description' => 'Target Proxmox node, e.g. pve01',
        ],
        'Pool' => [
            'Type' => 'text',
            'Size' => '25',
            'Description' => 'Optional Proxmox pool',
        ],
        'Template' => [
            'Type' => 'text',
            'Size' => '50',
            'Description' => 'KVM template VMID or LXC template path',
        ],
        'OS Flavor' => [
            'Type' => 'text',
            'Size' => '80',
            'Description' => 'Optional override from configurable option/custom field (for mapped keys use values like n8n, m8n, debian12, ubuntu2404)',
        ],
        'Storage' => [
            'Type' => 'text',
            'Size' => '25',
            'Default' => 'local-lvm',
        ],
        'Bridge' => [
            'Type' => 'text',
            'Size' => '25',
            'Default' => 'vmbr0',
        ],
        'Cores' => [
            'Type' => 'text',
            'Size' => '8',
            'Default' => '1',
        ],
        'Memory (MB)' => [
            'Type' => 'text',
            'Size' => '8',
            'Default' => '1024',
        ],
        'Swap (MB)' => [
            'Type' => 'text',
            'Size' => '8',
            'Default' => '512',
        ],
        'Disk (GB)' => [
            'Type' => 'text',
            'Size' => '8',
            'Default' => '20',
        ],
        'Start After Create' => [
            'Type' => 'yesno',
            'Description' => 'Start VM/CT after provisioning',
        ],
        'Disable Password Auth with SSH Key' => [
            'Type' => 'yesno',
            'Description' => 'If SSH key is present, skip password injection',
        ],
        'Auto DNS Registration' => [
            'Type' => 'yesno',
            'Description' => 'Create/update DNS records automatically for mapped app services',
        ],
        'DNS API URL' => [
            'Type' => 'text',
            'Size' => '80',
            'Default' => 'http://10.10.10.53:5380',
            'Description' => 'Technitium API base URL',
        ],
        'DNS API User' => [
            'Type' => 'text',
            'Size' => '40',
            'Default' => 'admin',
        ],
        'DNS API Password' => [
            'Type' => 'password',
            'Size' => '60',
            'Default' => '',
            'Description' => 'Technitium admin password',
        ],
        'DNS Forward Zone' => [
            'Type' => 'text',
            'Size' => '60',
            'Default' => 'infra.local',
        ],
        'DNS Reverse Zone' => [
            'Type' => 'text',
            'Size' => '60',
            'Default' => '10.10.10.in-addr.arpa',
        ],
        'DNS Host Prefix' => [
            'Type' => 'text',
            'Size' => '20',
            'Default' => 'n8n',
            'Description' => 'Hostname prefix for per-service records',
        ],
        'Enable Policy Engine' => [
            'Type' => 'yesno',
            'Description' => 'Enable product policy and IP lease workflow (phase 1)',
        ],
    ];
}

function proxmox_TestConnection(array $params)
{
    try {
        proxmox_api($params)->getVersion();
        return ['success' => true];
    } catch (\Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function proxmox_CreateAccount(array $params)
{
    $action = 'create';

    try {
        $api = proxmox_api($params);
        $node = proxmox_node($params);
        $type = proxmox_type($params);
        $template = proxmox_template($params);
        $vmid = proxmox_saved_vmid($params);
        $hostname = proxmox_hostname($params);
        $sshKey = proxmox_ssh_public_key($params);
        $disablePasswordAuth = proxmox_disable_password_auth_with_ssh_key($params, $sshKey);
        $selectedOsKey = '';
        $dnsFqdn = '';
        $dnsIp = '';
        $leased = null;
        $policy = null;

        if ($type === 'kvm') {
            $mapped = proxmox_kvm_template_mapping($params);
            if ($mapped !== null) {
                $node = (string) $mapped['node'];
                $template = (string) $mapped['template'];
                $selectedOsKey = isset($mapped['os_key']) ? (string) $mapped['os_key'] : '';
            }

            $dnsPlan = proxmox_dns_plan($params, $selectedOsKey);
            if ($dnsPlan !== null) {
                $hostname = (string) $dnsPlan['hostname_short'];
                $dnsFqdn = (string) $dnsPlan['hostname_fqdn'];
                $dnsIp = (string) $dnsPlan['ip'];
            }
        }

        if ($node === '' || $template === '') {
            throw new \RuntimeException('Missing required module options: Node and Template/OS Flavor.');
        }

        if ($vmid < 1) {
            $vmid = $api->nextVmid();
        }

        if ($type === 'kvm' && proxmox_policy_engine_enabled($params)) {
            $policy = proxmox_policy_for_product($params);
            if ($policy !== null) {
                proxmox_audit_event($params, 'policy_resolved', 'success', [
                    'policy_id' => isset($policy['id']) ? (int) $policy['id'] : 0,
                    'service_class' => proxmox_service_class($policy),
                    'strict_mode' => proxmox_policy_requires_strict($policy) ? 1 : 0,
                ]);
                if (proxmox_policy_requires_strict($policy) && !proxmox_validate_pool_for_policy($policy)) {
                    throw new \RuntimeException('Policy strict mode enabled but private pool is invalid or disabled.');
                }
                $leased = proxmox_lease_private_ip($params, $policy, $vmid, $node, $type);
                if (proxmox_policy_requires_strict($policy) && !is_array($leased)) {
                    throw new \RuntimeException('Policy strict mode enabled and no private IP lease was available.');
                }
                if (is_array($leased) && isset($leased['ip_address']) && $dnsIp === '') {
                    $dnsIp = (string) $leased['ip_address'];
                }

                proxmox_plan_events_for_class($params, proxmox_service_class($policy), 'create_planned', $policy);
            }
        }

        if ($type === 'lxc') {
            $payload = [
                'vmid' => $vmid,
                'hostname' => $hostname,
                'ostemplate' => $template,
                'cores' => proxmox_int_option($params, 'Cores', 1),
                'memory' => proxmox_int_option($params, 'Memory (MB)', 1024),
                'swap' => proxmox_int_option($params, 'Swap (MB)', 512),
                'rootfs' => proxmox_option($params, 'Storage', 'local-lvm') . ':' . proxmox_int_option($params, 'Disk (GB)', 20),
                'net0' => 'name=eth0,bridge=' . proxmox_option($params, 'Bridge', 'vmbr0') . ',ip=dhcp',
                'onboot' => 1,
            ];

            if (!$disablePasswordAuth) {
                $payload['password'] = proxmox_root_password($params);
            }

            if ($sshKey !== '') {
                $payload['ssh-public-keys'] = $sshKey;
            }

            $pool = proxmox_option($params, 'Pool', '');
            if ($pool !== '') {
                $payload['pool'] = $pool;
            }

            $upid = $api->createLxc($node, $payload);
            if (is_string($upid) && $upid !== '') {
                $api->waitForTask($node, $upid, 300);
            }
        } else {
            if (!ctype_digit((string) $template)) {
                throw new \RuntimeException('KVM provisioning expects Template/OS Flavor to be a numeric template VMID.');
            }

            $clonePayload = [
                'newid' => $vmid,
                'name' => $hostname,
                'full' => 1,
            ];
            $pool = proxmox_option($params, 'Pool', '');
            if ($pool !== '') {
                $clonePayload['pool'] = $pool;
            }

            $upid = $api->cloneQemu($node, (int) $template, $clonePayload);
            if (is_string($upid) && $upid !== '') {
                $api->waitForTask($node, $upid, 300);
            }

            $api->updateConfig($node, 'kvm', $vmid, [
                'cores' => proxmox_int_option($params, 'Cores', 1),
                'memory' => proxmox_int_option($params, 'Memory (MB)', 1024),
                'onboot' => 1,
                'net0' => 'virtio,bridge=' . proxmox_option($params, 'Bridge', 'vmbr0'),
                'ipconfig0' => (is_array($leased) ? proxmox_build_static_ipconfig($leased) : 'ip=dhcp'),
            ]);

            if ($sshKey !== '') {
                try {
                    $api->updateConfig($node, 'kvm', $vmid, [
                        'sshkeys' => $sshKey,
                    ]);
                } catch (\Throwable $e) {
                    logModuleCall('proxmox', 'cloudInitSshKey', ['vmid' => $vmid], [], $e->getMessage());
                }
            }

            $diskGb = proxmox_int_option($params, 'Disk (GB)', 20);
            if ($diskGb > 0) {
                try {
                    $api->resizeDisk($node, $vmid, 'scsi0', '+' . $diskGb . 'G');
                } catch (\Throwable $e) {
                    logModuleCall('proxmox', 'resizeDisk', ['vmid' => $vmid, 'size' => $diskGb], [], $e->getMessage());
                }
            }

            $rootPassword = proxmox_root_password($params);
            if ($rootPassword !== '' && !$disablePasswordAuth) {
                try {
                    $api->updateConfig($node, 'kvm', $vmid, [
                        'ciuser' => 'root',
                        'cipassword' => $rootPassword,
                    ]);
                } catch (\Throwable $e) {
                    logModuleCall('proxmox', 'cloudInitPassword', ['vmid' => $vmid], [], $e->getMessage(), ['cipassword']);
                }
            }
        }

        if (proxmox_should_start($params)) {
            try {
                $upid = $api->start($node, $type, $vmid);
                if (is_string($upid) && $upid !== '') {
                    $api->waitForTask($node, $upid, 120);
                }
            } catch (\Throwable $e) {
                logModuleCall('proxmox', 'startAfterCreate', ['vmid' => $vmid], [], $e->getMessage());
            }
        }

        proxmox_save_service_meta($params, $node, $type, $vmid, [
            'proxmox_hostname' => ($dnsFqdn !== '' ? $dnsFqdn : $hostname),
            'proxmox_private_ip' => (is_array($leased) && isset($leased['ip_address']) ? (string) $leased['ip_address'] : ''),
        ]);

        if (is_array($leased)) {
            proxmox_save_service_state($params, [
                'policy_id' => isset($policy['id']) ? (int) $policy['id'] : null,
                'private_ip' => (string) $leased['ip_address'],
                'provision_state' => 'provisioned',
            ]);
            proxmox_audit_event($params, 'ip_allocate', 'success', [
                'pool_id' => isset($leased['pool_id']) ? (int) $leased['pool_id'] : 0,
                'ip_address' => (string) $leased['ip_address'],
            ]);
        }

        if ($dnsFqdn !== '' && $dnsIp !== '') {
            try {
                proxmox_register_dns_record($params, $dnsFqdn, $dnsIp);
            } catch (\Throwable $e) {
                logModuleCall('proxmox', 'dnsRegister', ['hostname' => $dnsFqdn, 'ip' => $dnsIp], [], $e->getMessage(), ['DNS API Password']);
            }
        }

        proxmox_log_task($params, $action, 'success', null, ['node' => $node, 'type' => $type, 'vmid' => $vmid]);
        proxmox_audit_event($params, 'create', 'success', ['node' => $node, 'type' => $type, 'vmid' => $vmid]);

        return 'success';
    } catch (\Throwable $e) {
        if (isset($leased) && is_array($leased)) {
            try {
                proxmox_release_private_ip($params);
            } catch (\Throwable $ignore) {
            }
        }
        proxmox_audit_event($params, 'create', 'failed', [], [], $e->getMessage());
        proxmox_log_task($params, $action, 'failed', $e->getMessage());
        logModuleCall('proxmox', 'CreateAccount', $params, [], $e->getMessage(), ['serverpassword', 'password']);
        return $e->getMessage();
    }
}

function proxmox_SuspendAccount(array $params)
{
    return proxmox_power_action($params, 'suspend', 'stop');
}

function proxmox_UnsuspendAccount(array $params)
{
    return proxmox_power_action($params, 'unsuspend', 'start');
}

function proxmox_TerminateAccount(array $params)
{
    $action = 'terminate';
    try {
        $api = proxmox_api($params);
        $identity = proxmox_identity($params);

        if (empty($identity['node']) || empty($identity['type']) || empty($identity['vmid'])) {
            throw new \RuntimeException('Missing Proxmox identity on service (node/type/vmid).');
        }

        try {
            $status = $api->status($identity['node'], $identity['type'], $identity['vmid']);
            if (isset($status['status']) && strtolower((string) $status['status']) === 'running') {
                $upid = $api->stop($identity['node'], $identity['type'], $identity['vmid']);
                if (is_string($upid) && $upid !== '') {
                    $api->waitForTask($identity['node'], $upid, 120);
                }
            }
        } catch (\Throwable $e) {
            logModuleCall('proxmox', 'Terminate-stop', $identity, [], $e->getMessage());
        }

        $upid = $api->deleteResource($identity['node'], $identity['type'], $identity['vmid']);
        if (is_string($upid) && $upid !== '') {
            $api->waitForTask($identity['node'], $upid, 180);
        }

        if (proxmox_policy_engine_enabled($params)) {
            $policy = proxmox_policy_for_product($params);
            if ($policy !== null) {
                proxmox_plan_events_for_class($params, proxmox_service_class($policy), 'terminate_planned', $policy);
            }
        }

        $dnsHost = trim((string) proxmox_saved_value($params, 'proxmox_hostname', ''));
        $dnsIp = trim((string) proxmox_saved_value($params, 'proxmox_private_ip', ''));
        if ($dnsIp === '') {
            $dnsIp = proxmox_primary_service_ip($params);
        }
        if ($dnsHost !== '' && $dnsIp !== '') {
            try {
                proxmox_unregister_dns_record($params, $dnsHost, $dnsIp);
            } catch (\Throwable $e) {
                logModuleCall('proxmox', 'dnsUnregister', ['hostname' => $dnsHost, 'ip' => $dnsIp], [], $e->getMessage(), ['DNS API Password']);
            }
        }

        proxmox_delete_mapping($params);
        proxmox_release_private_ip($params);
        proxmox_delete_service_state($params);
        proxmox_audit_event($params, 'terminate', 'success');
        proxmox_log_task($params, $action, 'success');
        return 'success';
    } catch (\Throwable $e) {
        proxmox_audit_event($params, 'terminate', 'failed', [], [], $e->getMessage());
        proxmox_log_task($params, $action, 'failed', $e->getMessage());
        logModuleCall('proxmox', 'TerminateAccount', $params, [], $e->getMessage(), ['serverpassword']);
        return $e->getMessage();
    }
}

function proxmox_ChangePackage(array $params)
{
    $action = 'change-package';
    try {
        $api = proxmox_api($params);
        $identity = proxmox_identity($params);
        if (empty($identity['node']) || empty($identity['type']) || empty($identity['vmid'])) {
            throw new \RuntimeException('Missing Proxmox identity on service (node/type/vmid).');
        }

        $payload = [
            'cores' => proxmox_int_option($params, 'Cores', 1),
            'memory' => proxmox_int_option($params, 'Memory (MB)', 1024),
            'onboot' => 1,
        ];
        if ($identity['type'] === 'lxc') {
            $payload['swap'] = proxmox_int_option($params, 'Swap (MB)', 512);
        }

        $api->updateConfig($identity['node'], $identity['type'], $identity['vmid'], $payload);
        proxmox_log_task($params, $action, 'success', null, $payload);
        return 'success';
    } catch (\Throwable $e) {
        proxmox_log_task($params, $action, 'failed', $e->getMessage());
        logModuleCall('proxmox', 'ChangePackage', $params, [], $e->getMessage(), ['serverpassword']);
        return $e->getMessage();
    }
}

function proxmox_Reboot(array $params)
{
    return proxmox_power_action($params, 'reboot', 'reboot');
}

function proxmox_Shutdown(array $params)
{
    return proxmox_power_action($params, 'shutdown', 'stop');
}

function proxmox_AdminCustomButtonArray()
{
    return [
        'Start' => 'Start',
        'Stop' => 'Shutdown',
        'Reboot' => 'Reboot',
    ];
}

function proxmox_Start(array $params)
{
    return proxmox_power_action($params, 'start', 'start');
}

function proxmox_power_action(array $params, $taskAction, $apiAction)
{
    try {
        $api = proxmox_api($params);
        $identity = proxmox_identity($params);
        if (empty($identity['node']) || empty($identity['type']) || empty($identity['vmid'])) {
            throw new \RuntimeException('Missing Proxmox identity on service (node/type/vmid).');
        }

        if ($apiAction === 'start') {
            $upid = $api->start($identity['node'], $identity['type'], $identity['vmid']);
        } elseif ($apiAction === 'reboot') {
            $upid = $api->reboot($identity['node'], $identity['type'], $identity['vmid']);
        } else {
            $upid = $api->stop($identity['node'], $identity['type'], $identity['vmid']);
        }

        if (is_string($upid) && $upid !== '') {
            $api->waitForTask($identity['node'], $upid, 120);
        }

        if (proxmox_policy_engine_enabled($params)) {
            $policy = proxmox_policy_for_product($params);
            if ($policy !== null) {
                $class = proxmox_service_class($policy);
                if ($taskAction === 'suspend') {
                    proxmox_plan_events_for_class($params, $class, 'suspend_planned', $policy);
                } elseif ($taskAction === 'unsuspend') {
                    proxmox_plan_events_for_class($params, $class, 'unsuspend_planned', $policy);
                }
            }
        }

        if ($taskAction === 'suspend') {
            proxmox_save_service_state($params, ['provision_state' => 'suspended']);
        } elseif ($taskAction === 'unsuspend' || $taskAction === 'start' || $taskAction === 'reboot') {
            proxmox_save_service_state($params, ['provision_state' => 'provisioned']);
        }

        proxmox_audit_event($params, $taskAction, 'success');
        proxmox_log_task($params, $taskAction, 'success');
        return 'success';
    } catch (\Throwable $e) {
        proxmox_audit_event($params, $taskAction, 'failed', [], [], $e->getMessage());
        proxmox_log_task($params, $taskAction, 'failed', $e->getMessage());
        logModuleCall('proxmox', $taskAction, $params, [], $e->getMessage(), ['serverpassword']);
        return $e->getMessage();
    }
}

function proxmox_api(array $params)
{
    $host = isset($params['serverhostname']) ? $params['serverhostname'] : '';
    $port = isset($params['serverport']) ? (int) $params['serverport'] : 8006;
    $secure = isset($params['serversecure']) && $params['serversecure'] === 'on';

    return new ApiClient(
        $host,
        $port > 0 ? $port : 8006,
        $secure,
        isset($params['serverusername']) ? $params['serverusername'] : '',
        isset($params['serverpassword']) ? $params['serverpassword'] : ''
    );
}

function proxmox_identity(array $params)
{
    return [
        'node' => proxmox_saved_value($params, 'proxmox_node', proxmox_node($params)),
        'type' => proxmox_saved_value($params, 'proxmox_type', proxmox_type($params)),
        'vmid' => (int) proxmox_saved_value($params, 'proxmox_vmid', 0),
    ];
}

function proxmox_type(array $params)
{
    return strtolower((string) proxmox_option($params, 'Resource Type', 'kvm'));
}

function proxmox_node(array $params)
{
    return trim((string) proxmox_option($params, 'Node', ''));
}

function proxmox_template(array $params)
{
    $fromFlavor = proxmox_saved_value($params, 'os_flavor', '');
    if ($fromFlavor === '') {
        $fromFlavor = proxmox_saved_value($params, 'OS Flavor', '');
    }
    if ($fromFlavor === '') {
        $fromFlavor = proxmox_option($params, 'OS Flavor', '');
    }
    if ($fromFlavor !== '') {
        return proxmox_normalize_template_value($fromFlavor);
    }

    return proxmox_normalize_template_value(proxmox_option($params, 'Template', ''));
}

function proxmox_kvm_template_mapping(array $params)
{
    $map = proxmox_kvm_template_map();

    $candidates = [];
    $candidates[] = proxmox_saved_value($params, 'os_choice', '');
    $candidates[] = proxmox_saved_value($params, 'OS Choice', '');
    $candidates[] = proxmox_saved_value($params, 'os_flavor', '');
    $candidates[] = proxmox_saved_value($params, 'OS Flavor', '');
    $candidates[] = proxmox_option($params, 'OS Flavor', '');

    foreach ($candidates as $candidate) {
        $key = proxmox_os_choice_key($candidate);
        if ($key !== '' && isset($map[$key])) {
            $row = $map[$key];
            $row['os_key'] = $key;
            return $row;
        }
    }

    return null;
}

function proxmox_kvm_template_map()
{
    $defaults = proxmox_kvm_template_default_map();

    try {
        if (Capsule::schema()->hasTable('mod_proxmox_manager_os_templates')) {
            $rows = Capsule::table('mod_proxmox_manager_os_templates')
                ->where('resource_type', 'kvm')
                ->where('enabled', 1)
                ->get();

            foreach ($rows as $row) {
                $key = proxmox_os_choice_key(isset($row->os_key) ? $row->os_key : '');
                $node = isset($row->node) ? trim((string) $row->node) : '';
                $template = isset($row->template_vmid) ? (int) $row->template_vmid : 0;
                if ($key === '' || $node === '' || $template < 1) {
                    continue;
                }
                $defaults[$key] = ['node' => $node, 'template' => $template];
            }
        }
    } catch (\Throwable $e) {
    }

    return $defaults;
}

function proxmox_kvm_template_default_map()
{
    return [
        'debian12' => ['node' => 'pve26', 'template' => 9300],
        'ubuntu2204' => ['node' => 'pve26', 'template' => 9301],
        'ubuntu2404' => ['node' => 'pve26', 'template' => 9302],
        'almalinux9' => ['node' => 'pve26', 'template' => 9303],
        'rocky9' => ['node' => 'pve26', 'template' => 9304],
        'centosstream9' => ['node' => 'pve26', 'template' => 9305],
        'wordpress' => ['node' => 'pve26', 'template' => 9420],
        'dockerhost' => ['node' => 'pve26', 'template' => 9421],
        'n8n' => ['node' => 'pve26', 'template' => 9414],
        'make' => ['node' => 'pve26', 'template' => 9416],
        'm8n' => ['node' => 'pve26', 'template' => 9414],
        'alma9' => ['node' => 'pve26', 'template' => 9303],
        'ubuntu22' => ['node' => 'pve26', 'template' => 9301],
        'ubuntu24' => ['node' => 'pve26', 'template' => 9302],
        'centos9stream' => ['node' => 'pve26', 'template' => 9305],
    ];
}

function proxmox_os_choice_key($value)
{
    $normalized = trim((string) $value);
    if (strpos($normalized, '|') !== false) {
        $parts = explode('|', $normalized);
        $left = trim((string) $parts[0]);
        if ($left !== '') {
            $normalized = $left;
        }
    }

    $normalized = strtolower($normalized);
    if ($normalized === '') {
        return '';
    }

    return preg_replace('/[^a-z0-9]/', '', $normalized);
}

function proxmox_normalize_template_value($value)
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }

    if (strpos($raw, '|') !== false) {
        $parts = explode('|', $raw);
        $candidate = trim((string) end($parts));
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return $raw;
}

function proxmox_root_password(array $params)
{
    if (!empty($params['password'])) {
        return (string) $params['password'];
    }
    if (!empty($params['servicepassword'])) {
        return (string) $params['servicepassword'];
    }

    return '';
}

function proxmox_ssh_public_key(array $params)
{
    $candidates = [
        'ssh_public_key',
        'sshpublickey',
        'ssh_key',
        'SSH Public Key',
        'SSH Key',
    ];

    foreach ($candidates as $name) {
        $value = trim((string) proxmox_saved_value($params, $name, ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function proxmox_disable_password_auth_with_ssh_key(array $params, $sshKey)
{
    if (trim((string) $sshKey) === '') {
        return false;
    }

    $value = proxmox_option($params, 'Disable Password Auth with SSH Key', 'on');
    if (is_string($value)) {
        return in_array(strtolower($value), ['on', '1', 'yes', 'true'], true);
    }

    return (bool) $value;
}

function proxmox_hostname(array $params)
{
    if (!empty($params['domain'])) {
        return (string) $params['domain'];
    }
    if (!empty($params['username'])) {
        return 'vm-' . preg_replace('/[^a-z0-9\-]/i', '', (string) $params['username']);
    }

    return 'vm-' . (int) (isset($params['serviceid']) ? $params['serviceid'] : 0);
}

function proxmox_should_start(array $params)
{
    $value = proxmox_option($params, 'Start After Create', 'on');
    if (is_string($value)) {
        return in_array(strtolower($value), ['on', '1', 'yes', 'true'], true);
    }

    return (bool) $value;
}

function proxmox_int_option(array $params, $name, $default)
{
    $value = proxmox_option($params, $name, $default);
    return (int) ($value !== '' ? $value : $default);
}

function proxmox_option(array $params, $name, $default = '')
{
    if (isset($params['configoptions']) && is_array($params['configoptions']) && isset($params['configoptions'][$name])) {
        return $params['configoptions'][$name];
    }

    if (isset($params['configoption1'])) {
        $map = [
            'Resource Type' => 1,
            'Node' => 2,
            'Pool' => 3,
            'Template' => 4,
            'OS Flavor' => 5,
            'Storage' => 6,
            'Bridge' => 7,
            'Cores' => 8,
            'Memory (MB)' => 9,
            'Swap (MB)' => 10,
            'Disk (GB)' => 11,
            'Start After Create' => 12,
            'Disable Password Auth with SSH Key' => 13,
            'Auto DNS Registration' => 14,
            'DNS API URL' => 15,
            'DNS API User' => 16,
            'DNS API Password' => 17,
            'DNS Forward Zone' => 18,
            'DNS Reverse Zone' => 19,
            'DNS Host Prefix' => 20,
            'Enable Policy Engine' => 21,
        ];
        if (isset($map[$name])) {
            $key = 'configoption' . $map[$name];
            if (isset($params[$key]) && $params[$key] !== '') {
                return $params[$key];
            }
        }
    }

    return $default;
}

function proxmox_saved_vmid(array $params)
{
    return (int) proxmox_saved_value($params, 'proxmox_vmid', 0);
}

function proxmox_saved_value(array $params, $name, $default = '')
{
    if (isset($params['customfields']) && is_array($params['customfields'])) {
        if (isset($params['customfields'][$name]) && $params['customfields'][$name] !== '') {
            return $params['customfields'][$name];
        }
        foreach ($params['customfields'] as $key => $value) {
            if (stripos((string) $key, $name) === 0 && $value !== '') {
                return $value;
            }
        }
    }

    return $default;
}

function proxmox_save_service_meta(array $params, $node, $type, $vmid, array $extraValues = [])
{
    $serviceId = isset($params['serviceid']) ? (int) $params['serviceid'] : 0;
    $productId = isset($params['pid']) ? (int) $params['pid'] : (isset($params['packageid']) ? (int) $params['packageid'] : 0);
    $clientId = isset($params['clientsdetails']['userid']) ? (int) $params['clientsdetails']['userid'] : (isset($params['userid']) ? (int) $params['userid'] : null);

    if ($serviceId < 1 || $productId < 1) {
        return;
    }

    $values = [
        'proxmox_node' => (string) $node,
        'proxmox_type' => (string) $type,
        'proxmox_vmid' => (string) (int) $vmid,
    ];
    foreach ($extraValues as $fieldName => $fieldValue) {
        $name = trim((string) $fieldName);
        if ($name !== '') {
            $values[$name] = (string) $fieldValue;
        }
    }

    foreach ($values as $fieldName => $value) {
        $field = Capsule::table('tblcustomfields')
            ->where('type', 'product')
            ->where('relid', $productId)
            ->where(function ($query) use ($fieldName) {
                $query->where('fieldname', $fieldName)
                    ->orWhere('fieldname', 'like', $fieldName . '|%');
            })
            ->first();

        if (!$field) {
            continue;
        }

        $exists = Capsule::table('tblcustomfieldsvalues')
            ->where('fieldid', (int) $field->id)
            ->where('relid', $serviceId)
            ->count();

        if ((int) $exists > 0) {
            Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', (int) $field->id)
                ->where('relid', $serviceId)
                ->update(['value' => $value]);
        } else {
            Capsule::table('tblcustomfieldsvalues')->insert([
                'fieldid' => (int) $field->id,
                'relid' => $serviceId,
                'value' => $value,
            ]);
        }
    }

    if (Capsule::schema()->hasTable('mod_proxmox_manager_services')) {
        $exists = Capsule::table('mod_proxmox_manager_services')->where('service_id', $serviceId)->count();
        $payload = [
            'client_id' => $clientId,
            'node' => (string) $node,
            'resource_type' => (string) $type,
            'vmid' => (int) $vmid,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ((int) $exists > 0) {
            Capsule::table('mod_proxmox_manager_services')->where('service_id', $serviceId)->update($payload);
        } else {
            $payload['service_id'] = $serviceId;
            $payload['created_at'] = date('Y-m-d H:i:s');
            Capsule::table('mod_proxmox_manager_services')->insert($payload);
        }
    }
}

function proxmox_dns_plan(array $params, $osKey)
{
    $key = proxmox_os_choice_key($osKey);
    if (!in_array($key, ['n8n', 'm8n'], true)) {
        return null;
    }

    $serviceId = isset($params['serviceid']) ? (int) $params['serviceid'] : 0;
    if ($serviceId < 1) {
        return null;
    }

    $zone = strtolower(trim((string) proxmox_option($params, 'DNS Forward Zone', 'infra.local')));
    if ($zone === '') {
        return null;
    }

    $prefix = proxmox_os_choice_key(proxmox_option($params, 'DNS Host Prefix', 'n8n'));
    if ($prefix === '') {
        $prefix = 'n8n';
    }

    $host = $prefix . '-' . $serviceId;
    $ip = proxmox_primary_service_ip($params);

    return [
        'hostname_short' => $host,
        'hostname_fqdn' => $host . '.' . $zone,
        'ip' => $ip,
    ];
}

function proxmox_primary_service_ip(array $params)
{
    $candidates = [];
    $candidates[] = isset($params['assignedips']) ? (string) $params['assignedips'] : '';
    $candidates[] = isset($params['dedicatedip']) ? (string) $params['dedicatedip'] : '';
    $candidates[] = (string) proxmox_saved_value($params, 'IP Address', '');

    foreach ($candidates as $source) {
        if ($source === '') {
            continue;
        }
        if (preg_match('/\b((?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)(?:\.(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)){3})\b/', $source, $m)) {
            return (string) $m[1];
        }
    }

    return '';
}

function proxmox_register_dns_record(array $params, $fqdn, $ipAddress)
{
    if (!proxmox_should_register_dns($params)) {
        return;
    }

    $baseUrl = rtrim(trim((string) proxmox_option($params, 'DNS API URL', '')), '/');
    $user = trim((string) proxmox_option($params, 'DNS API User', ''));
    $pass = (string) proxmox_option($params, 'DNS API Password', '');
    $zone = trim((string) proxmox_option($params, 'DNS Forward Zone', 'infra.local'));
    $reverseZone = trim((string) proxmox_option($params, 'DNS Reverse Zone', '10.10.10.in-addr.arpa'));

    if ($baseUrl === '' || $user === '' || $pass === '' || $zone === '' || $reverseZone === '') {
        return;
    }

    $login = proxmox_dns_api_get($baseUrl . '/api/user/login', [
        'user' => $user,
        'pass' => $pass,
    ]);
    if (!isset($login['response']['token'])) {
        throw new \RuntimeException('Technitium login failed.');
    }
    $token = (string) $login['response']['token'];

    proxmox_dns_api_get($baseUrl . '/api/zones/records/add', [
        'token' => $token,
        'domain' => $fqdn,
        'zone' => $zone,
        'type' => 'A',
        'ttl' => 300,
        'ipAddress' => $ipAddress,
        'overwrite' => 'true',
    ]);

    $octets = explode('.', $ipAddress);
    if (count($octets) === 4) {
        proxmox_dns_api_get($baseUrl . '/api/zones/records/add', [
            'token' => $token,
            'domain' => (string) $octets[3],
            'zone' => $reverseZone,
            'type' => 'PTR',
            'ttl' => 300,
            'ptrName' => $fqdn,
            'overwrite' => 'true',
        ]);
    }
}

function proxmox_unregister_dns_record(array $params, $fqdn, $ipAddress)
{
    $baseUrl = rtrim(trim((string) proxmox_option($params, 'DNS API URL', '')), '/');
    $user = trim((string) proxmox_option($params, 'DNS API User', ''));
    $pass = (string) proxmox_option($params, 'DNS API Password', '');
    $zone = trim((string) proxmox_option($params, 'DNS Forward Zone', 'infra.local'));
    $reverseZone = trim((string) proxmox_option($params, 'DNS Reverse Zone', '10.10.10.in-addr.arpa'));

    if ($baseUrl === '' || $user === '' || $pass === '' || $zone === '' || $reverseZone === '') {
        return;
    }

    $login = proxmox_dns_api_get($baseUrl . '/api/user/login', [
        'user' => $user,
        'pass' => $pass,
    ]);
    if (!isset($login['response']['token'])) {
        throw new \RuntimeException('Technitium login failed.');
    }
    $token = (string) $login['response']['token'];

    proxmox_dns_api_get($baseUrl . '/api/zones/records/delete', [
        'token' => $token,
        'domain' => $fqdn,
        'zone' => $zone,
        'type' => 'A',
        'ipAddress' => $ipAddress,
    ]);

    $octets = explode('.', $ipAddress);
    if (count($octets) === 4) {
        proxmox_dns_api_get($baseUrl . '/api/zones/records/delete', [
            'token' => $token,
            'domain' => (string) $octets[3],
            'zone' => $reverseZone,
            'type' => 'PTR',
            'ptrName' => $fqdn,
        ]);
    }
}

function proxmox_should_register_dns(array $params)
{
    $value = proxmox_option($params, 'Auto DNS Registration', 'off');
    if (is_string($value)) {
        return in_array(strtolower($value), ['on', '1', 'yes', 'true'], true);
    }

    return (bool) $value;
}

function proxmox_dns_api_get($url, array $query)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($query));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($errno) {
        throw new \RuntimeException('Technitium API cURL error: ' . $error);
    }

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        throw new \RuntimeException('Technitium API invalid response.');
    }
    if (isset($decoded['status']) && strtolower((string) $decoded['status']) === 'error') {
        $message = isset($decoded['errorMessage']) ? (string) $decoded['errorMessage'] : 'Technitium API error';
        throw new \RuntimeException($message);
    }

    return $decoded;
}

function proxmox_policy_engine_enabled(array $params)
{
    $value = proxmox_option($params, 'Enable Policy Engine', 'off');
    if (is_string($value)) {
        return in_array(strtolower($value), ['on', '1', 'yes', 'true'], true);
    }

    return (bool) $value;
}

function proxmox_policy_for_product(array $params)
{
    if (!Capsule::schema()->hasTable('mod_proxmox_product_policies')) {
        return null;
    }

    $productId = isset($params['pid']) ? (int) $params['pid'] : (isset($params['packageid']) ? (int) $params['packageid'] : 0);
    if ($productId < 1) {
        return null;
    }

    $row = Capsule::table('mod_proxmox_product_policies')
        ->where('product_id', $productId)
        ->where('enabled', 1)
        ->first();

    if (!$row) {
        return null;
    }

    return [
        'id' => isset($row->id) ? (int) $row->id : 0,
        'product_id' => isset($row->product_id) ? (int) $row->product_id : 0,
        'private_pool_id' => isset($row->private_pool_id) ? (int) $row->private_pool_id : 0,
        'internal_dns_zone' => isset($row->internal_dns_zone) ? (string) $row->internal_dns_zone : 'infra.local',
        'service_class' => isset($row->service_class) ? (string) $row->service_class : 'private_only',
        'firewall_profile_key' => isset($row->firewall_profile_key) ? (string) $row->firewall_profile_key : '',
        'strict_mode' => isset($row->strict_mode) ? (int) $row->strict_mode : 0,
        'enabled' => isset($row->enabled) ? (int) $row->enabled : 0,
    ];
}

function proxmox_policy_requires_strict(array $policy)
{
    $value = isset($policy['strict_mode']) ? $policy['strict_mode'] : 0;
    if (is_string($value)) {
        return in_array(strtolower($value), ['on', '1', 'yes', 'true'], true);
    }

    return (int) $value === 1;
}

function proxmox_service_class(array $policy)
{
    $class = strtolower(trim((string) (isset($policy['service_class']) ? $policy['service_class'] : 'private_only')));
    if (!in_array($class, ['private_only', 'shared_edge', 'dedicated_public', 'hybrid'], true)) {
        return 'private_only';
    }

    return $class;
}

function proxmox_validate_pool_for_policy(array $policy)
{
    if (!Capsule::schema()->hasTable('mod_proxmox_ip_pools')) {
        return false;
    }

    $poolId = isset($policy['private_pool_id']) ? (int) $policy['private_pool_id'] : 0;
    if ($poolId < 1) {
        return false;
    }

    $pool = Capsule::table('mod_proxmox_ip_pools')
        ->where('id', $poolId)
        ->where('scope', 'private')
        ->where('enabled', 1)
        ->first();

    return (bool) $pool;
}

function proxmox_plan_events_for_class(array $params, $serviceClass, $phase, array $policy = [])
{
    $class = strtolower(trim((string) $serviceClass));
    $profile = trim((string) (isset($policy['firewall_profile_key']) ? $policy['firewall_profile_key'] : ''));

    if ($phase === 'create_planned') {
        if (in_array($class, ['shared_edge', 'hybrid'], true)) {
            proxmox_audit_event($params, 'edge_route_planned', 'planned', ['service_class' => $class]);
        }
        if (in_array($class, ['dedicated_public', 'hybrid'], true)) {
            proxmox_audit_event($params, 'public_ip_planned', 'planned', ['service_class' => $class]);
            proxmox_audit_event($params, 'public_dns_planned', 'planned', ['service_class' => $class]);
        }
        if ($profile !== '') {
            proxmox_audit_event($params, 'fw_profile_planned', 'planned', ['profile_key' => $profile]);
        }
        return;
    }

    if ($phase === 'suspend_planned') {
        if (in_array($class, ['shared_edge', 'hybrid'], true)) {
            proxmox_audit_event($params, 'edge_route_disable_planned', 'planned', ['service_class' => $class]);
        }
        if ($profile !== '') {
            proxmox_audit_event($params, 'fw_suspend_planned', 'planned', ['profile_key' => $profile]);
        }
        return;
    }

    if ($phase === 'unsuspend_planned') {
        if (in_array($class, ['shared_edge', 'hybrid'], true)) {
            proxmox_audit_event($params, 'edge_route_enable_planned', 'planned', ['service_class' => $class]);
        }
        if ($profile !== '') {
            proxmox_audit_event($params, 'fw_restore_planned', 'planned', ['profile_key' => $profile]);
        }
        return;
    }

    if ($phase === 'terminate_planned') {
        if (in_array($class, ['shared_edge', 'hybrid'], true)) {
            proxmox_audit_event($params, 'edge_route_delete_planned', 'planned', ['service_class' => $class]);
        }
        if (in_array($class, ['dedicated_public', 'hybrid'], true)) {
            proxmox_audit_event($params, 'public_dns_delete_planned', 'planned', ['service_class' => $class]);
            proxmox_audit_event($params, 'public_ip_release_planned', 'planned', ['service_class' => $class]);
        }
        if ($profile !== '') {
            proxmox_audit_event($params, 'fw_remove_planned', 'planned', ['profile_key' => $profile]);
        }
    }
}

function proxmox_lease_private_ip(array $params, array $policy, $vmid, $node, $resourceType)
{
    if (!Capsule::schema()->hasTable('mod_proxmox_ip_pools') || !Capsule::schema()->hasTable('mod_proxmox_ip_leases')) {
        return null;
    }

    $poolId = isset($policy['private_pool_id']) ? (int) $policy['private_pool_id'] : 0;
    if ($poolId < 1) {
        return null;
    }

    $pool = Capsule::table('mod_proxmox_ip_pools')
        ->where('id', $poolId)
        ->where('scope', 'private')
        ->where('enabled', 1)
        ->first();
    if (!$pool) {
        return null;
    }

    $serviceId = isset($params['serviceid']) ? (int) $params['serviceid'] : 0;
    $clientId = isset($params['clientsdetails']['userid']) ? (int) $params['clientsdetails']['userid'] : (isset($params['userid']) ? (int) $params['userid'] : null);

    $existing = Capsule::table('mod_proxmox_ip_leases')
        ->where('service_id', $serviceId)
        ->where('pool_id', $poolId)
        ->whereIn('status', ['reserved', 'assigned'])
        ->orderBy('id', 'desc')
        ->first();
    if ($existing) {
        return [
            'lease_id' => (int) $existing->id,
            'pool_id' => $poolId,
            'ip_address' => (string) $existing->ip_address,
            'gateway' => (string) $pool->gateway,
            'cidr' => (string) $pool->cidr,
        ];
    }

    $candidate = Capsule::table('mod_proxmox_ip_leases')
        ->where('pool_id', $poolId)
        ->where('status', 'free')
        ->orderBy('id', 'asc')
        ->first();
    if (!$candidate) {
        return null;
    }

    $updated = Capsule::table('mod_proxmox_ip_leases')
        ->where('id', (int) $candidate->id)
        ->where('status', 'free')
        ->update([
            'status' => 'assigned',
            'service_id' => $serviceId,
            'client_id' => $clientId,
            'vmid' => (int) $vmid,
            'node' => (string) $node,
            'resource_type' => (string) $resourceType,
            'lease_started_at' => date('Y-m-d H:i:s'),
            'lease_ended_at' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

    if ((int) $updated < 1) {
        return null;
    }

    return [
        'lease_id' => (int) $candidate->id,
        'pool_id' => $poolId,
        'ip_address' => (string) $candidate->ip_address,
        'gateway' => (string) $pool->gateway,
        'cidr' => (string) $pool->cidr,
    ];
}

function proxmox_build_static_ipconfig(array $lease)
{
    $ipAddress = isset($lease['ip_address']) ? trim((string) $lease['ip_address']) : '';
    $gateway = isset($lease['gateway']) ? trim((string) $lease['gateway']) : '';
    $cidr = isset($lease['cidr']) ? trim((string) $lease['cidr']) : '';
    $prefix = '24';
    if ($cidr !== '' && strpos($cidr, '/') !== false) {
        $parts = explode('/', $cidr, 2);
        if (isset($parts[1]) && ctype_digit((string) $parts[1])) {
            $prefix = (string) $parts[1];
        }
    }

    if ($ipAddress === '' || $gateway === '') {
        return 'ip=dhcp';
    }

    return 'ip=' . $ipAddress . '/' . $prefix . ',gw=' . $gateway;
}

function proxmox_release_private_ip(array $params)
{
    if (!Capsule::schema()->hasTable('mod_proxmox_ip_leases')) {
        return;
    }

    $serviceId = isset($params['serviceid']) ? (int) $params['serviceid'] : 0;
    if ($serviceId < 1) {
        return;
    }

    Capsule::table('mod_proxmox_ip_leases')
        ->where('service_id', $serviceId)
        ->whereIn('status', ['reserved', 'assigned'])
        ->update([
            'status' => 'free',
            'service_id' => null,
            'client_id' => null,
            'vmid' => null,
            'node' => null,
            'resource_type' => null,
            'lease_ended_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
}

function proxmox_save_service_state(array $params, array $state)
{
    if (!Capsule::schema()->hasTable('mod_proxmox_service_state')) {
        return;
    }

    $serviceId = isset($params['serviceid']) ? (int) $params['serviceid'] : 0;
    if ($serviceId < 1) {
        return;
    }

    $payload = [
        'policy_id' => isset($state['policy_id']) ? (int) $state['policy_id'] : null,
        'private_ip' => isset($state['private_ip']) ? (string) $state['private_ip'] : null,
        'public_ip' => isset($state['public_ip']) ? (string) $state['public_ip'] : null,
        'provision_state' => isset($state['provision_state']) ? (string) $state['provision_state'] : 'provisioned',
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    $exists = Capsule::table('mod_proxmox_service_state')->where('service_id', $serviceId)->count();
    if ((int) $exists > 0) {
        Capsule::table('mod_proxmox_service_state')->where('service_id', $serviceId)->update($payload);
    } else {
        $payload['service_id'] = $serviceId;
        $payload['created_at'] = date('Y-m-d H:i:s');
        Capsule::table('mod_proxmox_service_state')->insert($payload);
    }
}

function proxmox_delete_service_state(array $params)
{
    if (!Capsule::schema()->hasTable('mod_proxmox_service_state')) {
        return;
    }

    $serviceId = isset($params['serviceid']) ? (int) $params['serviceid'] : 0;
    if ($serviceId > 0) {
        Capsule::table('mod_proxmox_service_state')->where('service_id', $serviceId)->delete();
    }
}

function proxmox_audit_event(array $params, $eventType, $status, array $request = [], array $response = [], $error = null)
{
    if (!Capsule::schema()->hasTable('mod_proxmox_audit_events')) {
        return;
    }

    Capsule::table('mod_proxmox_audit_events')->insert([
        'service_id' => isset($params['serviceid']) ? (int) $params['serviceid'] : 0,
        'event_type' => (string) $eventType,
        'status' => (string) $status,
        'request_payload' => !empty($request) ? json_encode($request) : null,
        'response_payload' => !empty($response) ? json_encode($response) : null,
        'error_message' => $error !== null ? (string) $error : null,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

function proxmox_delete_mapping(array $params)
{
    if (!Capsule::schema()->hasTable('mod_proxmox_manager_services')) {
        return;
    }

    $serviceId = isset($params['serviceid']) ? (int) $params['serviceid'] : 0;
    if ($serviceId > 0) {
        Capsule::table('mod_proxmox_manager_services')->where('service_id', $serviceId)->delete();
    }
}

function proxmox_log_task(array $params, $action, $status, $errorMessage = null, array $responsePayload = [])
{
    if (!Capsule::schema()->hasTable('mod_proxmox_manager_tasks')) {
        return;
    }

    $identity = proxmox_identity($params);

    Capsule::table('mod_proxmox_manager_tasks')->insert([
        'service_id' => isset($params['serviceid']) ? (int) $params['serviceid'] : 0,
        'client_id' => isset($params['clientsdetails']['userid']) ? (int) $params['clientsdetails']['userid'] : (isset($params['userid']) ? (int) $params['userid'] : null),
        'node' => $identity['node'] ? (string) $identity['node'] : null,
        'resource_type' => $identity['type'] ? (string) $identity['type'] : null,
        'vmid' => $identity['vmid'] ? (int) $identity['vmid'] : null,
        'action' => (string) $action,
        'status' => (string) $status,
        'request_payload' => json_encode([
            'serviceid' => isset($params['serviceid']) ? (int) $params['serviceid'] : 0,
            'product' => isset($params['productname']) ? $params['productname'] : null,
        ]),
        'response_payload' => !empty($responsePayload) ? json_encode($responsePayload) : null,
        'error_message' => $errorMessage,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}
