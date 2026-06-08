<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

require_once __DIR__ . '/lib/ApiClient.php';
require_once __DIR__ . '/lib/WhmcsStore.php';

function n8nproxmox_MetaData()
{
    return array(
        'DisplayName' => 'n8n Proxmox Provisioning',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultNonSSLPort' => '8080',
        'DefaultSSLPort' => '443',
    );
}

function n8nproxmox_ConfigOptions()
{
    return array(
        'Plan Code' => array(
            'Type' => 'dropdown',
            'Options' => 'starter_5g,pro_20g,scale_50g',
            'Description' => 'Mapped to provisioner plan matrix',
        ),
        'Region' => array(
            'Type' => 'text',
            'Size' => '25',
            'Default' => 'default',
            'Description' => 'Node region key, e.g. us-east',
        ),
        'n8n Version Channel' => array(
            'Type' => 'dropdown',
            'Options' => 'stable,latest',
            'Default' => 'stable',
            'Description' => 'Update ring/channel',
        ),
        'Backup Retention Days' => array(
            'Type' => 'text',
            'Size' => '5',
            'Default' => '7',
            'Description' => 'Used by backup scheduler',
        ),
    );
}

function n8nproxmox_TestConnection($params)
{
    try {
        $api = n8nproxmox_buildApiClient($params);
        $response = $api->get('/v1/ping');

        if (!empty($response['ok'])) {
            return array('success' => true);
        }

        return array('success' => false, 'error' => 'Ping endpoint returned unexpected payload.');
    } catch (Exception $e) {
        return array('success' => false, 'error' => $e->getMessage());
    }
}

function n8nproxmox_CreateAccount($params)
{
    try {
        n8nproxmox_validateProvisioningConfig($params);

        $api = n8nproxmox_buildApiClient($params);
        $payload = n8nproxmox_buildBasePayload($params);
        $payload['action'] = 'create';

        $response = $api->post('/v1/jobs/provision', $payload);

        if (empty($response['job_id'])) {
            return 'Provisioner did not return a job_id.';
        }

        n8nproxmox_setServiceCustomField($params, 'Last Job ID', $response['job_id']);
        if (!empty($response['external_id'])) {
            n8nproxmox_setServiceCustomField($params, 'External ID', $response['external_id']);
        }

        n8nproxmox_safeModuleLog('CreateAccount', $payload, $response);

        return 'success';
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

function n8nproxmox_SuspendAccount($params)
{
    return n8nproxmox_queueLifecycleAction($params, 'suspend', '/v1/jobs/suspend');
}

function n8nproxmox_UnsuspendAccount($params)
{
    return n8nproxmox_queueLifecycleAction($params, 'unsuspend', '/v1/jobs/unsuspend');
}

function n8nproxmox_TerminateAccount($params)
{
    return n8nproxmox_queueLifecycleAction($params, 'terminate', '/v1/jobs/terminate', true);
}

function n8nproxmox_ChangePackage($params)
{
    n8nproxmox_validateProvisioningConfig($params);
    return n8nproxmox_queueLifecycleAction($params, 'change_package', '/v1/jobs/change-package');
}

function n8nproxmox_ClientArea($params)
{
    $externalId = n8nproxmox_getServiceCustomField($params, 'External ID');
    $lastJobId = n8nproxmox_getServiceCustomField($params, 'Last Job ID');

    $viewData = array(
        'externalId' => $externalId,
        'lastJobId' => $lastJobId,
        'instanceUrl' => n8nproxmox_getServiceCustomField($params, 'Instance URL'),
        'provisioningStatus' => n8nproxmox_getServiceCustomField($params, 'Provisioning Status'),
        'lastError' => n8nproxmox_getServiceCustomField($params, 'Last Error'),
        'usage' => array(),
        'errorMessage' => '',
    );

    if (!empty($externalId)) {
        try {
            $api = n8nproxmox_buildApiClient($params);
            $status = $api->get('/v1/tenants/' . rawurlencode($externalId) . '/status');
            $usage = $api->get('/v1/tenants/' . rawurlencode($externalId) . '/usage');

            if (!empty($status['instance_url'])) {
                $viewData['instanceUrl'] = $status['instance_url'];
            }
            if (!empty($usage) && is_array($usage)) {
                $viewData['usage'] = $usage;
            }
        } catch (Exception $e) {
            $viewData['errorMessage'] = $e->getMessage();
        }
    }

    return array(
        'templatefile' => 'templates/clientarea',
        'vars' => $viewData,
    );
}

function n8nproxmox_AdminServicesTabFields($params)
{
    return array(
        'External ID' => n8nproxmox_getServiceCustomField($params, 'External ID'),
        'Last Job ID' => n8nproxmox_getServiceCustomField($params, 'Last Job ID'),
    );
}

function n8nproxmox_AdminServicesTabFieldsSave($params)
{
    if (isset($params['External ID'])) {
        n8nproxmox_setServiceCustomField($params, 'External ID', $params['External ID']);
    }
    if (isset($params['Last Job ID'])) {
        n8nproxmox_setServiceCustomField($params, 'Last Job ID', $params['Last Job ID']);
    }
}

function n8nproxmox_ClientAreaCustomButtonArray()
{
    return array(
        'Restart Instance' => 'RestartInstance',
        'Run Backup Now' => 'RunBackupNow',
    );
}

function n8nproxmox_RestartInstance($params)
{
    return n8nproxmox_queueLifecycleAction($params, 'restart', '/v1/jobs/restart');
}

function n8nproxmox_RunBackupNow($params)
{
    return n8nproxmox_queueLifecycleAction($params, 'backup_now', '/v1/jobs/backup');
}

function n8nproxmox_queueLifecycleAction($params, $action, $endpoint, $clearExternalIdOnQueue = false)
{
    try {
        n8nproxmox_validateProvisioningConfig($params);

        $externalId = n8nproxmox_getServiceCustomField($params, 'External ID');
        $api = n8nproxmox_buildApiClient($params);

        $payload = n8nproxmox_buildBasePayload($params);
        $payload['action'] = $action;
        $payload['external_id'] = $externalId;

        $response = $api->post($endpoint, $payload);
        if (empty($response['job_id'])) {
            return 'Provisioner did not return a job_id.';
        }

        n8nproxmox_setServiceCustomField($params, 'Last Job ID', $response['job_id']);
        if (!empty($response['external_id'])) {
            n8nproxmox_setServiceCustomField($params, 'External ID', $response['external_id']);
        }
        if ($clearExternalIdOnQueue) {
            n8nproxmox_setServiceCustomField($params, 'External ID', '');
        }

        n8nproxmox_safeModuleLog($action, $payload, $response);

        return 'success';
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

function n8nproxmox_buildApiClient($params)
{
    $scheme = !empty($params['serversecure']) ? 'https' : 'http';
    $host = $params['serverhostname'] ?: $params['serverip'];
    $port = !empty($params['serversecure']) ? $params['serverportssl'] : $params['serverport'];
    $baseUrl = rtrim($scheme . '://' . $host . ':' . $port, '/');

    $apiToken = trim((string) $params['serveraccesshash']);
    if ($apiToken === '') {
        throw new Exception('Server Access Hash is empty. Put your provisioner API token there.');
    }

    return new N8nProxmoxApiClient($baseUrl, $apiToken, (int) $params['serviceid']);
}

function n8nproxmox_buildBasePayload($params)
{
    $planCode = n8nproxmox_getConfigOption($params, 'Plan Code', 1, 'starter_5g');
    $region = n8nproxmox_getConfigOption($params, 'Region', 2, 'default');
    $versionChannel = n8nproxmox_getConfigOption($params, 'n8n Version Channel', 3, 'stable');
    $backupRetentionDays = (int) n8nproxmox_getConfigOption($params, 'Backup Retention Days', 4, '7');

    return array(
        'service_id' => (int) $params['serviceid'],
        'client_id' => (int) $params['clientsdetails']['id'],
        'product_id' => (int) $params['pid'],
        'plan_code' => (string) $planCode,
        'region' => (string) $region,
        'version_channel' => (string) $versionChannel,
        'backup_retention_days' => $backupRetentionDays,
        'hostname' => (string) $params['domain'],
        'username' => (string) $params['username'],
        'password' => (string) $params['password'],
        'email' => (string) $params['clientsdetails']['email'],
        'firstname' => (string) $params['clientsdetails']['firstname'],
        'lastname' => (string) $params['clientsdetails']['lastname'],
        'custom_domain' => (string) n8nproxmox_getServiceCustomField($params, 'Custom Domain'),
    );
}

function n8nproxmox_getServiceCustomField($params, $fieldName)
{
    if (isset($params['customfields'][$fieldName])) {
        return (string) $params['customfields'][$fieldName];
    }

    try {
        return N8nProxmoxWhmcsStore::getCustomFieldValue((int) $params['serviceid'], (int) $params['pid'], $fieldName);
    } catch (Exception $e) {
        n8nproxmox_safeModuleLog('getServiceCustomField:' . $fieldName, array('service_id' => $params['serviceid']), array('error' => $e->getMessage()));
    }

    return '';
}

function n8nproxmox_setServiceCustomField($params, $fieldName, $value)
{
    try {
        N8nProxmoxWhmcsStore::setCustomFieldValue((int) $params['serviceid'], (int) $params['pid'], $fieldName, $value);
    } catch (Exception $e) {
        n8nproxmox_safeModuleLog('setServiceCustomField:' . $fieldName, array('service_id' => $params['serviceid']), array('error' => $e->getMessage()));
    }
}

function n8nproxmox_safeModuleLog($action, $request, $response)
{
    if (function_exists('logModuleCall')) {
        call_user_func('logModuleCall', 'n8nproxmox', $action, $request, $response, '', array('password', 'token', 'Authorization'));
    }
}

function n8nproxmox_getConfigOption($params, $name, $index, $default = '')
{
    if (isset($params['configoptions']) && isset($params['configoptions'][$name])) {
        $value = trim((string) $params['configoptions'][$name]);
        if ($value !== '') {
            return $value;
        }
    }

    $legacyKey = 'configoption' . (int) $index;
    if (isset($params[$legacyKey])) {
        $value = trim((string) $params[$legacyKey]);
        if ($value !== '') {
            return $value;
        }
    }

    return (string) $default;
}

function n8nproxmox_validateProvisioningConfig($params)
{
    $planCode = n8nproxmox_getConfigOption($params, 'Plan Code', 1, '');
    if ($planCode === '') {
        throw new Exception('Product module option "Plan Code" is required for automatic provisioning.');
    }

    $allowedPlans = array('starter_5g', 'pro_20g', 'scale_50g');
    if (!in_array($planCode, $allowedPlans, true)) {
        throw new Exception('Invalid Plan Code "' . $planCode . '". Allowed: ' . implode(', ', $allowedPlans));
    }
}
