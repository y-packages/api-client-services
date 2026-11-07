<?php

declare(strict_types=1);

namespace YakNet\Api\Webhook;

/**
 * Verifies incoming YakNet Cloud Webhook (Eventarc) HMAC-SHA256 signatures (`X-YakNet-Signature`).
 */
final class SignatureVerifier
{
    /**
     * Validate that the raw request body matches the HMAC-SHA256 signature header.
     */
    public static function verify(string $rawPayload, string $signatureHeader, string $signingSecret): bool
    {
        $signature = trim($signatureHeader);
        if ($signature === '' || $signingSecret === '') {
            return false;
        }

        if (str_starts_with($signature, 'sha256=')) {
            $signature = substr($signature, 7);
        }

        $computed = hash_hmac('sha256', $rawPayload, $signingSecret);

        return hash_equals($computed, $signature);
    }

    /**
     * Compute an HMAC-SHA256 signature header value for testing webhook receivers.
     */
    public static function sign(string $rawPayload, string $signingSecret): string
    {
        return 'sha256='.hash_hmac('sha256', $rawPayload, $signingSecret);
    }
}
