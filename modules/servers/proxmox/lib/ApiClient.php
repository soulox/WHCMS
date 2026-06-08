<?php

namespace WHMCS\Module\Server\Proxmox;

class ApiClient
{
    private $baseUrl;
    private $tokenId;
    private $tokenSecret;

    public function __construct($host, $port, $secure, $tokenId, $tokenSecret)
    {
        $scheme = $secure ? 'https' : 'http';
        $this->baseUrl = $scheme . '://' . $host . ':' . (int) $port . '/api2/json';
        $this->tokenId = (string) $tokenId;
        $this->tokenSecret = (string) $tokenSecret;
    }

    public function getVersion()
    {
        return $this->request('GET', '/version');
    }

    public function nextVmid()
    {
        return (int) $this->request('GET', '/cluster/nextid');
    }

    public function createLxc($node, array $payload)
    {
        return $this->request('POST', '/nodes/' . rawurlencode($node) . '/lxc', $payload);
    }

    public function cloneQemu($node, $templateVmid, array $payload)
    {
        return $this->request('POST', '/nodes/' . rawurlencode($node) . '/qemu/' . (int) $templateVmid . '/clone', $payload);
    }

    public function updateConfig($node, $resourceType, $vmid, array $payload)
    {
        return $this->request(
            'POST',
            '/nodes/' . rawurlencode($node) . '/' . $this->normalizeType($resourceType) . '/' . (int) $vmid . '/config',
            $payload
        );
    }

    public function resizeDisk($node, $vmid, $disk, $size)
    {
        return $this->request(
            'PUT',
            '/nodes/' . rawurlencode($node) . '/qemu/' . (int) $vmid . '/resize',
            ['disk' => $disk, 'size' => $size]
        );
    }

    public function start($node, $resourceType, $vmid)
    {
        return $this->request('POST', '/nodes/' . rawurlencode($node) . '/' . $this->normalizeType($resourceType) . '/' . (int) $vmid . '/status/start');
    }

    public function stop($node, $resourceType, $vmid)
    {
        return $this->request('POST', '/nodes/' . rawurlencode($node) . '/' . $this->normalizeType($resourceType) . '/' . (int) $vmid . '/status/stop');
    }

    public function reboot($node, $resourceType, $vmid)
    {
        return $this->request('POST', '/nodes/' . rawurlencode($node) . '/' . $this->normalizeType($resourceType) . '/' . (int) $vmid . '/status/reboot');
    }

    public function deleteResource($node, $resourceType, $vmid)
    {
        return $this->request('DELETE', '/nodes/' . rawurlencode($node) . '/' . $this->normalizeType($resourceType) . '/' . (int) $vmid);
    }

    public function status($node, $resourceType, $vmid)
    {
        return $this->request('GET', '/nodes/' . rawurlencode($node) . '/' . $this->normalizeType($resourceType) . '/' . (int) $vmid . '/status/current');
    }

    public function waitForTask($node, $upid, $timeoutSeconds = 180)
    {
        $deadline = time() + (int) $timeoutSeconds;
        $path = '/nodes/' . rawurlencode($node) . '/tasks/' . rawurlencode($upid) . '/status';

        do {
            $state = $this->request('GET', $path);
            if (isset($state['status']) && $state['status'] === 'stopped') {
                $exit = isset($state['exitstatus']) ? (string) $state['exitstatus'] : '';
                if ($exit !== '' && strtoupper($exit) !== 'OK') {
                    throw new \RuntimeException('Task failed: ' . $exit);
                }
                return $state;
            }
            usleep(1000000);
        } while (time() < $deadline);

        throw new \RuntimeException('Timed out waiting for Proxmox task completion.');
    }

    public function request($method, $path, array $payload = [])
    {
        if ($this->tokenId === '' || $this->tokenSecret === '') {
            throw new \RuntimeException('Missing Proxmox API token credentials.');
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        if (!empty($payload)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        }

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            throw new \RuntimeException('Proxmox API cURL error: ' . $error);
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Unexpected Proxmox response. HTTP ' . $httpCode);
        }

        if ($httpCode >= 400) {
            $message = isset($decoded['errors']) ? json_encode($decoded['errors']) : (isset($decoded['data']) ? json_encode($decoded['data']) : 'HTTP ' . $httpCode);
            throw new \RuntimeException('Proxmox API error: ' . $message);
        }

        return isset($decoded['data']) ? $decoded['data'] : [];
    }

    private function normalizeType($resourceType)
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
}
