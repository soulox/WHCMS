<?php

class MakeProxmoxApiClient
{
    private $baseUrl;
    private $token;
    private $serviceId;

    public function __construct($baseUrl, $token, $serviceId)
    {
        $this->baseUrl = rtrim((string) $baseUrl, '/');
        $this->token = (string) $token;
        $this->serviceId = (int) $serviceId;
    }

    public function get($path)
    {
        return $this->request('GET', $path);
    }

    public function post($path, array $payload)
    {
        return $this->request('POST', $path, $payload);
    }

    private function request($method, $path, array $payload = array())
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $headers = array(
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->token,
            'X-Service-Id: ' . $this->serviceId,
        );

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method !== 'GET') {
            $jsonBody = json_encode($payload);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        }

        $rawResponse = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($rawResponse === false) {
            throw new Exception('Provisioner API request failed: ' . $curlError);
        }

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            $decoded = array('raw' => $rawResponse);
        }

        if ($statusCode < 200 || $statusCode > 299) {
            $msg = !empty($decoded['message']) ? $decoded['message'] : 'HTTP ' . $statusCode;
            throw new Exception('Provisioner API error: ' . $msg);
        }

        return $decoded;
    }
}
