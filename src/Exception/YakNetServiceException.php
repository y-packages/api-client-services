<?php

declare(strict_types=1);

namespace YakNet\Api\Exception;

class YakNetServiceException extends YakNetException
{
    private int $statusCode;
    private string $rawBody;

    /** @var array<string, mixed> */
    private array $payload;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(string $message, int $statusCode = 0, array $payload = [], string $rawBody = '')
    {
        parent::__construct($message, $statusCode);
        $this->statusCode = $statusCode;
        $this->payload = $payload;
        $this->rawBody = $rawBody;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getRawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }
}
