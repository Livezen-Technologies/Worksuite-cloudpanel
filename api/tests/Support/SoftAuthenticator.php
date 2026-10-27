<?php

namespace Tests\Support;

/**
 * Minimal software WebAuthn authenticator for tests: ES256 key, "none" attestation,
 * hand-encoded CBOR, user presence + verification flags. Produces the JSON that
 * @simplewebauthn/browser posts (base64url fields).
 */
class SoftAuthenticator
{
    public \OpenSSLAsymmetricKey $key;

    public string $credentialId;

    public int $counter = 0;

    public ?string $userHandle = null;

    public function __construct(public string $origin = 'http://localhost:5173', public string $rpId = 'localhost')
    {
        $this->key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->credentialId = random_bytes(32);
    }

    public static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function unb64u(string $s): string
    {
        return base64_decode(strtr($s, '-_', '+/'));
    }

    // --- tiny CBOR encoder (major types 0,1,2,3,5) ---
    private static function head(int $major, int $n): string
    {
        if ($n < 24) {
            return chr(($major << 5) | $n);
        }
        if ($n < 256) {
            return chr(($major << 5) | 24).chr($n);
        }

        return chr(($major << 5) | 25).pack('n', $n);
    }

    private static function cbor(mixed $v): string
    {
        return match (true) {
            is_int($v) && $v >= 0 => self::head(0, $v),
            is_int($v) => self::head(1, -1 - $v),
            $v instanceof CborBytes => self::head(2, strlen($v->bytes)).$v->bytes,
            is_string($v) => self::head(3, strlen($v)).$v,
            is_array($v) => self::head(5, count($v)).implode('', array_map(fn ($k, $x) => self::cbor($k).self::cbor($x), array_keys($v), $v)),
        };
    }

    private function coseKey(): string
    {
        $d = openssl_pkey_get_details($this->key)['ec'];

        return self::cbor([1 => 2, 3 => -7, -1 => 1, -2 => new CborBytes(str_pad($d['x'], 32, "\0", STR_PAD_LEFT)), -3 => new CborBytes(str_pad($d['y'], 32, "\0", STR_PAD_LEFT))]);
    }

    private function authData(bool $attested): string
    {
        $flags = 0x01 | 0x04 | ($attested ? 0x40 : 0);
        $data = hash('sha256', $this->rpId, true).chr($flags).pack('N', $this->counter);
        if ($attested) {
            $data .= str_repeat("\0", 16).pack('n', strlen($this->credentialId)).$this->credentialId.$this->coseKey();
        }

        return $data;
    }

    private function clientData(string $type, string $challengeB64u): string
    {
        return json_encode(['type' => $type, 'challenge' => $challengeB64u, 'origin' => $this->origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES);
    }

    /** navigator.credentials.create() for the given options (JSON form). */
    public function create(array $options): array
    {
        $this->userHandle = self::unb64u($options['user']['id']);
        $attObj = self::cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => new CborBytes($this->authData(true))]);
        $id = self::b64u($this->credentialId);

        return ['id' => $id, 'rawId' => $id, 'type' => 'public-key', 'clientExtensionResults' => (object) [], 'authenticatorAttachment' => 'platform',
            'response' => ['clientDataJSON' => self::b64u($this->clientData('webauthn.create', $options['challenge'])), 'attestationObject' => self::b64u($attObj), 'transports' => ['internal']]];
    }

    /** navigator.credentials.get() for the given options (JSON form). */
    public function get(array $options, ?string $challengeOverride = null): array
    {
        $this->counter++;
        $authData = $this->authData(false);
        $cd = $this->clientData('webauthn.get', $challengeOverride ?? $options['challenge']);
        openssl_sign($authData.hash('sha256', $cd, true), $sig, $this->key, OPENSSL_ALGO_SHA256);
        $id = self::b64u($this->credentialId);

        return ['id' => $id, 'rawId' => $id, 'type' => 'public-key', 'clientExtensionResults' => (object) [],
            'response' => ['clientDataJSON' => self::b64u($cd), 'authenticatorData' => self::b64u($authData), 'signature' => self::b64u($sig), 'userHandle' => self::b64u((string) $this->userHandle)]];
    }
}

final class CborBytes
{
    public function __construct(public string $bytes) {}
}
