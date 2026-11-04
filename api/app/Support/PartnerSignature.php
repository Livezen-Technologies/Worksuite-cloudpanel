<?php

namespace App\Support;

/**
 * The partner request and webhook signatures (docs/PARTNER_API.md), shared with
 * WorkSuite Cloud's client (api/src/deployments/livezen.ts there).
 *
 * Request:  X-Livezen-Signature: v1=hex(HMAC-SHA256(secret, canonical))
 *           canonical = timestamp \n METHOD \n path?query \n sha256hex(body) \n nonce
 * Webhook:  X-Livezen-Signature: t=<timestamp>,v1=hex(HMAC-SHA256(webhook_secret, timestamp . "." . body))
 */
class PartnerSignature
{
    public static function canonical(string $timestamp, string $method, string $pathWithQuery, string $body, string $nonce): string
    {
        return implode("\n", [$timestamp, strtoupper($method), $pathWithQuery, hash('sha256', $body), $nonce]);
    }

    public static function sign(string $secret, string $canonical): string
    {
        return 'v1='.hash_hmac('sha256', $canonical, $secret);
    }

    public static function verify(string $secret, string $canonical, string $header): bool
    {
        return hash_equals(self::sign($secret, $canonical), trim($header));
    }

    public static function webhookHeader(string $secret, string $body, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
