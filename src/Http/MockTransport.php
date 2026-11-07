<?php

declare(strict_types=1);

namespace YakNet\Api\Http;

use YakNet\Api\Exception\YakNetException;

final class MockTransport implements TransportInterface
{
    /** @var list<HttpResponse|callable> */
    private array $queue = [];

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    private array $history = [];

    /**
     * @param list<HttpResponse|callable> $responses
     */
    public function __construct(array $responses = [])
    {
        $this->queue = $responses;
    }

    /**
     * @param array<string, mixed>|string $body
     * @param array<string, string> $headers
     */
    public function queueJson(array|string $body, int $statusCode = 200, array $headers = ['Content-Type' => 'application/json']): self
    {
        $raw = is_array($body) ? (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $body;
        $this->queue[] = new HttpResponse($statusCode, $raw, $headers);

        return $this;
    }

    public function queueCallback(callable $callback): self
    {
        $this->queue[] = $callback;

        return $this;
    }

    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): HttpResponse
    {
        $this->history[] = [
            'method' => strtoupper($method),
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        ];

        if (empty($this->queue)) {
            throw new YakNetException("MockTransport kuyruğunda bekleyen yanıt kalmadı ({$method} {$url}).");
        }

        $next = array_shift($this->queue);
        if (is_callable($next)) {
            $res = $next($method, $url, $headers, $body);
            if ($res instanceof HttpResponse) {
                return $res;
            }
            if (is_array($res)) {
                return new HttpResponse(200, (string) json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ['content-type' => 'application/json']);
            }
        }

        if ($next instanceof HttpResponse) {
            return $next;
        }

        throw new YakNetException('Geçersiz MockTransport yanıt nesnesi.');
    }

    /**
     * @return list<array{method: string, url: string, headers: array<string, string>, body: ?string}>
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    /**
     * @return array{method: string, url: string, headers: array<string, string>, body: ?string}|null
     */
    public function getLastRequest(): ?array
    {
        if (empty($this->history)) {
            return null;
        }

        return $this->history[count($this->history) - 1];
    }
}
