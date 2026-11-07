<?php

declare(strict_types=1);

namespace YakNet\Api\Http;

interface TransportInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): HttpResponse;
}
