<?php

namespace App\Support;

/** signature = hex(HMAC-SHA256(key, id.type.canonical_json(payload).issued_at)) — docs/API.md. */
final class CommandSigner
{
    public static function message(string $id, string $type, mixed $payload, string $issuedAt): string
    {
        return $id.'.'.$type.'.'.CanonicalJson::encode($payload).'.'.$issuedAt;
    }

    public static function sign(string $key, string $id, string $type, mixed $payload, string $issuedAt): string
    {
        return hash_hmac('sha256', self::message($id, $type, $payload, $issuedAt), $key);
    }
}
