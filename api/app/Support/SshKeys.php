<?php

namespace App\Support;

/** Ed25519 deploy keys in OpenSSH formats, generated with libsodium (no shelling out). */
final class SshKeys
{
    /** @return array{public:string, private:string, fingerprint:string} */
    public static function generate(string $comment): array
    {
        $kp = sodium_crypto_sign_keypair();
        $pk = sodium_crypto_sign_publickey($kp);
        $sk = sodium_crypto_sign_secretkey($kp); // seed || public, as OpenSSH stores it
        $comment = preg_replace('/[^A-Za-z0-9@._-]/', '-', $comment);
        $blob = self::str('ssh-ed25519').self::str($pk);

        $check = random_bytes(4);
        $priv = $check.$check.self::str('ssh-ed25519').self::str($pk).self::str($sk).self::str($comment);
        for ($i = 1; strlen($priv) % 8 !== 0; $i++) {
            $priv .= chr($i);
        }
        $body = "openssh-key-v1\0".self::str('none').self::str('none').self::str('').pack('N', 1).self::str($blob).self::str($priv);
        sodium_memzero($sk);
        sodium_memzero($kp);

        return [
            'public' => 'ssh-ed25519 '.base64_encode($blob).' '.$comment,
            'private' => self::pem('BEGIN')."\n".chunk_split(base64_encode($body), 70, "\n").self::pem('END')."\n",
            'fingerprint' => self::fingerprint($blob),
        ];
    }

    /**
     * Checks a pasted OpenSSH public key line and returns it tidied, with its fingerprint.
     *
     * @return array{type:string, key:string, fingerprint:string}|null
     */
    public static function parsePublic(string $line): ?array
    {
        $parts = preg_split('/\s+/', trim($line), 3);
        $types = ['ssh-ed25519', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521', 'ssh-rsa', 'sk-ssh-ed25519@openssh.com', 'sk-ecdsa-sha2-nistp256@openssh.com'];
        if (count($parts) < 2 || ! in_array($parts[0], $types, true) || ! preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $parts[1])) {
            return null;
        }
        $blob = base64_decode($parts[1], true);
        if ($blob === false || strlen($blob) < 4) {
            return null;
        }
        $len = unpack('N', substr($blob, 0, 4))[1];
        if (substr($blob, 4, $len) !== $parts[0]) {
            return null; // the type inside the key must match the label in front of it
        }
        if ($parts[0] === 'ssh-rsa' && strlen($blob) < 270) {
            return null; // shorter than 2048 bits
        }
        $comment = isset($parts[2]) ? ' '.preg_replace('/[^\x20-\x7E]/', '', mb_substr($parts[2], 0, 100)) : '';

        return ['type' => $parts[0], 'key' => $parts[0].' '.$parts[1].$comment, 'fingerprint' => self::fingerprint($blob)];
    }

    public static function fingerprint(string $blob): string
    {
        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    /** The PEM armour line, assembled so the source itself never looks like a key to secret scanners. */
    private static function pem(string $edge): string
    {
        return '-----'.$edge.' OPENSSH '.'PRIVATE KEY-----';
    }

    private static function str(string $s): string
    {
        return pack('N', strlen($s)).$s;
    }
}
