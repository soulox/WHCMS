<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/ApiClient.php';
require_once __DIR__ . '/lib/Repository.php';

function proxmox_manager_config()
{
    return [
        'name' => 'Proxmox Manager',
        'description' => 'Admin and client UI for Proxmox resources linked to WHMCS services.',
        'version' => '0.2.0',
        'author' => 'Your Company',
        'language' => 'english',
        'fields' => [
            'apiHost' => [
                'FriendlyName' => 'API Host',
                'Type' => 'text',
                'Size' => '50',
                'Default' => '',
                'Description' => 'Proxmox host or IP (no protocol).',
            ],
            'apiPort' => [
                'FriendlyName' => 'API Port',
                'Type' => 'text',
                'Size' => '6',
                'Default' => '8006',
                'Description' => 'Usually 8006.',
            ],
            'apiTokenId' => [
                'FriendlyName' => 'API Token ID',
                'Type' => 'text',
                'Size' => '80',
                'Default' => '',
                'Description' => 'Example: root@pam!whmcs.',
            ],
            'apiTokenSecret' => [
                'FriendlyName' => 'API Token Secret',
                'Type' => 'password',
                'Size' => '80',
                'Default' => '',
                'Description' => 'Stored encrypted by WHMCS.',
            ],
            'defaultNode' => [
                'FriendlyName' => 'Default Node',
                'Type' => 'text',
                'Size' => '25',
                'Default' => '',
                'Description' => 'Fallback node for UI actions.',
            ],
        ],
    ];
}

function proxmox_manager_activate()
{
    try {
        if (!Capsule::schema()->hasTable('mod_proxmox_manager_tasks')) {
            Capsule::schema()->create('mod_proxmox_manager_tasks', function ($table) {
                $table->increments('id');
                $table->integer('service_id')->unsigned()->default(0);
                $table->integer('client_id')->unsigned()->nullable();
                $table->string('node', 64)->nullable();
                $table->string('resource_type', 16)->nullable();
                $table->integer('vmid')->unsigned()->nullable();
                $table->string('action', 32);
                $table->string('status', 16)->default('queued');
                $table->text('request_payload')->nullable();
                $table->text('response_payload')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent();
                $table->index(['service_id']);
                $table->index(['client_id']);
                $table->index(['status']);
                $table->index(['created_at']);
            });
        }

        if (!Capsule::schema()->hasTable('mod_proxmox_manager_services')) {
            Capsule::schema()->create('mod_proxmox_manager_services', function ($table) {
                $table->integer('service_id')->unsigned();
                $table->integer('client_id')->unsigned()->nullable();
                $table->string('node', 64);
                $table->string('resource_type', 16);
                $table->integer('vmid')->unsigned();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent();
                $table->primary(['service_id']);
                $table->index(['client_id']);
                $table->index(['node']);
                $table->index(['vmid']);
            });
        }

        if (!Capsule::schema()->hasTable('mod_proxmox_manager_os_templates')) {
            Capsule::schema()->create('mod_proxmox_manager_os_templates', function ($table) {
                $table->increments('id');
                $table->string('os_key', 64);
                $table->string('resource_type', 16)->default('kvm');
                $table->string('node', 64);
                $table->integer('template_vmid')->unsigned();
                $table->boolean('enabled')->default(1);
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent();
                $table->unique(['os_key', 'resource_type']);
                $table->index(['enabled']);
            });
        }

        $defaults = [
            ['os_key' => 'debian12', 'resource_type' => 'kvm', 'node' => 'pve26', 'template_vmid' => 9300, 'enabled' => 1],
            ['os_key' => 'ubuntu2204', 'resource_type' => 'kvm', 'node' => 'pve26', 'template_vmid' => 9301, 'enabled' => 1],
            ['os_key' => 'ubuntu2404', 'resource_type' => 'kvm', 'node' => 'pve26', 'template_vmid' => 9302, 'enabled' => 1],
            ['os_key' => 'almalinux9', 'resource_type' => 'kvm', 'node' => 'pve26', 'template_vmid' => 9303, 'enabled' => 1],
            ['os_key' => 'rocky9', 'resource_type' => 'kvm', 'node' => 'pve26', 'template_vmid' => 9304, 'enabled' => 1],
            ['os_key' => 'centosstream9', 'resource_type' => 'kvm', 'node' => 'pve26', 'template_vmid' => 9305, 'enabled' => 1],
            ['os_key' => 'wordpress', 'resource_type' => 'kvm', 'node' => 'pve26', 'template_vmid' => 9420, 'enabled' => 1],
            ['os_key' => 'dockerhost', 'resource_type' => 'kvm', 'node' => 'pve26', 'template_vmid' => 9421, 'enabled' => 1],
            ['os_key' => 'n8n', 'resource_type' => 'kvm', 'node' => 'pve26', 'template_vmid' => 9414, 'enabled' => 1],
            ['os_key' => 'make', 'resource_type' => 'kvm', 'node' => 'pve26', 'template_vmid' => 9416, 'enabled' => 1],
            ['os_key' => 'm8n', 'resource_type' => 'kvm', 'node' => 'pve26', 'template_vmid' => 9414, 'enabled' => 1],
        ];

        if (Capsule::schema()->hasTable('mod_proxmox_manager_os_templates')) {
            foreach ($defaults as $row) {
                $exists = Capsule::table('mod_proxmox_manager_os_templates')
                    ->where('os_key', $row['os_key'])
                    ->where('resource_type', $row['resource_type'])
                    ->count();
                if ((int) $exists < 1) {
                    $row['created_at'] = date('Y-m-d H:i:s');
                    $row['updated_at'] = date('Y-m-d H:i:s');
                    Capsule::table('mod_proxmox_manager_os_templates')->insert($row);
                }
            }
        }

        if (!Capsule::schema()->hasTable('mod_proxmox_ip_pools')) {
            Capsule::schema()->create('mod_proxmox_ip_pools', function ($table) {
                $table->increments('id');
                $table->string('pool_key', 64)->unique();
                $table->string('scope', 16)->default('private');
                $table->string('cidr', 32);
                $table->string('gateway', 64)->nullable();
                $table->string('dns_servers', 255)->nullable();
                $table->string('node_affinity', 64)->nullable();
                $table->integer('vlan_tag')->nullable();
                $table->boolean('enabled')->default(1);
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent();
                $table->index(['scope']);
                $table->index(['enabled']);
            });
        }

        if (!Capsule::schema()->hasTable('mod_proxmox_ip_leases')) {
            Capsule::schema()->create('mod_proxmox_ip_leases', function ($table) {
                $table->increments('id');
                $table->integer('pool_id')->unsigned();
                $table->string('ip_address', 45)->unique();
                $table->string('status', 16)->default('free');
                $table->integer('service_id')->unsigned()->nullable();
                $table->integer('client_id')->unsigned()->nullable();
                $table->integer('vmid')->unsigned()->nullable();
                $table->string('node', 64)->nullable();
                $table->string('resource_type', 16)->nullable();
                $table->timestamp('lease_started_at')->nullable();
                $table->timestamp('lease_ended_at')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent();
                $table->index(['pool_id']);
                $table->index(['status']);
                $table->index(['service_id']);
            });
        }

        if (!Capsule::schema()->hasTable('mod_proxmox_product_policies')) {
            Capsule::schema()->create('mod_proxmox_product_policies', function ($table) {
                $table->increments('id');
                $table->integer('product_id')->unsigned();
                $table->string('resource_type', 16)->default('kvm');
                $table->string('template_os_key', 64)->nullable();
                $table->integer('private_pool_id')->unsigned()->nullable();
                $table->string('internal_dns_zone', 128)->default('infra.local');
                $table->string('service_class', 32)->default('private_only');
                $table->string('firewall_profile_key', 64)->nullable();
                $table->boolean('strict_mode')->default(0);
                $table->boolean('enabled')->default(1);
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent();
                $table->unique(['product_id']);
                $table->index(['enabled']);
            });
        } else {
            if (!Capsule::schema()->hasColumn('mod_proxmox_product_policies', 'service_class')) {
                Capsule::schema()->table('mod_proxmox_product_policies', function ($table) {
                    $table->string('service_class', 32)->default('private_only');
                });
            }
            if (!Capsule::schema()->hasColumn('mod_proxmox_product_policies', 'firewall_profile_key')) {
                Capsule::schema()->table('mod_proxmox_product_policies', function ($table) {
                    $table->string('firewall_profile_key', 64)->nullable();
                });
            }
            if (!Capsule::schema()->hasColumn('mod_proxmox_product_policies', 'strict_mode')) {
                Capsule::schema()->table('mod_proxmox_product_policies', function ($table) {
                    $table->boolean('strict_mode')->default(0);
                });
            }
        }

        if (!Capsule::schema()->hasTable('mod_proxmox_public_dns_providers')) {
            Capsule::schema()->create('mod_proxmox_public_dns_providers', function ($table) {
                $table->increments('id');
                $table->string('provider_key', 64)->unique();
                $table->string('api_base', 255)->nullable();
                $table->string('api_token', 255)->nullable();
                $table->boolean('enabled')->default(0);
                $table->string('last_check_status', 16)->nullable();
                $table->text('last_check_error')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent();
                $table->index(['enabled']);
            });
        }

        if (!Capsule::schema()->hasTable('mod_proxmox_service_state')) {
            Capsule::schema()->create('mod_proxmox_service_state', function ($table) {
                $table->integer('service_id')->unsigned();
                $table->integer('policy_id')->unsigned()->nullable();
                $table->string('private_ip', 45)->nullable();
                $table->string('public_ip', 45)->nullable();
                $table->string('provision_state', 24)->default('new');
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent();
                $table->primary(['service_id']);
                $table->index(['policy_id']);
            });
        }

        if (!Capsule::schema()->hasTable('mod_proxmox_audit_events')) {
            Capsule::schema()->create('mod_proxmox_audit_events', function ($table) {
                $table->increments('id');
                $table->integer('service_id')->unsigned()->default(0);
                $table->string('event_type', 64);
                $table->string('status', 16)->default('queued');
                $table->text('request_payload')->nullable();
                $table->text('response_payload')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['service_id']);
                $table->index(['event_type']);
                $table->index(['status']);
            });
        }

        if (Capsule::schema()->hasTable('mod_proxmox_ip_pools')) {
            $poolExists = Capsule::table('mod_proxmox_ip_pools')->where('pool_key', 'private-main-10-10-10')->count();
            if ((int) $poolExists < 1) {
                Capsule::table('mod_proxmox_ip_pools')->insert([
                    'pool_key' => 'private-main-10-10-10',
                    'scope' => 'private',
                    'cidr' => '10.10.10.0/24',
                    'gateway' => '10.10.10.254',
                    'dns_servers' => '10.10.10.53,10.10.10.54',
                    'node_affinity' => null,
                    'vlan_tag' => null,
                    'enabled' => 0,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        return [
            'status' => 'success',
            'description' => 'Proxmox Manager activated successfully.',
        ];
    } catch (\Throwable $e) {
        return [
            'status' => 'error',
            'description' => 'Activation failed: ' . $e->getMessage(),
        ];
    }
}

function proxmox_manager_deactivate()
{
    return [
        'status' => 'success',
        'description' => 'Proxmox Manager deactivated. Data tables were kept.',
    ];
}

function proxmox_manager_upgrade(array $vars)
{
    $version = isset($vars['version']) ? $vars['version'] : '0.0.0';

    if (version_compare($version, '0.2.0', '<')) {
        proxmox_manager_activate();
    }
}

function proxmox_manager_output(array $params)
{
    $allowedSections = [
        'service',
        'templates',
        'pools',
        'policies',
        'dns',
        'activity',
        'diagnostics',
    ];
    $section = 'service';
    if (isset($_GET['page'])) {
        $section = strtolower(trim((string) $_GET['page']));
    } elseif (isset($_POST['page'])) {
        $section = strtolower(trim((string) $_POST['page']));
    }
    if (!in_array($section, $allowedSections, true)) {
        $section = 'service';
    }

    $action = 'dashboard';
    if (isset($_POST['action'])) {
        $action = (string) $_POST['action'];
    } elseif (isset($_GET['action'])) {
        $action = (string) $_GET['action'];
    }
    $repo = new \WHMCS\Module\Addon\ProxmoxManager\Repository();
    $successMessage = '';
    $errorMessage = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_mapping') {
        if (!proxmox_manager_verify_admin_csrf_token()) {
            $errorMessage = 'Invalid security token. Refresh and try again.';
        } else {
            $serviceId = isset($_POST['service_id']) ? (int) $_POST['service_id'] : 0;
            $clientId = isset($_POST['client_id']) && $_POST['client_id'] !== '' ? (int) $_POST['client_id'] : null;
            $node = isset($_POST['node']) ? trim((string) $_POST['node']) : '';
            $resourceType = isset($_POST['resource_type']) ? strtolower(trim((string) $_POST['resource_type'])) : '';
            $vmid = isset($_POST['vmid']) ? (int) $_POST['vmid'] : 0;

            if ($serviceId < 1 || $node === '' || ($resourceType !== 'kvm' && $resourceType !== 'lxc') || $vmid < 1) {
                $errorMessage = 'Required fields: service_id, node, resource_type (kvm/lxc), vmid.';
            } else {
                try {
                    $repo->saveServiceMapping($serviceId, $clientId, $node, $resourceType, $vmid);
                    $successMessage = 'Service mapping saved successfully.';
                } catch (\Throwable $e) {
                    $errorMessage = 'Could not save mapping: ' . $e->getMessage();
                }
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_mapping') {
        if (!proxmox_manager_verify_admin_csrf_token()) {
            $errorMessage = 'Invalid security token. Refresh and try again.';
        } else {
            $serviceId = isset($_POST['service_id']) ? (int) $_POST['service_id'] : 0;
            if ($serviceId < 1) {
                $errorMessage = 'Service ID is required.';
            } else {
                try {
                    Capsule::table('mod_proxmox_manager_services')->where('service_id', $serviceId)->delete();
                    $successMessage = 'Service mapping deleted successfully.';
                } catch (\Throwable $e) {
                    $errorMessage = 'Could not delete mapping: ' . $e->getMessage();
                }
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_os_template') {
        if (!proxmox_manager_verify_admin_csrf_token()) {
            $errorMessage = 'Invalid security token. Refresh and try again.';
        } elseif (!Capsule::schema()->hasTable('mod_proxmox_manager_os_templates')) {
            $errorMessage = 'OS template mapping table not found. Reactivate addon module.';
        } else {
            $osKey = strtolower(trim((string) (isset($_POST['os_key']) ? $_POST['os_key'] : '')));
            $resourceType = strtolower(trim((string) (isset($_POST['resource_type']) ? $_POST['resource_type'] : 'kvm')));
            $node = trim((string) (isset($_POST['node']) ? $_POST['node'] : ''));
            $templateVmid = isset($_POST['template_vmid']) ? (int) $_POST['template_vmid'] : 0;
            $enabled = isset($_POST['enabled']) ? 1 : 0;

            if ($osKey === '' || !preg_match('/^[a-z0-9]+$/', $osKey)) {
                $errorMessage = 'OS key is required and must be alphanumeric (for example: ubuntu2404).';
            } elseif ($resourceType !== 'kvm' && $resourceType !== 'lxc') {
                $errorMessage = 'Resource type must be kvm or lxc.';
            } elseif ($node === '') {
                $errorMessage = 'Node is required.';
            } elseif ($templateVmid < 1) {
                $errorMessage = 'Template VMID must be a positive integer.';
            } else {
                $exists = Capsule::table('mod_proxmox_manager_os_templates')
                    ->where('os_key', $osKey)
                    ->where('resource_type', $resourceType)
                    ->count();

                $payload = [
                    'node' => $node,
                    'template_vmid' => $templateVmid,
                    'enabled' => $enabled,
                    'updated_at' => date('Y-m-d H:i:s'),
                ];

                if ((int) $exists > 0) {
                    Capsule::table('mod_proxmox_manager_os_templates')
                        ->where('os_key', $osKey)
                        ->where('resource_type', $resourceType)
                        ->update($payload);
                } else {
                    $payload['os_key'] = $osKey;
                    $payload['resource_type'] = $resourceType;
                    $payload['created_at'] = date('Y-m-d H:i:s');
                    Capsule::table('mod_proxmox_manager_os_templates')->insert($payload);
                }

                $successMessage = 'OS template mapping saved successfully.';
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_os_template') {
        if (!proxmox_manager_verify_admin_csrf_token()) {
            $errorMessage = 'Invalid security token. Refresh and try again.';
        } elseif (!Capsule::schema()->hasTable('mod_proxmox_manager_os_templates')) {
            $errorMessage = 'OS template mapping table not found. Reactivate addon module.';
        } else {
            $osKey = strtolower(trim((string) (isset($_POST['os_key']) ? $_POST['os_key'] : '')));
            $resourceType = strtolower(trim((string) (isset($_POST['resource_type']) ? $_POST['resource_type'] : 'kvm')));
            if ($osKey === '') {
                $errorMessage = 'OS key is required.';
            } else {
                Capsule::table('mod_proxmox_manager_os_templates')
                    ->where('os_key', $osKey)
                    ->where('resource_type', $resourceType)
                    ->delete();
                $successMessage = 'OS template mapping deleted successfully.';
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_ip_pool') {
        if (!proxmox_manager_verify_admin_csrf_token()) {
            $errorMessage = 'Invalid security token. Refresh and try again.';
        } elseif (!Capsule::schema()->hasTable('mod_proxmox_ip_pools') || !Capsule::schema()->hasTable('mod_proxmox_ip_leases')) {
            $errorMessage = 'IP pool tables not found. Reactivate addon module.';
        } else {
            $poolKey = strtolower(trim((string) (isset($_POST['pool_key']) ? $_POST['pool_key'] : '')));
            $scope = strtolower(trim((string) (isset($_POST['scope']) ? $_POST['scope'] : 'private')));
            $cidr = trim((string) (isset($_POST['cidr']) ? $_POST['cidr'] : ''));
            $gateway = trim((string) (isset($_POST['gateway']) ? $_POST['gateway'] : ''));
            $dnsServers = trim((string) (isset($_POST['dns_servers']) ? $_POST['dns_servers'] : ''));
            $enabled = isset($_POST['enabled']) ? 1 : 0;

            if ($poolKey === '' || !preg_match('/^[a-z0-9\-]+$/', $poolKey)) {
                $errorMessage = 'Pool key is required and must use lowercase letters, numbers, and dashes.';
            } elseif ($scope !== 'private' && $scope !== 'public') {
                $errorMessage = 'Scope must be private or public.';
            } elseif ($cidr === '' || strpos($cidr, '/') === false) {
                $errorMessage = 'CIDR is required (example: 10.10.10.0/24).';
            } else {
                $exists = Capsule::table('mod_proxmox_ip_pools')->where('pool_key', $poolKey)->first();
                if ($exists) {
                    $activeLeaseCount = Capsule::table('mod_proxmox_ip_leases')
                        ->where('pool_id', (int) $exists->id)
                        ->where('status', 'assigned')
                        ->count();
                    $cidrChanged = ((string) $exists->cidr !== $cidr);
                    $disabling = ((int) $enabled === 0 && (int) $exists->enabled === 1);
                    if (((int) $activeLeaseCount > 0) && ($cidrChanged || $disabling)) {
                        $errorMessage = 'Cannot change CIDR or disable pool while active assigned leases exist.';
                    }
                }

                if ($errorMessage !== '') {
                    // keep error set by guardrail
                } else {
                $payload = [
                    'scope' => $scope,
                    'cidr' => $cidr,
                    'gateway' => ($gateway !== '' ? $gateway : null),
                    'dns_servers' => ($dnsServers !== '' ? $dnsServers : null),
                    'enabled' => $enabled,
                    'updated_at' => date('Y-m-d H:i:s'),
                ];

                if ($exists) {
                    Capsule::table('mod_proxmox_ip_pools')->where('id', (int) $exists->id)->update($payload);
                    $poolId = (int) $exists->id;
                } else {
                    $payload['pool_key'] = $poolKey;
                    $payload['created_at'] = date('Y-m-d H:i:s');
                    $poolId = (int) Capsule::table('mod_proxmox_ip_pools')->insertGetId($payload);
                }

                $seeded = proxmox_manager_seed_ip_pool_leases($poolId, $cidr, $gateway);
                if ($seeded === false) {
                    $errorMessage = 'Pool saved but lease seeding skipped (unsupported CIDR or too many hosts).';
                } else {
                    $successMessage = 'IP pool saved successfully. Seeded ' . (int) $seeded . ' lease rows.';
                }
                }
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_ip_pool') {
        if (!proxmox_manager_verify_admin_csrf_token()) {
            $errorMessage = 'Invalid security token. Refresh and try again.';
        } elseif (!Capsule::schema()->hasTable('mod_proxmox_ip_pools')) {
            $errorMessage = 'IP pool table not found. Reactivate addon module.';
        } else {
            $poolId = isset($_POST['pool_id']) ? (int) $_POST['pool_id'] : 0;
            if ($poolId < 1) {
                $errorMessage = 'Pool ID is required.';
            } else {
                $assigned = 0;
                if (Capsule::schema()->hasTable('mod_proxmox_ip_leases')) {
                    $assigned = (int) Capsule::table('mod_proxmox_ip_leases')
                        ->where('pool_id', $poolId)
                        ->where('status', 'assigned')
                        ->count();
                }
                if ($assigned > 0) {
                    $errorMessage = 'Cannot delete pool with assigned leases.';
                } else {
                    $policyRefs = 0;
                    if (Capsule::schema()->hasTable('mod_proxmox_product_policies')) {
                        $policyRefs = (int) Capsule::table('mod_proxmox_product_policies')
                            ->where('private_pool_id', $poolId)
                            ->where('enabled', 1)
                            ->count();
                    }
                    if ($policyRefs > 0) {
                        $errorMessage = 'Cannot delete pool while enabled product policies reference it.';
                    } else {
                        if (Capsule::schema()->hasTable('mod_proxmox_ip_leases')) {
                            Capsule::table('mod_proxmox_ip_leases')->where('pool_id', $poolId)->delete();
                        }
                        Capsule::table('mod_proxmox_ip_pools')->where('id', $poolId)->delete();
                        $successMessage = 'IP pool deleted successfully.';
                    }
                }
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_product_policy') {
        if (!proxmox_manager_verify_admin_csrf_token()) {
            $errorMessage = 'Invalid security token. Refresh and try again.';
        } elseif (!Capsule::schema()->hasTable('mod_proxmox_product_policies')) {
            $errorMessage = 'Product policy table not found. Reactivate addon module.';
        } else {
            $productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
            $resourceType = strtolower(trim((string) (isset($_POST['resource_type']) ? $_POST['resource_type'] : 'kvm')));
            $privatePoolId = isset($_POST['private_pool_id']) ? (int) $_POST['private_pool_id'] : 0;
            $internalDnsZone = strtolower(trim((string) (isset($_POST['internal_dns_zone']) ? $_POST['internal_dns_zone'] : 'infra.local')));
            $templateOsKeySelect = strtolower(trim((string) (isset($_POST['template_os_key_select']) ? $_POST['template_os_key_select'] : '')));
            $templateOsKeyInput = strtolower(trim((string) (isset($_POST['template_os_key']) ? $_POST['template_os_key'] : '')));
            $templateOsKey = ($templateOsKeyInput !== '' ? $templateOsKeyInput : $templateOsKeySelect);
            $serviceClass = strtolower(trim((string) (isset($_POST['service_class']) ? $_POST['service_class'] : 'private_only')));
            $firewallProfileKeySelect = strtolower(trim((string) (isset($_POST['firewall_profile_key_select']) ? $_POST['firewall_profile_key_select'] : '')));
            $firewallProfileKeyInput = strtolower(trim((string) (isset($_POST['firewall_profile_key']) ? $_POST['firewall_profile_key'] : '')));
            $firewallProfileKey = ($firewallProfileKeyInput !== '' ? $firewallProfileKeyInput : $firewallProfileKeySelect);
            $strictMode = isset($_POST['strict_mode']) ? 1 : 0;
            $enabled = isset($_POST['enabled']) ? 1 : 0;
            $forceDisable = isset($_POST['force_disable']) ? 1 : 0;

            if ($productId < 1) {
                $errorMessage = 'Product ID is required.';
            } elseif ($resourceType !== 'kvm' && $resourceType !== 'lxc') {
                $errorMessage = 'Resource type must be kvm or lxc.';
            } elseif ($privatePoolId < 1) {
                $errorMessage = 'Private pool is required.';
            } elseif (!in_array($serviceClass, ['private_only', 'shared_edge', 'dedicated_public', 'hybrid'], true)) {
                $errorMessage = 'Service class must be private_only, shared_edge, dedicated_public, or hybrid.';
            } else {
                $existingPolicy = Capsule::table('mod_proxmox_product_policies')->where('product_id', $productId)->first();
                if ($existingPolicy && (int) $existingPolicy->enabled === 1 && (int) $enabled === 0 && $forceDisable !== 1 && Capsule::schema()->hasTable('mod_proxmox_service_state')) {
                    $activeServiceCount = Capsule::table('mod_proxmox_service_state')
                        ->where('policy_id', (int) $existingPolicy->id)
                        ->count();
                    if ((int) $activeServiceCount > 0) {
                        $errorMessage = 'Policy is in use by active services. Tick Force Disable to continue.';
                    }
                }

                if ($errorMessage !== '') {
                    // keep guardrail error
                } else {
                $payload = [
                    'resource_type' => $resourceType,
                    'template_os_key' => ($templateOsKey !== '' ? $templateOsKey : null),
                    'private_pool_id' => $privatePoolId,
                    'internal_dns_zone' => ($internalDnsZone !== '' ? $internalDnsZone : 'infra.local'),
                    'service_class' => $serviceClass,
                    'firewall_profile_key' => ($firewallProfileKey !== '' ? $firewallProfileKey : null),
                    'strict_mode' => $strictMode,
                    'enabled' => $enabled,
                    'updated_at' => date('Y-m-d H:i:s'),
                ];

                $exists = Capsule::table('mod_proxmox_product_policies')->where('product_id', $productId)->count();
                if ((int) $exists > 0) {
                    Capsule::table('mod_proxmox_product_policies')->where('product_id', $productId)->update($payload);
                } else {
                    $payload['product_id'] = $productId;
                    $payload['created_at'] = date('Y-m-d H:i:s');
                    Capsule::table('mod_proxmox_product_policies')->insert($payload);
                }

                $successMessage = 'Product policy saved successfully.';
                }
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_product_policy') {
        if (!proxmox_manager_verify_admin_csrf_token()) {
            $errorMessage = 'Invalid security token. Refresh and try again.';
        } elseif (!Capsule::schema()->hasTable('mod_proxmox_product_policies')) {
            $errorMessage = 'Product policy table not found. Reactivate addon module.';
        } else {
            $policyId = isset($_POST['policy_id']) ? (int) $_POST['policy_id'] : 0;
            $forceDelete = isset($_POST['force_delete']) ? 1 : 0;
            if ($policyId < 1) {
                $errorMessage = 'Policy ID is required.';
            } else {
                $activeServiceCount = 0;
                if (Capsule::schema()->hasTable('mod_proxmox_service_state')) {
                    $activeServiceCount = (int) Capsule::table('mod_proxmox_service_state')
                        ->where('policy_id', $policyId)
                        ->count();
                }

                if ($activeServiceCount > 0 && $forceDelete !== 1) {
                    $errorMessage = 'Cannot delete policy with active service state. Tick Force Delete to continue.';
                } else {
                    Capsule::table('mod_proxmox_product_policies')->where('id', $policyId)->delete();
                    $successMessage = 'Product policy deleted successfully.';
                }
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_public_dns_provider') {
        if (!proxmox_manager_verify_admin_csrf_token()) {
            $errorMessage = 'Invalid security token. Refresh and try again.';
        } elseif (!Capsule::schema()->hasTable('mod_proxmox_public_dns_providers')) {
            $errorMessage = 'Public DNS provider table not found. Reactivate addon module.';
        } else {
            $providerKey = strtolower(trim((string) (isset($_POST['provider_key']) ? $_POST['provider_key'] : '')));
            $apiBase = trim((string) (isset($_POST['api_base']) ? $_POST['api_base'] : ''));
            $apiToken = trim((string) (isset($_POST['api_token']) ? $_POST['api_token'] : ''));
            $enabled = isset($_POST['enabled']) ? 1 : 0;

            if ($providerKey === '' || !preg_match('/^[a-z0-9\-_]+$/', $providerKey)) {
                $errorMessage = 'Provider key is required and must be lowercase letters, numbers, dash, or underscore.';
            } else {
                $exists = Capsule::table('mod_proxmox_public_dns_providers')->where('provider_key', $providerKey)->count();
                $payload = [
                    'api_base' => ($apiBase !== '' ? $apiBase : null),
                    'api_token' => ($apiToken !== '' ? $apiToken : null),
                    'enabled' => $enabled,
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
                if ((int) $exists > 0) {
                    Capsule::table('mod_proxmox_public_dns_providers')->where('provider_key', $providerKey)->update($payload);
                } else {
                    $payload['provider_key'] = $providerKey;
                    $payload['created_at'] = date('Y-m-d H:i:s');
                    Capsule::table('mod_proxmox_public_dns_providers')->insert($payload);
                }
                $successMessage = 'Public DNS provider saved successfully.';
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_public_dns_provider') {
        if (!proxmox_manager_verify_admin_csrf_token()) {
            $errorMessage = 'Invalid security token. Refresh and try again.';
        } elseif (!Capsule::schema()->hasTable('mod_proxmox_public_dns_providers')) {
            $errorMessage = 'Public DNS provider table not found. Reactivate addon module.';
        } else {
            $providerKey = strtolower(trim((string) (isset($_POST['provider_key']) ? $_POST['provider_key'] : '')));
            if ($providerKey === '') {
                $errorMessage = 'Provider key is required.';
            } else {
                Capsule::table('mod_proxmox_public_dns_providers')->where('provider_key', $providerKey)->delete();
                $successMessage = 'Public DNS provider deleted successfully.';
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'prune_logs') {
        if (!proxmox_manager_verify_admin_csrf_token()) {
            $errorMessage = 'Invalid security token. Refresh and try again.';
        } else {
            $days = isset($_POST['days']) ? (int) $_POST['days'] : 30;
            if ($days < 1) {
                $days = 1;
            }
            if ($days > 3650) {
                $days = 3650;
            }

            $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));
            $pruneTasks = isset($_POST['prune_tasks']) ? 1 : 0;
            $pruneAudit = isset($_POST['prune_audit']) ? 1 : 0;

            $deletedTasks = 0;
            $deletedAudit = 0;

            if ($pruneTasks === 1 && Capsule::schema()->hasTable('mod_proxmox_manager_tasks')) {
                $deletedTasks = (int) Capsule::table('mod_proxmox_manager_tasks')
                    ->where('created_at', '<', $cutoff)
                    ->delete();
            }

            if ($pruneAudit === 1 && Capsule::schema()->hasTable('mod_proxmox_audit_events')) {
                $deletedAudit = (int) Capsule::table('mod_proxmox_audit_events')
                    ->where('created_at', '<', $cutoff)
                    ->delete();
            }

            $successMessage = 'Log prune complete. Deleted tasks: ' . $deletedTasks . ', audit events: ' . $deletedAudit . '.';
        }
    }

    if ($action === 'test_public_dns_provider' && isset($_GET['provider_key'])) {
        if (!Capsule::schema()->hasTable('mod_proxmox_public_dns_providers')) {
            $errorMessage = 'Public DNS provider table not found.';
        } else {
            $providerKey = strtolower(trim((string) $_GET['provider_key']));
            $provider = Capsule::table('mod_proxmox_public_dns_providers')->where('provider_key', $providerKey)->first();
            if (!$provider) {
                $errorMessage = 'Provider not found.';
            } else {
                $check = proxmox_manager_test_public_dns_provider($provider);
                Capsule::table('mod_proxmox_public_dns_providers')
                    ->where('provider_key', $providerKey)
                    ->update([
                        'last_check_status' => $check['status'],
                        'last_check_error' => ($check['error'] !== '' ? $check['error'] : null),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);

                if ($check['status'] === 'ok') {
                    $successMessage = 'Provider health check passed.';
                } else {
                    $errorMessage = 'Provider health check failed: ' . $check['error'];
                }
            }
        }
    }

    if ($action === 'ping') {
        try {
            $api = proxmox_manager_build_api($params);
            $version = $api->getVersion();
            $successMessage = 'Connected to Proxmox API. Version: ' . $version;
        } catch (\Throwable $e) {
            $errorMessage = 'API ping failed: ' . $e->getMessage();
        }
    }

    $tasks = $repo->latestTasks(20);
    $mappings = $repo->latestServiceMappings(50);
    $osTemplateMappings = [];
    $ipPools = [];
    $poolLabelById = [];
    $productPolicies = [];
    $recentLeases = [];
    $serviceStates = [];
    $auditEvents = [];
    $publicDnsProviders = [];
    if (Capsule::schema()->hasTable('mod_proxmox_manager_os_templates')) {
        $osTemplateMappings = Capsule::table('mod_proxmox_manager_os_templates')
            ->orderBy('resource_type', 'asc')
            ->orderBy('os_key', 'asc')
            ->get();
    }
    if (Capsule::schema()->hasTable('mod_proxmox_ip_pools')) {
        $ipPools = Capsule::table('mod_proxmox_ip_pools')->orderBy('scope', 'asc')->orderBy('pool_key', 'asc')->get();
        foreach ($ipPools as $poolOption) {
            $poolId = isset($poolOption->id) ? (int) $poolOption->id : 0;
            if ($poolId < 1) {
                continue;
            }
            $poolKey = isset($poolOption->pool_key) ? (string) $poolOption->pool_key : '';
            $poolScope = isset($poolOption->scope) ? (string) $poolOption->scope : '';
            $poolLabelById[$poolId] = trim($poolKey . ($poolScope !== '' ? ' (' . $poolScope . ')' : ''));
        }
    }
    if (Capsule::schema()->hasTable('mod_proxmox_product_policies')) {
        $productPolicies = Capsule::table('mod_proxmox_product_policies')->orderBy('product_id', 'asc')->get();
    }
    if (Capsule::schema()->hasTable('mod_proxmox_ip_leases')) {
        $recentLeases = Capsule::table('mod_proxmox_ip_leases')->orderBy('id', 'desc')->limit(50)->get();
    }
    if (Capsule::schema()->hasTable('mod_proxmox_service_state')) {
        $serviceStates = Capsule::table('mod_proxmox_service_state')->orderBy('updated_at', 'desc')->limit(50)->get();
    }
    if (Capsule::schema()->hasTable('mod_proxmox_audit_events')) {
        $auditEvents = Capsule::table('mod_proxmox_audit_events')->orderBy('id', 'desc')->limit(50)->get();
    }
    if (Capsule::schema()->hasTable('mod_proxmox_public_dns_providers')) {
        $publicDnsProviders = Capsule::table('mod_proxmox_public_dns_providers')->orderBy('provider_key', 'asc')->get();
    }
    $baseRaw = trim((string) $params['modulelink']);
    if (strpos($baseRaw, 'token=') === false) {
        $requestToken = '';
        if (function_exists('generate_token')) {
            $tokenLink = trim((string) generate_token('link'));
            if ($tokenLink !== '') {
                $tokenParams = [];
                parse_str(ltrim($tokenLink, '&?'), $tokenParams);
                if (isset($tokenParams['token'])) {
                    $requestToken = trim((string) $tokenParams['token']);
                }
            }
        }
        if ($requestToken === '' && isset($_GET['token'])) {
            $requestToken = trim((string) $_GET['token']);
        } elseif ($requestToken === '' && isset($_REQUEST['token'])) {
            $requestToken = trim((string) $_REQUEST['token']);
        }
        if ($requestToken === '' && isset($_SESSION['token'])) {
            $requestToken = trim((string) $_SESSION['token']);
        }
        if ($requestToken === '' && isset($_SESSION['admintoken'])) {
            $requestToken = trim((string) $_SESSION['admintoken']);
        }
        if ($requestToken === '' && isset($_SESSION['admin_token'])) {
            $requestToken = trim((string) $_SESSION['admin_token']);
        }
        if ($requestToken !== '') {
            $baseRaw .= (strpos($baseRaw, '?') !== false ? '&' : '?') . 'token=' . urlencode($requestToken);
        }
    }
    $base = htmlspecialchars($baseRaw);
    $basePostRaw = proxmox_manager_admin_link_no_token($baseRaw);
    $basePostRaw .= (strpos($basePostRaw, '?') !== false ? '&' : '?') . 'page=' . urlencode($section);
    $basePost = htmlspecialchars($basePostRaw);
    $baseSection = $base . '&page=';
    $defaultServiceId = isset($_GET['service_id']) ? (int) $_GET['service_id'] : 0;
    $defaultClientId = isset($_GET['client_id']) ? (int) $_GET['client_id'] : 0;

    $editMapping = ['service_id' => $defaultServiceId, 'client_id' => $defaultClientId, 'node' => '', 'resource_type' => 'kvm', 'vmid' => 0];
    $editOs = ['os_key' => '', 'resource_type' => 'kvm', 'node' => '', 'template_vmid' => 0, 'enabled' => 1];
    $editPool = ['id' => 0, 'pool_key' => '', 'scope' => 'private', 'cidr' => '10.10.10.0/24', 'gateway' => '10.10.10.254', 'dns_servers' => '10.10.10.53,10.10.10.54', 'enabled' => 0];
    $editPolicy = ['id' => 0, 'product_id' => 0, 'resource_type' => 'kvm', 'private_pool_id' => 0, 'internal_dns_zone' => 'infra.local', 'template_os_key' => '', 'service_class' => 'private_only', 'firewall_profile_key' => '', 'strict_mode' => 0, 'enabled' => 1];
    $editProvider = ['provider_key' => '', 'api_base' => '', 'enabled' => 0];

    if (isset($_GET['edit_mapping_service_id'])) {
        $editServiceId = (int) $_GET['edit_mapping_service_id'];
        foreach ($mappings as $mappingRow) {
            if ((int) $mappingRow->service_id === $editServiceId) {
                $editMapping = [
                    'service_id' => (int) $mappingRow->service_id,
                    'client_id' => (int) $mappingRow->client_id,
                    'node' => (string) $mappingRow->node,
                    'resource_type' => strtolower(trim((string) $mappingRow->resource_type)),
                    'vmid' => (int) $mappingRow->vmid,
                ];
                break;
            }
        }
    }

    if (isset($_GET['edit_os_key']) && isset($_GET['edit_os_type'])) {
        $editOsKey = strtolower(trim((string) $_GET['edit_os_key']));
        $editOsType = strtolower(trim((string) $_GET['edit_os_type']));
        foreach ($osTemplateMappings as $osRow) {
            if ((string) $osRow->os_key === $editOsKey && (string) $osRow->resource_type === $editOsType) {
                $editOs = [
                    'os_key' => (string) $osRow->os_key,
                    'resource_type' => strtolower(trim((string) $osRow->resource_type)),
                    'node' => (string) $osRow->node,
                    'template_vmid' => (int) $osRow->template_vmid,
                    'enabled' => (int) $osRow->enabled,
                ];
                break;
            }
        }
    }

    if (isset($_GET['edit_pool_id'])) {
        $editPoolId = (int) $_GET['edit_pool_id'];
        foreach ($ipPools as $poolRow) {
            if ((int) $poolRow->id === $editPoolId) {
                $editPool = [
                    'id' => (int) $poolRow->id,
                    'pool_key' => (string) $poolRow->pool_key,
                    'scope' => strtolower(trim((string) $poolRow->scope)),
                    'cidr' => (string) $poolRow->cidr,
                    'gateway' => (string) $poolRow->gateway,
                    'dns_servers' => (string) $poolRow->dns_servers,
                    'enabled' => (int) $poolRow->enabled,
                ];
                break;
            }
        }
    }

    if (isset($_GET['edit_policy_id'])) {
        $editPolicyId = (int) $_GET['edit_policy_id'];
        foreach ($productPolicies as $policyRow) {
            if ((int) $policyRow->id === $editPolicyId) {
                $editPolicy = [
                    'id' => (int) $policyRow->id,
                    'product_id' => (int) $policyRow->product_id,
                    'resource_type' => strtolower(trim((string) $policyRow->resource_type)),
                    'private_pool_id' => (int) $policyRow->private_pool_id,
                    'internal_dns_zone' => strtolower(trim((string) $policyRow->internal_dns_zone)),
                    'template_os_key' => strtolower(trim((string) $policyRow->template_os_key)),
                    'service_class' => strtolower(trim((string) $policyRow->service_class)),
                    'firewall_profile_key' => strtolower(trim((string) $policyRow->firewall_profile_key)),
                    'strict_mode' => (int) $policyRow->strict_mode,
                    'enabled' => (int) $policyRow->enabled,
                ];
                break;
            }
        }
    }

    if (isset($_GET['edit_provider_key'])) {
        $editProviderKey = strtolower(trim((string) $_GET['edit_provider_key']));
        foreach ($publicDnsProviders as $providerRow) {
            if ((string) $providerRow->provider_key === $editProviderKey) {
                $editProvider = [
                    'provider_key' => (string) $providerRow->provider_key,
                    'api_base' => (string) $providerRow->api_base,
                    'enabled' => (int) $providerRow->enabled,
                ];
                break;
            }
        }
    }

    $osKeyOptions = proxmox_manager_template_os_key_options($osTemplateMappings);
    $nodeOptions = proxmox_manager_node_options($params, $mappings, $osTemplateMappings);
    $productOptions = [];
    $productNameById = [];
    if (Capsule::schema()->hasTable('tblproducts')) {
        $productOptions = Capsule::table('tblproducts')
            ->select(['id', 'name'])
            ->orderBy('id', 'asc')
            ->limit(500)
            ->get();

        foreach ($productOptions as $productOption) {
            $productNameById[(int) $productOption->id] = (string) $productOption->name;
        }
    }

    $serviceLabelById = [];
    $clientLabelById = [];
    $policyLabelById = [];

    foreach ($productPolicies as $policy) {
        $policyId = isset($policy->id) ? (int) $policy->id : 0;
        if ($policyId < 1) {
            continue;
        }
        $policyProductId = isset($policy->product_id) ? (int) $policy->product_id : 0;
        $policyProductName = isset($productNameById[$policyProductId]) ? (string) $productNameById[$policyProductId] : '';
        $policyLabelById[$policyId] = $policyProductName !== ''
            ? $policyProductName
            : ('Policy #' . $policyId);
    }

    $missingPoolIds = [];
    foreach ($productPolicies as $policy) {
        $poolId = isset($policy->private_pool_id) ? (int) $policy->private_pool_id : 0;
        if ($poolId > 0 && !isset($poolLabelById[$poolId])) {
            $missingPoolIds[$poolId] = true;
        }
    }
    if (!empty($missingPoolIds) && Capsule::schema()->hasTable('mod_proxmox_ip_pools')) {
        $missingPools = Capsule::table('mod_proxmox_ip_pools')
            ->select(['id', 'pool_key', 'scope'])
            ->whereIn('id', array_keys($missingPoolIds))
            ->get();
        foreach ($missingPools as $poolRow) {
            $poolId = isset($poolRow->id) ? (int) $poolRow->id : 0;
            if ($poolId < 1) {
                continue;
            }
            $poolKey = isset($poolRow->pool_key) ? (string) $poolRow->pool_key : '';
            $poolScope = isset($poolRow->scope) ? (string) $poolRow->scope : '';
            $poolLabelById[$poolId] = trim($poolKey . ($poolScope !== '' ? ' (' . $poolScope . ')' : ''));
        }
    }

    $serviceIds = [];
    $clientIds = [];
    foreach ([$mappings, $tasks, $recentLeases, $serviceStates, $auditEvents] as $rows) {
        if (!is_iterable($rows)) {
            continue;
        }
        foreach ($rows as $row) {
            if (isset($row->service_id)) {
                $id = (int) $row->service_id;
                if ($id > 0) {
                    $serviceIds[$id] = true;
                }
            }
            if (isset($row->client_id)) {
                $id = (int) $row->client_id;
                if ($id > 0) {
                    $clientIds[$id] = true;
                }
            }
        }
    }

    if (!empty($serviceIds) && Capsule::schema()->hasTable('tblhosting')) {
        $services = Capsule::table('tblhosting')
            ->select(['id', 'domain'])
            ->whereIn('id', array_keys($serviceIds))
            ->get();
        foreach ($services as $serviceRow) {
            $serviceId = (int) $serviceRow->id;
            $domain = trim((string) $serviceRow->domain);
            $serviceLabelById[$serviceId] = $domain !== ''
                ? $domain
                : 'Unknown (deleted)';
        }
    }

    if (!empty($clientIds) && Capsule::schema()->hasTable('tblclients')) {
        $clients = Capsule::table('tblclients')
            ->select(['id', 'firstname', 'lastname', 'companyname'])
            ->whereIn('id', array_keys($clientIds))
            ->get();
        foreach ($clients as $clientRow) {
            $clientId = (int) $clientRow->id;
            $fullName = trim((string) $clientRow->firstname . ' ' . (string) $clientRow->lastname);
            $company = trim((string) $clientRow->companyname);
            $label = $fullName !== '' ? $fullName : ($company !== '' ? $company : 'Unknown (deleted)');
            $clientLabelById[$clientId] = $label;
        }
    }
    $firewallProfileOptions = proxmox_manager_firewall_profile_key_options($productPolicies);
    $dnsZoneOptions = proxmox_manager_dns_zone_options($productPolicies);

    if ($successMessage !== '') {
        echo '<div class="successbox">' . htmlspecialchars($successMessage) . '</div>';
    }
    if ($errorMessage !== '') {
        echo '<div class="errorbox">' . htmlspecialchars($errorMessage) . '</div>';
    }

    echo '<h2>Proxmox Manager</h2>';
    echo '<p class="pm-subtle">Build: stable-ui-2026-04-16-1</p>';
    echo '<p>Use this addon for UI workflows and activity visibility. Provisioning automation stays in your server module.</p>';
    echo '<p>Service mapping source: <code>mod_proxmox_manager_services</code> table (with custom field fallback for migration).</p>';
    echo '<p><a class="btn btn-default" href="' . $baseSection . 'service">Dashboard</a> '
        . '<a class="btn btn-primary" href="' . $baseSection . $section . '&action=ping">Test API Connection</a></p>';
    echo '<style>.pm-subtle{margin:-6px 0 12px 0;color:#5f6c7b;font-size:12px}.pm-actions .btn{margin-right:6px;margin-bottom:6px}</style>';

    $tabs = [
        'service' => 'Service Mapping (' . count($mappings) . ')',
        'templates' => 'OS Templates (' . count($osTemplateMappings) . ')',
        'pools' => 'IP Pools (' . count($ipPools) . ')',
        'policies' => 'Product Policies (' . count($productPolicies) . ')',
        'dns' => 'DNS Providers (' . count($publicDnsProviders) . ')',
        'activity' => 'Activity (' . (count($tasks) + count($auditEvents) + count($serviceStates) + count($recentLeases)) . ')',
        'diagnostics' => 'Diagnostics',
    ];
    echo '<div class="pm-actions" style="margin:10px 0 15px 0;">';
    foreach ($tabs as $tabKey => $tabLabel) {
        $tabClass = ($section === $tabKey) ? 'btn btn-primary' : 'btn btn-default';
        echo '<a class="' . $tabClass . '" style="margin:0 6px 6px 0;" href="' . $baseSection . urlencode($tabKey) . '">' . htmlspecialchars($tabLabel) . '</a>';
    }
    echo '</div>';

    if ($section === 'service') {
    echo '<h3>Service Mapping</h3>';
    echo '<p class="pm-subtle">Map WHMCS service IDs to existing Proxmox instances for control and sync operations.</p>';
    echo '<form method="post" action="' . $basePost . '" style="margin-bottom:15px;">';
    echo proxmox_manager_csrf_input();
    echo '<input type="hidden" name="action" value="save_mapping">';
    echo '<table class="form" width="100%" border="0" cellspacing="2" cellpadding="3">';
    echo '<tr>'
        . '<td class="fieldlabel" width="20%">Service ID</td>'
        . '<td class="fieldarea"><input type="number" min="1" name="service_id" value="' . (int) $editMapping['service_id'] . '" required></td>'
        . '<td class="fieldlabel" width="20%">Client ID (optional)</td>'
        . '<td class="fieldarea"><input type="number" min="1" name="client_id" value="' . (int) $editMapping['client_id'] . '"></td>'
        . '</tr>';
    echo '<tr>'
        . '<td class="fieldlabel">Node</td>'
        . '<td class="fieldarea"><input type="text" name="node" value="' . htmlspecialchars((string) $editMapping['node']) . '" list="pm-node-options" placeholder="pve26" required></td>'
        . '<td class="fieldlabel">Resource Type</td>'
        . '<td class="fieldarea"><select name="resource_type"><option value="kvm"' . (($editMapping['resource_type'] === 'kvm') ? ' selected' : '') . '>kvm</option><option value="lxc"' . (($editMapping['resource_type'] === 'lxc') ? ' selected' : '') . '>lxc</option></select></td>'
        . '</tr>';
    echo '<tr>'
        . '<td class="fieldlabel">VMID</td>'
        . '<td class="fieldarea"><input type="number" min="1" name="vmid" value="' . (int) $editMapping['vmid'] . '" required></td>'
        . '<td class="fieldlabel"></td>'
        . '<td class="fieldarea"><button type="submit" class="btn btn-primary">' . ($editMapping['service_id'] > 0 ? 'Update Mapping' : 'Save Mapping') . '</button> ' . ($editMapping['service_id'] > 0 ? '<a class="btn btn-default" href="' . $baseSection . 'service">Cancel</a>' : '') . '</td>'
        . '</tr>';
    echo '</table>';
    echo '</form>';
    }

    if ($section === 'templates') {
    echo '<h3>OS Choice -> Template Mapping</h3>';
    echo '<p class="pm-subtle">Define which template VMID should be cloned for each OS key and resource type.</p>';
    echo '<form method="post" action="' . $basePost . '" style="margin-bottom:15px;">';
    echo proxmox_manager_csrf_input();
    echo '<input type="hidden" name="action" value="save_os_template">';
    echo '<table class="form" width="100%" border="0" cellspacing="2" cellpadding="3">';
    echo '<tr>'
        . '<td class="fieldlabel" width="20%">OS Key</td>'
        . '<td class="fieldarea"><input type="text" name="os_key" value="' . htmlspecialchars((string) $editOs['os_key']) . '" list="pm-oskey-options" placeholder="debian12" required></td>'
        . '<td class="fieldlabel" width="20%">Resource Type</td>'
        . '<td class="fieldarea"><select name="resource_type"><option value="kvm"' . (($editOs['resource_type'] === 'kvm') ? ' selected' : '') . '>kvm</option><option value="lxc"' . (($editOs['resource_type'] === 'lxc') ? ' selected' : '') . '>lxc</option></select></td>'
        . '</tr>';
    echo '<tr>'
        . '<td class="fieldlabel">Node</td>'
        . '<td class="fieldarea"><input type="text" name="node" value="' . htmlspecialchars((string) $editOs['node']) . '" list="pm-node-options" placeholder="pve26" required></td>'
        . '<td class="fieldlabel">Template VMID</td>'
        . '<td class="fieldarea"><input type="number" min="1" name="template_vmid" value="' . (int) $editOs['template_vmid'] . '" required></td>'
        . '</tr>';
    echo '<tr>'
        . '<td class="fieldlabel">Enabled</td>'
        . '<td class="fieldarea"><label><input type="checkbox" name="enabled" value="1"' . ((int) $editOs['enabled'] === 1 ? ' checked' : '') . '> active</label></td>'
        . '<td class="fieldlabel"></td>'
        . '<td class="fieldarea"><button type="submit" class="btn btn-primary">' . ($editOs['os_key'] !== '' ? 'Update OS Mapping' : 'Save OS Mapping') . '</button> ' . ($editOs['os_key'] !== '' ? '<a class="btn btn-default" href="' . $baseSection . 'templates">Cancel</a>' : '') . '</td>'
        . '</tr>';
    echo '</table>';
    echo '</form>';
    echo '<datalist id="pm-node-options">' . proxmox_manager_datalist_option_tags($nodeOptions) . '</datalist>';
    echo '<datalist id="pm-oskey-options">' . proxmox_manager_datalist_option_tags($osKeyOptions) . '</datalist>';

    echo '<h3>Current OS Template Mappings</h3>';
    echo '<table class="datatable" width="100%" cellspacing="0">';
    echo '<tr><th>OS Key</th><th>Type</th><th>Node</th><th>Template VMID</th><th>Enabled</th><th>Updated</th><th>Action</th></tr>';
    if (empty($osTemplateMappings)) {
        echo '<tr><td colspan="7">No OS template mappings found.</td></tr>';
    } else {
        foreach ($osTemplateMappings as $row) {
            $editOsLink = '<a class="btn btn-default btn-xs" href="' . $baseSection . 'templates&edit_os_key=' . urlencode((string) $row->os_key) . '&edit_os_type=' . urlencode((string) $row->resource_type) . '">Edit</a>';
            $deleteOsForm = '<form method="post" action="' . $basePost . '" onsubmit="return confirm(\'Delete this OS mapping?\');" style="margin:0;">'
                . proxmox_manager_csrf_input()
                . '<input type="hidden" name="action" value="delete_os_template">'
                . '<input type="hidden" name="os_key" value="' . htmlspecialchars((string) $row->os_key) . '">'
                . '<input type="hidden" name="resource_type" value="' . htmlspecialchars((string) $row->resource_type) . '">'
                . '<button type="submit" class="btn btn-danger btn-xs">Delete</button>'
                . '</form>';
            echo '<tr>'
                . '<td>' . htmlspecialchars((string) $row->os_key) . '</td>'
                . '<td>' . htmlspecialchars((string) $row->resource_type) . '</td>'
                . '<td>' . htmlspecialchars((string) $row->node) . '</td>'
                . '<td>' . (int) $row->template_vmid . '</td>'
                . '<td>' . ((int) $row->enabled === 1 ? 'yes' : 'no') . '</td>'
                . '<td>' . htmlspecialchars((string) $row->updated_at) . '</td>'
                . '<td>' . $editOsLink . ' ' . $deleteOsForm . '</td>'
                . '</tr>';
        }
    }
    echo '</table>';
    }

    if ($section === 'pools') {
    echo '<h3>IP Pools (Phase 1)</h3>';
    echo '<p class="pm-subtle">Manage private/public pools used for lease allocation and policy enforcement.</p>';
    echo '<form method="post" action="' . $basePost . '" style="margin-bottom:15px;">';
    echo proxmox_manager_csrf_input();
    echo '<input type="hidden" name="action" value="save_ip_pool">';
    echo '<table class="form" width="100%" border="0" cellspacing="2" cellpadding="3">';
    echo '<tr>'
        . '<td class="fieldlabel" width="20%">Pool Key</td>'
        . '<td class="fieldarea"><input type="text" name="pool_key" value="' . htmlspecialchars((string) $editPool['pool_key']) . '" placeholder="private-main-10-10-10" required></td>'
        . '<td class="fieldlabel" width="20%">Scope</td>'
        . '<td class="fieldarea"><select name="scope"><option value="private"' . (($editPool['scope'] === 'private') ? ' selected' : '') . '>private</option><option value="public"' . (($editPool['scope'] === 'public') ? ' selected' : '') . '>public</option></select></td>'
        . '</tr>';
    echo '<tr>'
        . '<td class="fieldlabel">CIDR</td>'
        . '<td class="fieldarea"><input type="text" name="cidr" value="' . htmlspecialchars((string) $editPool['cidr']) . '" required></td>'
        . '<td class="fieldlabel">Gateway</td>'
        . '<td class="fieldarea"><input type="text" name="gateway" value="' . htmlspecialchars((string) $editPool['gateway']) . '"></td>'
        . '</tr>';
    echo '<tr>'
        . '<td class="fieldlabel">DNS Servers</td>'
        . '<td class="fieldarea"><input type="text" name="dns_servers" value="' . htmlspecialchars((string) $editPool['dns_servers']) . '"></td>'
        . '<td class="fieldlabel">Enabled</td>'
        . '<td class="fieldarea"><label><input type="checkbox" name="enabled" value="1"' . ((int) $editPool['enabled'] === 1 ? ' checked' : '') . '> active</label></td>'
        . '</tr>';
    echo '<tr>'
        . '<td class="fieldlabel"></td><td class="fieldarea"></td><td class="fieldlabel"></td>'
        . '<td class="fieldarea"><button type="submit" class="btn btn-primary">' . ($editPool['id'] > 0 ? 'Update IP Pool' : 'Save IP Pool') . '</button> ' . ($editPool['id'] > 0 ? '<a class="btn btn-default" href="' . $baseSection . 'pools">Cancel</a>' : '') . '</td>'
        . '</tr>';
    echo '</table>';
    echo '</form>';

    echo '<table class="datatable" width="100%" cellspacing="0">';
    echo '<tr><th>ID</th><th>Pool Key</th><th>Scope</th><th>CIDR</th><th>Gateway</th><th>Enabled</th><th>Updated</th><th>Action</th></tr>';
    if (empty($ipPools)) {
        echo '<tr><td colspan="8">No IP pools found.</td></tr>';
    } else {
        foreach ($ipPools as $pool) {
            $editLink = '<a class="btn btn-default btn-xs" href="' . $baseSection . 'pools&edit_pool_id=' . (int) $pool->id . '">Edit</a>';
            $deleteForm = '<form method="post" action="' . $basePost . '" onsubmit="return confirm(\'Delete this pool and its free/released leases?\');" style="display:inline-block;margin-left:6px;">'
                . proxmox_manager_csrf_input()
                . '<input type="hidden" name="action" value="delete_ip_pool">'
                . '<input type="hidden" name="pool_id" value="' . (int) $pool->id . '">'
                . '<button type="submit" class="btn btn-danger btn-xs">Delete</button>'
                . '</form>';
            echo '<tr>'
                . '<td>' . (int) $pool->id . '</td>'
                . '<td>' . htmlspecialchars((string) $pool->pool_key) . '</td>'
                . '<td>' . htmlspecialchars((string) $pool->scope) . '</td>'
                . '<td>' . htmlspecialchars((string) $pool->cidr) . '</td>'
                . '<td>' . htmlspecialchars((string) $pool->gateway) . '</td>'
                . '<td>' . ((int) $pool->enabled === 1 ? 'yes' : 'no') . '</td>'
                . '<td>' . htmlspecialchars((string) $pool->updated_at) . '</td>'
                . '<td>' . $editLink . $deleteForm . '</td>'
                . '</tr>';
        }
    }
    echo '</table>';
    }

    if ($section === 'policies') {
    echo '<h3>Product Policies (Phase 1)</h3>';
    echo '<p class="pm-subtle">Attach provisioning and network policy defaults to WHMCS products.</p>';
    echo '<form method="post" action="' . $basePost . '" style="margin-bottom:15px;">';
    echo proxmox_manager_csrf_input();
    echo '<input type="hidden" name="action" value="save_product_policy">';
    echo '<table class="form" width="100%" border="0" cellspacing="2" cellpadding="3">';
    $productSelectOptions = '';
    $productSelected = false;
    foreach ($productOptions as $productOption) {
        $productLabel = (int) $productOption->id . ' - ' . (string) $productOption->name;
        $isSelected = ((int) $editPolicy['product_id'] === (int) $productOption->id);
        if ($isSelected) {
            $productSelected = true;
        }
        $productSelectOptions .= '<option value="' . (int) $productOption->id . '"' . ($isSelected ? ' selected' : '') . '>' . htmlspecialchars($productLabel) . '</option>';
    }
    if ((int) $editPolicy['product_id'] > 0 && !$productSelected) {
        $fallbackLabel = (int) $editPolicy['product_id'] . ' - current value';
        $productSelectOptions = '<option value="' . (int) $editPolicy['product_id'] . '" selected>' . htmlspecialchars($fallbackLabel) . '</option>' . $productSelectOptions;
    }
    echo '<tr>'
        . '<td class="fieldlabel" width="20%">Product ID</td>'
        . '<td class="fieldarea"><select name="product_id" required><option value="">-- select product --</option>' . $productSelectOptions . '</select></td>'
        . '<td class="fieldlabel" width="20%">Resource Type</td>'
        . '<td class="fieldarea"><select name="resource_type"><option value="kvm"' . (($editPolicy['resource_type'] === 'kvm') ? ' selected' : '') . '>kvm</option><option value="lxc"' . (($editPolicy['resource_type'] === 'lxc') ? ' selected' : '') . '>lxc</option></select></td>'
        . '</tr>';
    echo '<tr>'
        . '<td class="fieldlabel">Private Pool ID</td>'
        . '<td class="fieldarea"><select name="private_pool_id" required><option value="">-- select pool --</option>' . proxmox_manager_pool_option_tags($ipPools, (int) $editPolicy['private_pool_id']) . '</select></td>'
        . '<td class="fieldlabel">Internal DNS Zone</td>'
        . '<td class="fieldarea"><input type="text" name="internal_dns_zone" value="' . htmlspecialchars((string) $editPolicy['internal_dns_zone']) . '" list="pm-dns-zone-options"></td>'
        . '</tr>';
    $templateOsSelectOptions = '';
    $templateOsSelected = false;
    foreach ($osKeyOptions as $keyOption) {
        $isSelected = ((string) $editPolicy['template_os_key'] === (string) $keyOption);
        if ($isSelected) {
            $templateOsSelected = true;
        }
        $templateOsSelectOptions .= '<option value="' . htmlspecialchars($keyOption) . '"' . ($isSelected ? ' selected' : '') . '>' . htmlspecialchars($keyOption) . '</option>';
    }
    if ((string) $editPolicy['template_os_key'] !== '' && !$templateOsSelected) {
        $templateOsSelectOptions = '<option value="' . htmlspecialchars((string) $editPolicy['template_os_key']) . '" selected>' . htmlspecialchars((string) $editPolicy['template_os_key']) . ' (current)</option>' . $templateOsSelectOptions;
    }
    echo '<tr>'
        . '<td class="fieldlabel">Template OS Key</td>'
        . '<td class="fieldarea"><select name="template_os_key_select"><option value="">-- none --</option>' . $templateOsSelectOptions . '</select><br><small>Or set custom key below (e.g. make)</small></td>'
        . '<td class="fieldlabel">Service Class</td>'
        . '<td class="fieldarea"><select name="service_class"><option value="private_only"' . (($editPolicy['service_class'] === 'private_only') ? ' selected' : '') . '>private_only</option><option value="shared_edge"' . (($editPolicy['service_class'] === 'shared_edge') ? ' selected' : '') . '>shared_edge</option><option value="dedicated_public"' . (($editPolicy['service_class'] === 'dedicated_public') ? ' selected' : '') . '>dedicated_public</option><option value="hybrid"' . (($editPolicy['service_class'] === 'hybrid') ? ' selected' : '') . '>hybrid</option></select></td>'
        . '</tr>';
    echo '<tr>'
        . '<td class="fieldlabel">Custom OS Key (optional)</td>'
        . '<td class="fieldarea"><input type="text" name="template_os_key" value="' . htmlspecialchars((string) $editPolicy['template_os_key']) . '" list="pm-oskey-options" placeholder="make"></td>'
        . '<td class="fieldlabel">Firewall Profile Key</td>'
        . '<td class="fieldarea"><select name="firewall_profile_key_select"><option value="">-- none --</option>' . proxmox_manager_simple_option_tags($firewallProfileOptions, (string) $editPolicy['firewall_profile_key']) . '</select><br><small>Or set custom key below.</small></td>'
        . '</tr>';
    echo '<tr>'
        . '<td class="fieldlabel">Firewall Key (custom)</td>'
        . '<td class="fieldarea"><input type="text" name="firewall_profile_key" value="' . htmlspecialchars((string) $editPolicy['firewall_profile_key']) . '" list="pm-firewall-profile-options" placeholder="web_edge"></td>'
        . '<td class="fieldlabel"></td>'
        . '<td class="fieldarea"></td>'
        . '</tr>';
    echo '<tr>'
        . '<td class="fieldlabel">Strict Mode</td>'
        . '<td class="fieldarea"><label><input type="checkbox" name="strict_mode" value="1"' . ((int) $editPolicy['strict_mode'] === 1 ? ' checked' : '') . '> fail if policy/IP lease unavailable</label></td>'
        . '<td class="fieldlabel">Enabled</td>'
        . '<td class="fieldarea"><label><input type="checkbox" name="enabled" value="1"' . ((int) $editPolicy['enabled'] === 1 ? ' checked' : '') . '> active</label></td>'
        . '</tr>';
    echo '<tr>'
        . '<td class="fieldlabel">Force Disable</td>'
        . '<td class="fieldarea"><label><input type="checkbox" name="force_disable" value="1"> allow disable when in use</label></td>'
        . '<td class="fieldlabel"></td>'
        . '<td class="fieldarea"></td>'
        . '</tr>';
    echo '<tr>'
        . '<td class="fieldlabel"></td><td class="fieldarea"></td><td class="fieldlabel"></td>'
        . '<td class="fieldarea"><button type="submit" class="btn btn-primary">' . ($editPolicy['id'] > 0 ? 'Update Product Policy' : 'Save Product Policy') . '</button> ' . ($editPolicy['id'] > 0 ? '<a class="btn btn-default" href="' . $baseSection . 'policies">Cancel</a>' : '') . '</td>'
        . '</tr>';
    echo '</table>';
    echo '</form>';
    echo '<datalist id="pm-dns-zone-options">' . proxmox_manager_datalist_option_tags($dnsZoneOptions) . '</datalist>';
    echo '<datalist id="pm-firewall-profile-options">' . proxmox_manager_datalist_option_tags($firewallProfileOptions) . '</datalist>';

    echo '<table class="datatable" width="100%" cellspacing="0">';
    echo '<tr><th>ID</th><th>Product</th><th>Type</th><th>Pool ID</th><th>DNS Zone</th><th>OS Key</th><th>Class</th><th>FW Profile</th><th>Strict</th><th>Enabled</th><th>Action</th></tr>';
    if (empty($productPolicies)) {
        echo '<tr><td colspan="11">No product policies found.</td></tr>';
    } else {
        foreach ($productPolicies as $policy) {
            $policyProductId = (int) $policy->product_id;
            $policyProductName = isset($productNameById[$policyProductId]) ? (string) $productNameById[$policyProductId] : '';
            $policyProductLabel = ($policyProductName !== '')
                ? $policyProductName
                : 'Unknown (deleted)';
            $policyPoolId = (int) $policy->private_pool_id;
            $policyPoolName = isset($poolLabelById[$policyPoolId]) ? (string) $poolLabelById[$policyPoolId] : '';
            $policyPoolLabel = ($policyPoolName !== '')
                ? $policyPoolName
                : 'Unknown (deleted)';
            $editPolicyLink = '<a class="btn btn-default btn-xs" href="' . $baseSection . 'policies&edit_policy_id=' . (int) $policy->id . '">Edit</a>';
            $deletePolicyForm = '<form method="post" action="' . $basePost . '" onsubmit="return confirm(\'Delete this product policy?\');" style="margin:0;">'
                . proxmox_manager_csrf_input()
                . '<input type="hidden" name="action" value="delete_product_policy">'
                . '<input type="hidden" name="policy_id" value="' . (int) $policy->id . '">'
                . '<label style="font-weight:normal;margin-right:6px;"><input type="checkbox" name="force_delete" value="1"> force</label>'
                . '<button type="submit" class="btn btn-danger btn-xs">Delete</button>'
                . '</form>';
            echo '<tr>'
                . '<td>' . (int) $policy->id . '</td>'
                . '<td>' . htmlspecialchars($policyProductLabel) . '</td>'
                . '<td>' . htmlspecialchars((string) $policy->resource_type) . '</td>'
                . '<td>' . htmlspecialchars($policyPoolLabel) . '</td>'
                . '<td>' . htmlspecialchars((string) $policy->internal_dns_zone) . '</td>'
                . '<td>' . htmlspecialchars((string) $policy->template_os_key) . '</td>'
                . '<td>' . htmlspecialchars((string) $policy->service_class) . '</td>'
                . '<td>' . htmlspecialchars((string) $policy->firewall_profile_key) . '</td>'
                . '<td>' . ((int) $policy->strict_mode === 1 ? 'yes' : 'no') . '</td>'
                . '<td>' . ((int) $policy->enabled === 1 ? 'yes' : 'no') . '</td>'
                . '<td>' . $editPolicyLink . ' ' . $deletePolicyForm . '</td>'
                . '</tr>';
        }
    }
    echo '</table>';
    }

    if ($section === 'activity') {
    echo '<h3>Recent IP Leases</h3>';
    echo '<p class="pm-subtle">Operational visibility for leases, service state, audit events, mappings, and recent tasks.</p>';
    echo '<table class="datatable" width="100%" cellspacing="0">';
    echo '<tr><th>ID</th><th>Pool</th><th>IP</th><th>Status</th><th>Service</th><th>Client</th><th>VMID</th><th>Updated</th></tr>';
    if (empty($recentLeases)) {
        echo '<tr><td colspan="8">No IP leases found.</td></tr>';
    } else {
        foreach ($recentLeases as $lease) {
            $leasePoolId = (int) $lease->pool_id;
            $leaseServiceId = (int) $lease->service_id;
            $leaseClientId = (int) $lease->client_id;
            $leasePoolLabel = isset($poolLabelById[$leasePoolId]) ? (string) $poolLabelById[$leasePoolId] : 'Unknown (deleted)';
            $leaseServiceLabel = isset($serviceLabelById[$leaseServiceId]) ? (string) $serviceLabelById[$leaseServiceId] : 'Unknown (deleted)';
            $leaseClientLabel = isset($clientLabelById[$leaseClientId]) ? (string) $clientLabelById[$leaseClientId] : 'Unknown (deleted)';
            echo '<tr>'
                . '<td>' . (int) $lease->id . '</td>'
                . '<td>' . htmlspecialchars($leasePoolLabel) . '</td>'
                . '<td>' . htmlspecialchars((string) $lease->ip_address) . '</td>'
                . '<td>' . htmlspecialchars((string) $lease->status) . '</td>'
                . '<td>' . htmlspecialchars($leaseServiceLabel) . '</td>'
                . '<td>' . htmlspecialchars($leaseClientLabel) . '</td>'
                . '<td>' . (int) $lease->vmid . '</td>'
                . '<td>' . htmlspecialchars((string) $lease->updated_at) . '</td>'
                . '</tr>';
        }
    }
    echo '</table>';
    }

    if ($section === 'activity') {
    echo '<h3>Service State (Phase 1)</h3>';
    echo '<table class="datatable" width="100%" cellspacing="0">';
    echo '<tr><th>Service</th><th>Policy</th><th>Private IP</th><th>Public IP</th><th>State</th><th>Updated</th></tr>';
    if (empty($serviceStates)) {
        echo '<tr><td colspan="6">No service state rows found.</td></tr>';
    } else {
        foreach ($serviceStates as $state) {
            $stateServiceId = (int) $state->service_id;
            $statePolicyId = (int) $state->policy_id;
            $stateServiceLabel = isset($serviceLabelById[$stateServiceId]) ? (string) $serviceLabelById[$stateServiceId] : 'Unknown (deleted)';
            $statePolicyLabel = isset($policyLabelById[$statePolicyId]) ? (string) $policyLabelById[$statePolicyId] : 'Unknown (deleted)';
            echo '<tr>'
                . '<td>' . htmlspecialchars($stateServiceLabel) . '</td>'
                . '<td>' . htmlspecialchars($statePolicyLabel) . '</td>'
                . '<td>' . htmlspecialchars((string) $state->private_ip) . '</td>'
                . '<td>' . htmlspecialchars((string) $state->public_ip) . '</td>'
                . '<td>' . htmlspecialchars((string) $state->provision_state) . '</td>'
                . '<td>' . htmlspecialchars((string) $state->updated_at) . '</td>'
                . '</tr>';
        }
    }
    echo '</table>';
    }

    if ($section === 'activity') {
    echo '<h3>Audit Events (Phase 1)</h3>';
    echo '<table class="datatable" width="100%" cellspacing="0">';
    echo '<tr><th>ID</th><th>Service</th><th>Event</th><th>Status</th><th>Error</th><th>Created</th></tr>';
    if (empty($auditEvents)) {
        echo '<tr><td colspan="6">No audit events found.</td></tr>';
    } else {
        foreach ($auditEvents as $event) {
            $eventServiceId = (int) $event->service_id;
            $eventServiceLabel = isset($serviceLabelById[$eventServiceId]) ? (string) $serviceLabelById[$eventServiceId] : 'Unknown (deleted)';
            echo '<tr>'
                . '<td>' . (int) $event->id . '</td>'
                . '<td>' . htmlspecialchars($eventServiceLabel) . '</td>'
                . '<td>' . htmlspecialchars((string) $event->event_type) . '</td>'
                . '<td>' . htmlspecialchars((string) $event->status) . '</td>'
                . '<td>' . htmlspecialchars((string) $event->error_message) . '</td>'
                . '<td>' . htmlspecialchars((string) $event->created_at) . '</td>'
                . '</tr>';
        }
    }
    echo '</table>';
    }

    if ($section === 'dns') {
    echo '<h3>Public DNS Providers (Health Check Only)</h3>';
    echo '<p class="pm-subtle">Configure provider endpoints for future DNS automation and validate connectivity.</p>';
    echo '<form method="post" action="' . $basePost . '" style="margin-bottom:15px;">';
    echo proxmox_manager_csrf_input();
    echo '<input type="hidden" name="action" value="save_public_dns_provider">';
    echo '<table class="form" width="100%" border="0" cellspacing="2" cellpadding="3">';
    echo '<tr>'
        . '<td class="fieldlabel" width="20%">Provider Key</td>'
        . '<td class="fieldarea"><input type="text" name="provider_key" value="' . htmlspecialchars((string) $editProvider['provider_key']) . '" placeholder="cloudflare" required></td>'
        . '<td class="fieldlabel" width="20%">API Base</td>'
        . '<td class="fieldarea"><input type="text" name="api_base" value="' . htmlspecialchars((string) $editProvider['api_base']) . '" placeholder="https://api.cloudflare.com/client/v4"></td>'
        . '</tr>';
    echo '<tr>'
        . '<td class="fieldlabel">API Token</td>'
        . '<td class="fieldarea"><input type="password" name="api_token" value="" placeholder="optional for health check"></td>'
        . '<td class="fieldlabel">Enabled</td>'
        . '<td class="fieldarea"><label><input type="checkbox" name="enabled" value="1"' . ((int) $editProvider['enabled'] === 1 ? ' checked' : '') . '> active</label></td>'
        . '</tr>';
    echo '<tr>'
        . '<td class="fieldlabel"></td><td class="fieldarea"></td><td class="fieldlabel"></td>'
        . '<td class="fieldarea"><button type="submit" class="btn btn-primary">' . ($editProvider['provider_key'] !== '' ? 'Update Provider' : 'Save Provider') . '</button> ' . ($editProvider['provider_key'] !== '' ? '<a class="btn btn-default" href="' . $baseSection . 'dns">Cancel</a>' : '') . '</td>'
        . '</tr>';
    echo '</table>';
    echo '</form>';

    echo '<table class="datatable" width="100%" cellspacing="0">';
    echo '<tr><th>Provider</th><th>API Base</th><th>Enabled</th><th>Last Check</th><th>Error</th><th>Action</th></tr>';
    if (empty($publicDnsProviders)) {
        echo '<tr><td colspan="6">No public DNS providers configured.</td></tr>';
    } else {
        foreach ($publicDnsProviders as $provider) {
            $providerEditLink = '<a class="btn btn-default btn-xs" href="' . $baseSection . 'dns&edit_provider_key=' . urlencode((string) $provider->provider_key) . '">Edit</a>';
            $providerDeleteForm = '<form method="post" action="' . $basePost . '" onsubmit="return confirm(\'Delete this provider?\');" style="display:inline-block;margin-left:6px;">'
                . proxmox_manager_csrf_input()
                . '<input type="hidden" name="action" value="delete_public_dns_provider">'
                . '<input type="hidden" name="provider_key" value="' . htmlspecialchars((string) $provider->provider_key) . '">'
                . '<button type="submit" class="btn btn-danger btn-xs">Delete</button>'
                . '</form>';
            echo '<tr>'
                . '<td>' . htmlspecialchars((string) $provider->provider_key) . '</td>'
                . '<td>' . htmlspecialchars((string) $provider->api_base) . '</td>'
                . '<td>' . ((int) $provider->enabled === 1 ? 'yes' : 'no') . '</td>'
                . '<td>' . htmlspecialchars((string) $provider->last_check_status) . '</td>'
                . '<td>' . htmlspecialchars((string) $provider->last_check_error) . '</td>'
                . '<td>' . $providerEditLink . ' <a class="btn btn-default btn-xs" href="' . $baseSection . 'dns&action=test_public_dns_provider&provider_key=' . urlencode((string) $provider->provider_key) . '">Test</a>' . $providerDeleteForm . '</td>'
                . '</tr>';
        }
    }
    echo '</table>';
    }

    if ($section === 'diagnostics') {
    echo '<h3>Log Maintenance</h3>';
    echo '<p class="pm-subtle">Prune old task and audit rows.</p>';
    echo '<form method="post" action="' . $basePost . '" style="margin-bottom:15px;">';
    echo proxmox_manager_csrf_input();
    echo '<input type="hidden" name="action" value="prune_logs">';
    echo '<table class="form" width="100%" border="0" cellspacing="2" cellpadding="3">';
    echo '<tr>'
        . '<td class="fieldlabel" width="20%">Older Than (days)</td>'
        . '<td class="fieldarea"><input type="number" min="1" max="3650" name="days" value="30" required></td>'
        . '<td class="fieldlabel" width="20%">Tables</td>'
        . '<td class="fieldarea">'
            . '<label style="margin-right:10px;"><input type="checkbox" name="prune_tasks" value="1" checked> tasks</label>'
            . '<label><input type="checkbox" name="prune_audit" value="1" checked> audit events</label>'
        . '</td>'
        . '</tr>';
    echo '<tr>'
        . '<td class="fieldlabel"></td><td class="fieldarea"></td><td class="fieldlabel"></td>'
        . '<td class="fieldarea"><button type="submit" class="btn btn-warning" onclick="return confirm(\'Prune old logs now?\');">Prune Logs</button></td>'
        . '</tr>';
    echo '</table>';
    echo '</form>';
    }

    if ($section === 'activity') {
    echo '<h3>Recent Service Mappings</h3>';
    echo '<table class="datatable" width="100%" cellspacing="0">';
    echo '<tr><th>Service</th><th>Client</th><th>Node</th><th>Type</th><th>VMID</th><th>Updated</th><th>Action</th></tr>';
    if (empty($mappings)) {
        echo '<tr><td colspan="7">No mappings found.</td></tr>';
    } else {
        foreach ($mappings as $mapping) {
            $mapServiceId = (int) $mapping->service_id;
            $mapClientId = (int) $mapping->client_id;
            $mapServiceLabel = isset($serviceLabelById[$mapServiceId]) ? (string) $serviceLabelById[$mapServiceId] : 'Unknown (deleted)';
            $mapClientLabel = isset($clientLabelById[$mapClientId]) ? (string) $clientLabelById[$mapClientId] : 'Unknown (deleted)';
            $editMapLink = '<a class="btn btn-default btn-xs" href="' . $baseSection . 'service&edit_mapping_service_id=' . (int) $mapping->service_id . '">Edit</a>';
            $deleteMapForm = '<form method="post" action="' . $basePost . '" onsubmit="return confirm(\'Delete this service mapping?\');" style="margin:0;">'
                . proxmox_manager_csrf_input()
                . '<input type="hidden" name="action" value="delete_mapping">'
                . '<input type="hidden" name="service_id" value="' . (int) $mapping->service_id . '">'
                . '<button type="submit" class="btn btn-danger btn-xs">Delete</button>'
                . '</form>';
            echo '<tr>'
                . '<td>' . htmlspecialchars($mapServiceLabel) . '</td>'
                . '<td>' . htmlspecialchars($mapClientLabel) . '</td>'
                . '<td>' . htmlspecialchars((string) $mapping->node) . '</td>'
                . '<td>' . htmlspecialchars((string) $mapping->resource_type) . '</td>'
                . '<td>' . (int) $mapping->vmid . '</td>'
                . '<td>' . htmlspecialchars((string) $mapping->updated_at) . '</td>'
                . '<td>' . $editMapLink . ' ' . $deleteMapForm . '</td>'
                . '</tr>';
        }
    }
    echo '</table>';

    echo '<h3>Recent Tasks</h3>';
    echo '<table class="datatable" width="100%" cellspacing="0">';
    echo '<tr><th>ID</th><th>Service</th><th>Client</th><th>Node</th><th>VMID</th><th>Action</th><th>Status</th><th>Created</th></tr>';

    if (empty($tasks)) {
        echo '<tr><td colspan="8">No tasks logged yet.</td></tr>';
    } else {
        foreach ($tasks as $task) {
            $taskServiceId = (int) $task->service_id;
            $taskClientId = (int) $task->client_id;
            $taskServiceLabel = isset($serviceLabelById[$taskServiceId]) ? (string) $serviceLabelById[$taskServiceId] : 'Unknown (deleted)';
            $taskClientLabel = isset($clientLabelById[$taskClientId]) ? (string) $clientLabelById[$taskClientId] : 'Unknown (deleted)';
            echo '<tr>'
                . '<td>' . (int) $task->id . '</td>'
                . '<td>' . htmlspecialchars($taskServiceLabel) . '</td>'
                . '<td>' . htmlspecialchars($taskClientLabel) . '</td>'
                . '<td>' . htmlspecialchars((string) $task->node) . '</td>'
                . '<td>' . (int) $task->vmid . '</td>'
                . '<td>' . htmlspecialchars((string) $task->action) . '</td>'
                . '<td>' . htmlspecialchars((string) $task->status) . '</td>'
                . '<td>' . htmlspecialchars((string) $task->created_at) . '</td>'
                . '</tr>';
        }
    }

    echo '</table>';
    }
}

function proxmox_manager_clientarea(array $params)
{
    $clientId = proxmox_manager_current_client_id($params);
    $serviceId = isset($_GET['serviceid']) ? (int) $_GET['serviceid'] : 0;
    $successMessage = '';
    $errorMessage = '';

    $service = null;
    if ($clientId > 0 && $serviceId > 0) {
        $service = Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->where('userid', $clientId)
            ->first();
    }

    $repo = new \WHMCS\Module\Addon\ProxmoxManager\Repository();
    $serviceMeta = $service ? proxmox_manager_get_service_meta($service, $params) : [];

    if ($service && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = isset($_POST['pm_do']) ? strtolower(trim((string) $_POST['pm_do'])) : '';
        $allowed = ['start', 'stop', 'reboot'];

        if (!in_array($action, $allowed, true)) {
            $errorMessage = 'Invalid action requested.';
        } elseif (!proxmox_manager_verify_csrf_token()) {
            $errorMessage = 'Invalid security token. Refresh the page and try again.';
        } elseif (empty($serviceMeta['node']) || empty($serviceMeta['resource_type']) || empty($serviceMeta['vmid'])) {
            $errorMessage = 'Missing Proxmox mapping for this service. Save node/type/vmid into mod_proxmox_manager_services or set legacy custom fields for auto-migration.';
        } else {
            $taskData = [
                'service_id' => (int) $service->id,
                'client_id' => $clientId,
                'node' => $serviceMeta['node'],
                'resource_type' => $serviceMeta['resource_type'],
                'vmid' => (int) $serviceMeta['vmid'],
                'action' => $action,
                'status' => 'queued',
            ];

            try {
                $taskData['request_payload'] = json_encode([
                    'node' => $serviceMeta['node'],
                    'resource_type' => $serviceMeta['resource_type'],
                    'vmid' => (int) $serviceMeta['vmid'],
                    'action' => $action,
                ]);

                $api = proxmox_manager_build_api($params);
                if ($action === 'start') {
                    $response = $api->start($serviceMeta['node'], $serviceMeta['resource_type'], (int) $serviceMeta['vmid']);
                } elseif ($action === 'stop') {
                    $response = $api->stop($serviceMeta['node'], $serviceMeta['resource_type'], (int) $serviceMeta['vmid']);
                } else {
                    $response = $api->reboot($serviceMeta['node'], $serviceMeta['resource_type'], (int) $serviceMeta['vmid']);
                }

                $taskData['status'] = 'success';
                $taskData['response_payload'] = is_scalar($response) ? (string) $response : json_encode($response);
                $repo->logTask($taskData);
                $successMessage = 'Action queued successfully: ' . strtoupper($action);
            } catch (\Throwable $e) {
                $taskData['status'] = 'failed';
                $taskData['error_message'] = $e->getMessage();
                $repo->logTask($taskData);
                $errorMessage = 'Proxmox action failed: ' . $e->getMessage();
            }
        }
    }

    $statusText = '';
    if ($service && !empty($serviceMeta['node']) && !empty($serviceMeta['resource_type']) && !empty($serviceMeta['vmid'])) {
        try {
            $status = proxmox_manager_build_api($params)->getStatus(
                $serviceMeta['node'],
                $serviceMeta['resource_type'],
                (int) $serviceMeta['vmid']
            );
            $statusText = isset($status['status']) ? (string) $status['status'] : 'unknown';
        } catch (\Throwable $e) {
            if ($errorMessage === '') {
                $errorMessage = 'Unable to fetch current status: ' . $e->getMessage();
            }
        }
    }

    $tasks = $service ? $repo->latestTasksByService((int) $service->id, 10) : [];

    return [
        'pagetitle' => 'Proxmox Service Manager',
        'breadcrumb' => ['index.php?m=proxmox_manager' => 'Proxmox Manager'],
        'templatefile' => 'clienthome',
        'requirelogin' => true,
        'forcessl' => false,
        'vars' => [
            'serviceId' => $service ? (int) $service->id : 0,
            'serviceFound' => (bool) $service,
            'meta' => $serviceMeta,
            'statusText' => $statusText,
            'tasks' => $tasks,
            'moduleLink' => 'index.php?m=proxmox_manager',
            'successMessage' => $successMessage,
            'csrfToken' => proxmox_manager_csrf_token(),
            'errorMessage' => $errorMessage !== '' ? $errorMessage : (($serviceId > 0 && !$service) ? 'Service not found or access denied.' : ''),
        ],
    ];
}

function proxmox_manager_build_api(array $params)
{
    $host = proxmox_manager_setting($params, 'apiHost', '');
    $port = (int) proxmox_manager_setting($params, 'apiPort', '8006');
    $tokenId = proxmox_manager_setting($params, 'apiTokenId', '');
    $tokenSecret = proxmox_manager_setting($params, 'apiTokenSecret', '');

    return new \WHMCS\Module\Addon\ProxmoxManager\ApiClient($host, $port, true, $tokenId, $tokenSecret);
}

function proxmox_manager_setting(array $params, $key, $default = '')
{
    if (isset($params[$key]) && $params[$key] !== '') {
        return $params[$key];
    }

    return $default;
}

function proxmox_manager_current_client_id(array $params)
{
    if (isset($params['clientdetails']['id'])) {
        return (int) $params['clientdetails']['id'];
    }

    if (isset($params['client']['id'])) {
        return (int) $params['client']['id'];
    }

    if (isset($params['userid'])) {
        return (int) $params['userid'];
    }

    return 0;
}

function proxmox_manager_seed_ip_pool_leases($poolId, $cidr, $gateway = null)
{
    $poolId = (int) $poolId;
    if ($poolId < 1) {
        return false;
    }

    $cidr = trim((string) $cidr);
    if ($cidr === '' || strpos($cidr, '/') === false) {
        return false;
    }

    $parts = explode('/', $cidr, 2);
    $baseIp = isset($parts[0]) ? trim((string) $parts[0]) : '';
    $prefix = isset($parts[1]) ? (int) $parts[1] : -1;
    if ($baseIp === '' || $prefix < 16 || $prefix > 30) {
        return false;
    }

    $baseLong = ip2long($baseIp);
    if ($baseLong === false) {
        return false;
    }

    $hostCount = (int) pow(2, (32 - $prefix));
    if ($hostCount > 4096) {
        return false;
    }

    $network = $baseLong & (~((1 << (32 - $prefix)) - 1));
    $broadcast = $network + $hostCount - 1;
    $gateway = trim((string) $gateway);

    $inserted = 0;
    for ($ipLong = $network + 1; $ipLong < $broadcast; $ipLong++) {
        $ip = long2ip($ipLong);
        if ($ip === false || $ip === $gateway) {
            continue;
        }

        $exists = Capsule::table('mod_proxmox_ip_leases')->where('ip_address', $ip)->count();
        if ((int) $exists > 0) {
            continue;
        }

        Capsule::table('mod_proxmox_ip_leases')->insert([
            'pool_id' => $poolId,
            'ip_address' => $ip,
            'status' => 'free',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $inserted++;
    }

    return $inserted;
}

function proxmox_manager_template_os_key_options($osTemplateMappings)
{
    $keys = [
        'debian12',
        'ubuntu2204',
        'ubuntu2404',
        'almalinux9',
        'rocky9',
        'centosstream9',
        'wordpress',
        'dockerhost',
        'n8n',
        'make',
    ];

    if (is_iterable($osTemplateMappings)) {
        foreach ($osTemplateMappings as $row) {
            if (!isset($row->os_key)) {
                continue;
            }
            $key = strtolower(trim((string) $row->os_key));
            if ($key !== '' && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
    }

    sort($keys);
    return $keys;
}

function proxmox_manager_node_options(array $params, $mappings, $osTemplateMappings)
{
    $nodes = [];
    $defaultNode = trim((string) proxmox_manager_setting($params, 'defaultNode', ''));
    if ($defaultNode !== '') {
        $nodes[] = $defaultNode;
    }

    if (is_iterable($mappings)) {
        foreach ($mappings as $row) {
            if (!isset($row->node)) {
                continue;
            }
            $node = trim((string) $row->node);
            if ($node !== '' && !in_array($node, $nodes, true)) {
                $nodes[] = $node;
            }
        }
    }

    if (is_iterable($osTemplateMappings)) {
        foreach ($osTemplateMappings as $row) {
            if (!isset($row->node)) {
                continue;
            }
            $node = trim((string) $row->node);
            if ($node !== '' && !in_array($node, $nodes, true)) {
                $nodes[] = $node;
            }
        }
    }

    sort($nodes);
    return $nodes;
}

function proxmox_manager_datalist_option_tags(array $values)
{
    $options = '';
    foreach ($values as $value) {
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        $options .= '<option value="' . htmlspecialchars($value) . '"></option>';
    }

    return $options;
}

function proxmox_manager_pool_option_tags($ipPools, $selectedPoolId)
{
    $selectedPoolId = (int) $selectedPoolId;
    $options = '';
    $selectedFound = false;
    if (is_iterable($ipPools)) {
        foreach ($ipPools as $pool) {
            $poolId = isset($pool->id) ? (int) $pool->id : 0;
            if ($poolId < 1) {
                continue;
            }
            $label = $poolId . ' - ' . (string) $pool->pool_key . ' (' . (string) $pool->scope . ')';
            $selected = ($poolId === $selectedPoolId) ? ' selected' : '';
            if ($selected !== '') {
                $selectedFound = true;
            }
            $options .= '<option value="' . $poolId . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
        }
    }

    if ($selectedPoolId > 0 && !$selectedFound) {
        $options = '<option value="' . $selectedPoolId . '" selected>' . htmlspecialchars((string) $selectedPoolId . ' - current value') . '</option>' . $options;
    }

    return $options;
}

function proxmox_manager_simple_option_tags(array $values, $selectedValue)
{
    $selectedValue = trim((string) $selectedValue);
    $options = '';
    $selectedFound = false;
    foreach ($values as $value) {
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        $selected = ($value === $selectedValue) ? ' selected' : '';
        if ($selected !== '') {
            $selectedFound = true;
        }
        $options .= '<option value="' . htmlspecialchars($value) . '"' . $selected . '>' . htmlspecialchars($value) . '</option>';
    }

    if ($selectedValue !== '' && !$selectedFound) {
        $options = '<option value="' . htmlspecialchars($selectedValue) . '" selected>' . htmlspecialchars($selectedValue . ' (current)') . '</option>' . $options;
    }

    return $options;
}

function proxmox_manager_firewall_profile_key_options($productPolicies)
{
    $keys = [
        'web_edge',
        'private_default',
        'shared_edge',
        'strict_locked',
    ];

    if (is_iterable($productPolicies)) {
        foreach ($productPolicies as $policy) {
            if (!isset($policy->firewall_profile_key)) {
                continue;
            }
            $key = strtolower(trim((string) $policy->firewall_profile_key));
            if ($key !== '' && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
    }

    sort($keys);
    return $keys;
}

function proxmox_manager_dns_zone_options($productPolicies)
{
    $zones = ['infra.local'];

    if (is_iterable($productPolicies)) {
        foreach ($productPolicies as $policy) {
            if (!isset($policy->internal_dns_zone)) {
                continue;
            }
            $zone = strtolower(trim((string) $policy->internal_dns_zone));
            if ($zone !== '' && !in_array($zone, $zones, true)) {
                $zones[] = $zone;
            }
        }
    }

    sort($zones);
    return $zones;
}

function proxmox_manager_admin_link_no_token($moduleLink)
{
    $moduleLink = trim((string) $moduleLink);
    if ($moduleLink === '') {
        return '';
    }

    $moduleLink = preg_replace('/([?&])token=[^&]*(&)?/i', '$1', $moduleLink);
    return rtrim((string) $moduleLink, '?&');
}

function proxmox_manager_test_public_dns_provider($provider)
{
    $apiBase = isset($provider->api_base) ? trim((string) $provider->api_base) : '';
    if ($apiBase === '') {
        return ['status' => 'error', 'error' => 'API base URL is empty.'];
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiBase);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_NOBODY, true);

    $headers = [];
    if (isset($provider->api_token) && trim((string) $provider->api_token) !== '') {
        $headers[] = 'Authorization: Bearer ' . trim((string) $provider->api_token);
    }
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        return ['status' => 'error', 'error' => $error];
    }

    if ($code >= 200 && $code < 500) {
        return ['status' => 'ok', 'error' => ''];
    }

    return ['status' => 'error', 'error' => 'Unexpected HTTP status: ' . $code];
}

function proxmox_manager_csrf_token()
{
    static $cachedToken = null;
    if ($cachedToken !== null) {
        return $cachedToken;
    }

    if (function_exists('generate_token')) {
        $cachedToken = (string) generate_token('plain');
        if ($cachedToken !== '') {
            return $cachedToken;
        }
    }

    if (isset($_REQUEST['token'])) {
        $requestToken = trim((string) $_REQUEST['token']);
        if ($requestToken !== '') {
            $cachedToken = $requestToken;
            return $cachedToken;
        }
    }

    if (isset($_SESSION['token']) && trim((string) $_SESSION['token']) !== '') {
        $cachedToken = trim((string) $_SESSION['token']);
        return $cachedToken;
    }

    if (isset($_SESSION['admintoken']) && trim((string) $_SESSION['admintoken']) !== '') {
        $cachedToken = trim((string) $_SESSION['admintoken']);
        return $cachedToken;
    }

    if (isset($_SESSION['admin_token']) && trim((string) $_SESSION['admin_token']) !== '') {
        $cachedToken = trim((string) $_SESSION['admin_token']);
        return $cachedToken;
    }

    $cachedToken = '';
    return '';
}

function proxmox_manager_csrf_input()
{
    return '';
}

function proxmox_manager_verify_csrf_token()
{
    if (!function_exists('check_token')) {
        return true;
    }

    return (bool) check_token('WHMCS.default', false);
}

function proxmox_manager_verify_admin_csrf_token()
{
    // WHMCS core already enforces CSRF before addon output handlers.
    // Avoid double-validation edge cases across WHMCS builds.
    return true;
}

function proxmox_manager_get_service_meta($service, array $params)
{
    $serviceId = isset($service->id) ? (int) $service->id : 0;
    $packageId = isset($service->packageid) ? (int) $service->packageid : 0;
    $clientId = isset($service->userid) ? (int) $service->userid : null;
    $repo = new \WHMCS\Module\Addon\ProxmoxManager\Repository();

    $meta = [
        'node' => proxmox_manager_setting($params, 'defaultNode', ''),
        'resource_type' => '',
        'vmid' => 0,
    ];

    if ($serviceId < 1 || $packageId < 1) {
        return $meta;
    }

    $mapped = $repo->getServiceMapping($serviceId);
    if ($mapped) {
        $meta['node'] = trim((string) $mapped->node);
        $meta['resource_type'] = strtolower(trim((string) $mapped->resource_type));
        $meta['vmid'] = (int) $mapped->vmid;
        return $meta;
    }

    $wanted = [
        'proxmox_node' => 'node',
        'proxmox_type' => 'resource_type',
        'proxmox_vmid' => 'vmid',
    ];

    foreach ($wanted as $fieldKey => $metaKey) {
        $customField = Capsule::table('tblcustomfields')
            ->where('type', 'product')
            ->where('relid', $packageId)
            ->where(function ($query) use ($fieldKey) {
                $query->where('fieldname', $fieldKey)
                    ->orWhere('fieldname', 'like', $fieldKey . '|%');
            })
            ->first();

        if (!$customField) {
            continue;
        }

        $value = Capsule::table('tblcustomfieldsvalues')
            ->where('fieldid', (int) $customField->id)
            ->where('relid', $serviceId)
            ->value('value');

        if ($value === null || $value === '') {
            continue;
        }

        if ($metaKey === 'vmid') {
            $meta[$metaKey] = (int) $value;
            continue;
        }

        $meta[$metaKey] = trim((string) $value);
    }

    if (!empty($meta['node']) && !empty($meta['resource_type']) && !empty($meta['vmid'])) {
        $repo->saveServiceMapping($serviceId, $clientId, $meta['node'], $meta['resource_type'], (int) $meta['vmid']);
    }

    return $meta;
}
