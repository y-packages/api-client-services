<?php

declare(strict_types=1);

namespace YakNet\Api;

use YakNet\Api\Exception\YakNetServiceException;
use YakNet\Api\Http\CurlTransport;
use YakNet\Api\Http\HttpResponse;
use YakNet\Api\Http\TransportInterface;

class Client
{
    public const LIBVER = '1.0.0';
    public const DEFAULT_BASE_URL = 'https://developer-console.yakhub.com.tr';

    private string $baseUrl = self::DEFAULT_BASE_URL;
    private ?string $apiKey = null;
    private ?string $clientId = null;
    private ?string $clientSecret = null;
    private ?string $accessToken = null;
    private string $applicationName = '';
    private int $timeout = 15;
    private TransportInterface $transport;

    /** @var array<string, string> */
    private array $defaultHeaders = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [], ?TransportInterface $transport = null)
    {
        if (isset($config['base_url']) && is_string($config['base_url'])) {
            $this->setBaseUrl($config['base_url']);
        } elseif (isset($config['baseUrl']) && is_string($config['baseUrl'])) {
            $this->setBaseUrl($config['baseUrl']);
        }

        if (isset($config['api_key']) && is_string($config['api_key'])) {
            $this->setApiKey($config['api_key']);
        } elseif (isset($config['apiKey']) && is_string($config['apiKey'])) {
            $this->setApiKey($config['apiKey']);
        }

        $cid = $config['client_id'] ?? $config['clientId'] ?? null;
        $csec = $config['client_secret'] ?? $config['clientSecret'] ?? null;
        if (is_string($cid) && $cid !== '') {
            $this->clientId = $cid;
        }
        if (is_string($csec) && $csec !== '') {
            $this->clientSecret = $csec;
        }

        $token = $config['access_token'] ?? $config['accessToken'] ?? null;
        if (is_string($token) && $token !== '') {
            $this->setAccessToken($token);
        }

        if (isset($config['application_name']) && is_string($config['application_name'])) {
            $this->setApplicationName($config['application_name']);
        }

        if (isset($config['timeout']) && is_int($config['timeout'])) {
            $this->setTimeout($config['timeout']);
        }

        $verifySsl = ! isset($config['verify_ssl']) || (bool) $config['verify_ssl'];
        $this->transport = $transport ?? new CurlTransport($verifySsl);
    }

    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = rtrim($baseUrl, '/');

        return $this;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function setApiKey(string $apiKey): self
    {
        $this->apiKey = trim($apiKey);

        return $this;
    }

    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    public function setClientCredentials(string $clientId, ?string $clientSecret = null): self
    {
        $this->clientId = trim($clientId);
        $this->clientSecret = $clientSecret !== null ? trim($clientSecret) : null;

        return $this;
    }

    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    public function getClientSecret(): ?string
    {
        return $this->clientSecret;
    }

    public function setAccessToken(string $accessToken): self
    {
        $this->accessToken = trim($accessToken);

        return $this;
    }

    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    public function setApplicationName(string $applicationName): self
    {
        $this->applicationName = trim($applicationName);

        return $this;
    }

    public function getApplicationName(): string
    {
        return $this->applicationName;
    }

    public function setTimeout(int $seconds): self
    {
        $this->timeout = max(1, $seconds);

        return $this;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function setTransport(TransportInterface $transport): self
    {
        $this->transport = $transport;

        return $this;
    }

    public function getTransport(): TransportInterface
    {
        return $this->transport;
    }

    public function setDefaultHeader(string $name, string $value): self
    {
        $this->defaultHeaders[$name] = $value;

        return $this;
    }

    /**
     * Execute an authenticated REST API call and return the raw HttpResponse.
     *
     * @param array<string, mixed>|null $payload
     * @param array<string, string> $extraHeaders
     */
    public function request(string $method, string $pathOrUrl, ?array $payload = null, array $extraHeaders = []): HttpResponse
    {
        $url = str_starts_with($pathOrUrl, 'http://') || str_starts_with($pathOrUrl, 'https://')
            ? $pathOrUrl
            : $this->baseUrl.'/'.ltrim($pathOrUrl, '/');

        $ua = ($this->applicationName !== '' ? $this->applicationName.' ' : '')
            .'yaknet-api-php-client/'.self::LIBVER;

        $headers = array_merge([
            'Accept' => 'application/json',
            'User-Agent' => $ua,
        ], $this->defaultHeaders);

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $headers['X-API-Key'] = $this->apiKey;
        }

        if ($this->clientId !== null && $this->clientId !== '') {
            $headers['X-Client-ID'] = $this->clientId;
        }

        if ($this->clientSecret !== null && $this->clientSecret !== '') {
            $headers['X-Client-Secret'] = $this->clientSecret;
        }

        if ($this->accessToken !== null && $this->accessToken !== '') {
            $headers['Authorization'] = 'Bearer '.$this->accessToken;
        }

        foreach ($extraHeaders as $k => $v) {
            $headers[$k] = $v;
        }

        $body = null;
        $upperMethod = strtoupper($method);
        if ($payload !== null && ! in_array($upperMethod, ['GET', 'HEAD'], true)) {
            $headers['Content-Type'] = 'application/json';
            $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $response = $this->transport->send($upperMethod, $url, $headers, $body, $this->timeout);
        $status = $response->getStatusCode();

        if ($status >= 400) {
            $decoded = $response->json() ?? [];
            $errorMsg = "YakNet API Hatası (HTTP {$status})";
            if (isset($decoded['error']) && is_string($decoded['error'])) {
                $errorMsg = $decoded['error'];
            } elseif (isset($decoded['message']) && is_string($decoded['message'])) {
                $errorMsg = $decoded['message'];
            } elseif (isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                $errorMsg = $decoded['error']['message'];
            }

            throw new YakNetServiceException($errorMsg, $status, $decoded, $response->getBody());
        }

        return $response;
    }
}
