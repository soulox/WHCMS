<?php

define('CLIENTAREA', true);

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/lib/WhmcsStore.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(array('ok' => false, 'message' => 'Method not allowed'));
        exit;
    }

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        throw new Exception('Invalid JSON payload.');
    }

    $serviceId = isset($payload['service_id']) ? (int) $payload['service_id'] : 0;
    if ($serviceId <= 0) {
        throw new Exception('service_id is required.');
    }

    $hosting = MakeProxmoxWhmcsStore::getHostingById($serviceId);
    if (!$hosting) {
        throw new Exception('Service not found.');
    }

    $server = MakeProxmoxWhmcsStore::getServerById((int) $hosting['server']);
    if (!$server) {
        throw new Exception('Server not found for service.');
    }

    makeproxmox_verifyRequestSignature($server, $raw);

    $productId = (int) $hosting['packageid'];

    if (!empty($payload['job_id'])) {
        MakeProxmoxWhmcsStore::setCustomFieldValue($serviceId, $productId, 'Last Job ID', (string) $payload['job_id']);
    }
    if (!empty($payload['external_id'])) {
        MakeProxmoxWhmcsStore::setCustomFieldValue($serviceId, $productId, 'External ID', (string) $payload['external_id']);
    }
    if (!empty($payload['instance_url'])) {
        MakeProxmoxWhmcsStore::setCustomFieldValue($serviceId, $productId, 'Instance URL', (string) $payload['instance_url']);
    }
    if (!empty($payload['status'])) {
        MakeProxmoxWhmcsStore::setCustomFieldValue($serviceId, $productId, 'Provisioning Status', (string) $payload['status']);
    }
    if (!empty($payload['error_message'])) {
        MakeProxmoxWhmcsStore::setCustomFieldValue($serviceId, $productId, 'Last Error', (string) $payload['error_message']);
    }

    if (!empty($payload['status']) && $payload['status'] === 'active') {
        MakeProxmoxWhmcsStore::updateHosting($serviceId, array('domainstatus' => 'Active'));
    }
    if (!empty($payload['status']) && $payload['status'] === 'suspended') {
        MakeProxmoxWhmcsStore::updateHosting($serviceId, array('domainstatus' => 'Suspended'));
    }
    if (!empty($payload['status']) && $payload['status'] === 'terminated') {
        MakeProxmoxWhmcsStore::updateHosting($serviceId, array('domainstatus' => 'Terminated'));
    }

    makeproxmox_safeLog('callback', $payload, array('ok' => true));
    echo json_encode(array('ok' => true));
} catch (Exception $e) {
    http_response_code(400);
    makeproxmox_safeLog('callback_error', array(), array('error' => $e->getMessage()));
    echo json_encode(array('ok' => false, 'message' => $e->getMessage()));
}

function makeproxmox_verifyRequestSignature(array $server, $rawBody)
{
    $auth = isset($_SERVER['HTTP_AUTHORIZATION']) ? trim((string) $_SERVER['HTTP_AUTHORIZATION']) : '';
    if (stripos($auth, 'Bearer ') !== 0) {
        throw new Exception('Missing bearer token.');
    }

    $token = trim(substr($auth, 7));
    $expectedToken = trim((string) $server['accesshash']);
    if ($expectedToken === '' || !hash_equals($expectedToken, $token)) {
        throw new Exception('Invalid bearer token.');
    }

    $signature = isset($_SERVER['HTTP_X_MAKE_SIGNATURE']) ? trim((string) $_SERVER['HTTP_X_MAKE_SIGNATURE']) : '';
    if ($signature === '') {
        return;
    }

    $secret = trim((string) makeproxmox_extractServerSecret($server));
    if ($secret === '') {
        throw new Exception('Signature provided but server password/secret is empty.');
    }

    $computed = hash_hmac('sha256', $rawBody, $secret);
    if (!hash_equals($computed, $signature)) {
        throw new Exception('Invalid HMAC signature.');
    }
}

function makeproxmox_safeLog($action, $request, $response)
{
    if (function_exists('logModuleCall')) {
        call_user_func('logModuleCall', 'makeproxmox', $action, $request, $response, '', array('password', 'token', 'Authorization'));
    }
}

function makeproxmox_extractServerSecret(array $server)
{
    $raw = isset($server['password']) ? (string) $server['password'] : '';
    if ($raw !== '' && function_exists('decrypt')) {
        try {
            return (string) call_user_func('decrypt', $raw);
        } catch (Exception $e) {
            return $raw;
        }
    }

    return $raw;
}
