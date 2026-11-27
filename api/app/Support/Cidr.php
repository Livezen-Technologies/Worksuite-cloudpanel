<?php

namespace App\Support;

/** IPv4 CIDR helpers for VPN address planning. */
final class Cidr
{
    /** @return array{0:int,1:int}|null first and last address as integers */
    public static function range(string $cidr): ?array
    {
        if (! preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', trim($cidr), $m)) {
            return null;
        }
        $ip = ip2long($m[1]);
        $bits = (int) $m[2];
        if ($ip === false || $bits < 8 || $bits > 30) {
            return null;
        }
        $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
        $start = $ip & $mask;

        return [$start, $start | (~$mask & 0xFFFFFFFF)];
    }

    public static function isNetworkAddress(string $cidr): bool
    {
        [$ip] = explode('/', $cidr);
        $r = self::range($cidr);

        return $r !== null && ip2long($ip) === $r[0];
    }

    public static function isPrivate(string $cidr): bool
    {
        $r = self::range($cidr);
        if (! $r) {
            return false;
        }
        foreach (['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10'] as $block) {
            $b = self::range($block);
            if ($r[0] >= $b[0] && $r[1] <= $b[1]) {
                return true;
            }
        }

        return false;
    }

    public static function overlaps(string $a, string $b): bool
    {
        $x = self::range($a);
        $y = self::range($b);

        return $x && $y && $x[0] <= $y[1] && $y[0] <= $x[1];
    }
}
