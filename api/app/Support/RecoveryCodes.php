<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * MFA-REC: one-time recovery codes for a lost authenticator. Only SHA-256 hashes are stored;
 * the plain codes are returned once when generated. Each code works a single time.
 */
class RecoveryCodes
{
    public const COUNT = 10;
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789'; // no 0/o, 1/l/i

    /** Replaces the user's codes with a fresh set and returns the plain codes. */
    public static function generate(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < self::COUNT; $i++) {
            $raw = '';
            for ($j = 0; $j < 10; $j++) {
                $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $codes[] = substr($raw, 0, 5).'-'.substr($raw, 5);
        }
        $user->forceFill(['mfa_recovery_codes' => array_map([self::class, 'hash'], $codes)])->save();

        return $codes;
    }

    /** Uses up a code if it is one of the user's unused codes. Safe against a double submit. */
    public static function consume(User $user, ?string $code): bool
    {
        $normal = self::normalize($code);
        if (strlen($normal) !== 10) {
            return false;
        }
        $hash = self::hash($normal);

        return DB::transaction(function () use ($user, $hash) {
            $fresh = User::whereKey($user->id)->lockForUpdate()->first();
            $left = (array) ($fresh->mfa_recovery_codes ?? []);
            $i = array_search($hash, $left, true);
            if ($i === false) {
                return false;
            }
            array_splice($left, $i, 1);
            $fresh->forceFill(['mfa_recovery_codes' => array_values($left)])->save();
            $user->setRawAttributes($fresh->getAttributes(), true);

            return true;
        });
    }

    public static function remaining(User $user): int
    {
        return count((array) ($user->mfa_recovery_codes ?? []));
    }

    private static function normalize(?string $code): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string) $code));
    }

    private static function hash(string $code): string
    {
        return hash('sha256', self::normalize($code));
    }
}
