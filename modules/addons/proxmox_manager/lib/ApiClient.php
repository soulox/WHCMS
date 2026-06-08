<?php

namespace WHMCS\Module\Addon\ProxmoxManager;

class ApiClient
{
    private $host;
    private $port;
    private $secure;
    private $tokenId;
    private $tokenSecret;
    private $baseUrl;

    public function __construct($host, $port, $secure, $tokenId, $tokenSecret)
    {
        $this->host = $host;
        $this->port = (int) $port;
        $this->secure = (bool) $secure;
        $this->tokenId = (string) $tokenId;
        $this->tokenSecret = (string) $tokenSecret;

        $scheme = $this->secure ? 'https' : 'http';
        $this->baseUrl = $scheme . '://' . $this->host . ':' . $this->port . '/api2/json';
    }

    public function getVersion()
    {
        $data = $this->request('GET', '/version');
        return isset($data['release']) ? $data['release'] : 'unknown';
    }

    public function getStatus($node, $resourceType, $vmid)
    {
        $apiType = $this->normalizeResourceType($resourceType);
        return $this->request('GET', '/nodes/' . rawurlencode($node) . '/' . $apiType . '/' . (int) $vmid . '/status/current');
    }

    public function start($node, $resourceType, $vmid)
    {
        return $this->powerAction($node, $resourceType, $vmid, 'start');
    }

    public function stop($node, $resourceType, $vmid)
    {
        return $this->powerAction($node, $resourceType, $vmid, 'stop');
    }

    public function reboot($node, $resourceType, $vmid)
    {
        return $this->powerAction($node, $resourceType, $vmid, 'reboot');
    }

    private function powerAction($node, $resourceType, $vmid, $action)
    {
        $apiType = $this->normalizeResourceType($resourceType);
        $valid = ['start', 'stop', 'reboot'];
        if (!in_array($action, $valid, true)) {
            throw new \InvalidArgumentException('Unsupported power action: ' . $action);
        }

        return $this->request('POST', '/nodes/' . rawurlencode($node) . '/' . $apiType . '/' . (int) $vmid . '/status/' . $action);
    }

    private function normalizeResourceType($resourceType)
    {
        $type = strtolower(trim((string) $resourceType));
        if ($type === 'kvm' || $type === 'qemu') {
            return 'qemu';
        }
        if ($type === 'lxc') {
            return 'lxc';
        }

        throw new \InvalidArgumentException('Unsupported resource type: ' . $resourceType);
    }

    public function request($method, $path, array $payload = [])
    {
        if ($this->host === '' || $this->tokenId === '' || $this->tokenSecret === '') {
            throw new \RuntimeException('Proxmox API settings are incomplete.');
        }

        $url = $this->baseUrl . $path;
        $headers = [
            'Authorization: PVEAPIToken=' . $this->tokenId . '=' . $this->tokenSecret,
            'Content-Type: application/x-www-form-urlencoded',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        if (!empty($payload)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        }

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            throw new \RuntimeException('cURL error: ' . $error);
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid API response. HTTP ' . $code);
        }

        if ($code >= 400) {
            $message = isset($decoded['errors']) ? json_encode($decoded['errors']) : 'HTTP ' . $code;
            throw new \RuntimeException('Proxmox API error: ' . $message);
        }

        return isset($decoded['data']) ? $decoded['data'] : [];
    }
}
