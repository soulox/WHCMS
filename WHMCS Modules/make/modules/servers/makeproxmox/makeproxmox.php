<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

require_once __DIR__ . '/lib/ApiClient.php';
require_once __DIR__ . '/lib/WhmcsStore.php';

function makeproxmox_MetaData()
{
    return array(
        'DisplayName' => 'Make Proxmox Provisioning',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultNonSSLPort' => '8080',
        'DefaultSSLPort' => '443',
    );
}

function makeproxmox_ConfigOptions()
{
    return array(
        'Package Key' => array(
            'Type' => 'dropdown',
            'Options' => 'make-starter,make-professional,make-enterprise',
            'Description' => 'Mapped to provisioner plan matrix',
        ),
        'Region' => array(
            'Type' => 'text',
            'Size' => '25',
            'Default' => 'default',
            'Description' => 'Node region key, e.g. us-west',
        ),
        'Runtime Channel' => array(
            'Type' => 'dropdown',
            'Options' => 'stable,latest',
            'Default' => 'stable',
            'Description' => 'Runtime update ring/channel',
        ),
        'Backup Retention Days' => array(
            'Type' => 'text',
            'Size' => '5',
            'Default' => '7',
            'Description' => 'Used by backup scheduler',
        ),
    );
}

function makeproxmox_TestConnection($params)
{
    try {
        $api = makeproxmox_buildApiClient($params);
        $response = $api->get('/v1/ping');
        if (!empty($response['ok'])) {
            return array('success' => true);
        }
        return array('success' => false, 'error' => 'Ping endpoint returned unexpected payload.');
    } catch (Exception $e) {
        return array('success' => false, 'error' => $e->getMessage());
    }
}

function makeproxmox_CreateAccount($params)
{
    return makeproxmox_queueLifecycleAction($params, 'create', '/v1/jobs/provision');
}

function makeproxmox_SuspendAccount($params)
{
    return makeproxmox_queueLifecycleAction($params, 'suspend', '/v1/jobs/suspend');
}

function makeproxmox_UnsuspendAccount($params)
{
    return makeproxmox_queueLifecycleAction($params, 'unsuspend', '/v1/jobs/unsuspend');
}

function makeproxmox_TerminateAccount($params)
{
    return makeproxmox_queueLifecycleAction($params, 'terminate', '/v1/jobs/terminate', true);
}

function makeproxmox_ChangePackage($params)
{
    return makeproxmox_queueLifecycleAction($params, 'change_package', '/v1/jobs/change-package');
}

function makeproxmox_ClientArea($params)
{
    $externalId = makeproxmox_getServiceCustomField($params, 'External ID');
    $viewData = array(
        'externalId' => $externalId,
        'lastJobId' => makeproxmox_getServiceCustomField($params, 'Last Job ID'),
        'instanceUrl' => makeproxmox_getServiceCustomField($params, 'Instance URL'),
        'provisioningStatus' => makeproxmox_getServiceCustomField($params, 'Provisioning Status'),
        'lastError' => makeproxmox_getServiceCustomField($params, 'Last Error'),
        'usage' => array(),
        'errorMessage' => '',
    );

    if (!empty($externalId)) {
        try {
            $api = makeproxmox_buildApiClient($params);
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

function makeproxmox_ClientAreaCustomButtonArray()
{
    return array(
        'Restart Instance' => 'RestartInstance',
        'Run Backup Now' => 'RunBackupNow',
    );
}

function makeproxmox_RestartInstance($params)
{
    return makeproxmox_queueLifecycleAction($params, 'restart', '/v1/jobs/restart');
}

function makeproxmox_RunBackupNow($params)
{
    return makeproxmox_queueLifecycleAction($params, 'backup_now', '/v1/jobs/backup');
}

function makeproxmox_queueLifecycleAction($params, $action, $endpoint, $clearExternalIdOnQueue = false)
{
    try {
        $api = makeproxmox_buildApiClient($params);
        $payload = makeproxmox_buildBasePayload($params);
        if ($payload['plan_code'] === '') {
            return 'Plan code is missing. Set Package Key in product Module Settings or use product name mapping.';
        }
        $payload['action'] = $action;
        $payload['external_id'] = makeproxmox_getServiceCustomField($params, 'External ID');

        $response = $api->post($endpoint, $payload);
        if (empty($response['job_id'])) {
            return 'Provisioner did not return a job_id.';
        }

        makeproxmox_setServiceCustomField($params, 'Last Job ID', $response['job_id']);
        if (!empty($response['external_id'])) {
            makeproxmox_setServiceCustomField($params, 'External ID', $response['external_id']);
        }
        if ($clearExternalIdOnQueue) {
            makeproxmox_setServiceCustomField($params, 'External ID', '');
        }

        makeproxmox_safeModuleLog($action, $payload, $response);
        return 'success';
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

function makeproxmox_buildApiClient($params)
{
    $scheme = !empty($params['serversecure']) ? 'https' : 'http';
    $host = $params['serverhostname'] ?: $params['serverip'];
    $port = !empty($params['serversecure']) ? $params['serverportssl'] : $params['serverport'];
    $baseUrl = rtrim($scheme . '://' . $host . ':' . $port, '/');

    $apiToken = trim((string) $params['serveraccesshash']);
    if ($apiToken === '') {
        throw new Exception('Server Access Hash is empty. Put your provisioner API token there.');
    }

    return new MakeProxmoxApiClient($baseUrl, $apiToken, (int) $params['serviceid']);
}

function makeproxmox_buildBasePayload($params)
{
    $planCode = makeproxmox_resolvePlanCode($params);

    return array(
        'service_id' => (int) $params['serviceid'],
        'client_id' => (int) $params['clientsdetails']['id'],
        'product_id' => (int) $params['pid'],
        'plan_code' => $planCode,
        'region' => (string) (trim((string) $params['configoption2']) !== '' ? $params['configoption2'] : 'default'),
        'runtime_channel' => (string) (trim((string) $params['configoption3']) !== '' ? $params['configoption3'] : 'stable'),
        'backup_retention_days' => (int) ($params['configoption4'] !== '' ? $params['configoption4'] : 7),
        'hostname' => (string) $params['domain'],
        'username' => (string) $params['username'],
        'password' => (string) $params['password'],
        'email' => (string) $params['clientsdetails']['email'],
        'firstname' => (string) $params['clientsdetails']['firstname'],
        'lastname' => (string) $params['clientsdetails']['lastname'],
        'custom_domain' => (string) makeproxmox_getServiceCustomField($params, 'Custom Domain'),
    );
}

function makeproxmox_resolvePlanCode($params)
{
    $configured = trim((string) $params['configoption1']);
    if ($configured !== '') {
        return $configured;
    }

    $productName = strtolower(trim((string) (isset($params['productname']) ? $params['productname'] : '')));
    if ($productName !== '') {
        if (strpos($productName, 'starter') !== false) {
            return 'make-starter';
        }
        if (strpos($productName, 'professional') !== false || strpos($productName, 'pro') !== false) {
            return 'make-professional';
        }
        if (strpos($productName, 'enterprise') !== false) {
            return 'make-enterprise';
        }
    }

    $customFieldPlan = trim((string) makeproxmox_getServiceCustomField($params, 'Package Key'));
    if ($customFieldPlan !== '') {
        return $customFieldPlan;
    }

    return '';
}

function makeproxmox_getServiceCustomField($params, $fieldName)
{
    if (isset($params['customfields'][$fieldName])) {
        return (string) $params['customfields'][$fieldName];
    }

    try {
        return MakeProxmoxWhmcsStore::getCustomFieldValue((int) $params['serviceid'], (int) $params['pid'], $fieldName);
    } catch (Exception $e) {
        makeproxmox_safeModuleLog('getServiceCustomField:' . $fieldName, array('service_id' => $params['serviceid']), array('error' => $e->getMessage()));
    }

    return '';
}

function makeproxmox_setServiceCustomField($params, $fieldName, $value)
{
    try {
        MakeProxmoxWhmcsStore::setCustomFieldValue((int) $params['serviceid'], (int) $params['pid'], $fieldName, $value);
    } catch (Exception $e) {
        makeproxmox_safeModuleLog('setServiceCustomField:' . $fieldName, array('service_id' => $params['serviceid']), array('error' => $e->getMessage()));
    }
}

function makeproxmox_safeModuleLog($action, $request, $response)
{
    if (function_exists('logModuleCall')) {
        call_user_func('logModuleCall', 'makeproxmox', $action, $request, $response, '', array('password', 'token', 'Authorization'));
    }
}
