<?php

namespace App\Support;

use Pdp\Domain as PdpDomain;
use Pdp\Rules;

/**
 * PDNS-2: apex vs subdomain from the Public Suffix List (ICANN section), so "example.co.uk" is an
 * apex and "erp.example.co.uk" a subdomain. The list ships in resources/psl and can be refreshed
 * into storage/app/psl with `php artisan dns:refresh-psl`; the newer copy wins.
 */
class DomainName
{
    private static ?Rules $rules = null;

    public static function listPath(): string
    {
        $fresh = storage_path('app/psl/public_suffix_list.dat');

        return is_file($fresh) && filesize($fresh) > 100000 ? $fresh : resource_path('psl/public_suffix_list.dat');
    }

    public static function rules(): Rules
    {
        return self::$rules ??= Rules::fromPath(self::listPath());
    }

    public static function forget(): void
    {
        self::$rules = null;
    }

    /** The registrable domain ("example.co.uk" for "erp.example.co.uk"), or null when there is none. */
    public static function registrable(string $name): ?string
    {
        $name = strtolower(trim(ltrim(rtrim($name, '.'), '*.')));
        try {
            $r = self::rules()->getICANNDomain(PdpDomain::fromIDNA2008($name))->registrableDomain()->toString();

            return $r !== '' ? $r : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** True for a name below its registrable domain. A name with no known suffix falls back to counting labels. */
    public static function isSubdomain(string $name): bool
    {
        $clean = strtolower(trim(ltrim(rtrim($name, '.'), '*.')));
        $registrable = self::registrable($clean);
        if ($registrable === null) {
            return substr_count($clean, '.') >= 2;
        }

        return $clean !== $registrable;
    }

    public static function isApex(string $name): bool
    {
        return ! self::isSubdomain($name);
    }
}
