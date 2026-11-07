<?php

declare(strict_types=1);

namespace YakNet\Api\Http;

use YakNet\Api\Exception\YakNetException;

final class CurlTransport implements TransportInterface
{
    private bool $verifySsl;

    public function __construct(bool $verifySsl = true)
    {
        $this->verifySsl = $verifySsl;
    }

    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): HttpResponse
    {
        $method = strtoupper($method);
        $formattedHeaders = [];
        foreach ($headers as $key => $value) {
            $formattedHeaders[] = "{$key}: {$value}";
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new YakNetException("cURL başlatılamadı: {$url}");
        }

        $responseHeaders = [];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $formattedHeaders,
            CURLOPT_CONNECTTIMEOUT => min(8, max(1, $timeout)),
            CURLOPT_TIMEOUT => max(1, $timeout),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                $len = strlen($headerLine);
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $len;
            },
        ]);

        if ($body !== null && $method !== 'GET' && $method !== 'HEAD') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $rawBody = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }

        if ($rawBody === false) {
            throw new YakNetException("YakNet API ağ hatası ({$url}): {$curlError}");
        }

        return new HttpResponse($statusCode, (string) $rawBody, $responseHeaders);
    }
}
