<?php

declare(strict_types=1);

namespace YakNet\Api;

abstract class Service
{
    public string $serviceName = '';
    public string $version = 'v1';
    public string $servicePath = '';

    protected Client $client;

    /**
     * @param Client|array<string, mixed> $clientOrConfig
     */
    public function __construct(Client|array $clientOrConfig = [])
    {
        $this->client = $clientOrConfig instanceof Client
            ? $clientOrConfig
            : new Client($clientOrConfig);
    }

    public function getClient(): Client
    {
        return $this->client;
    }
}
