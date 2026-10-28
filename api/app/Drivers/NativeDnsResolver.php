<?php

namespace App\Drivers;

use App\Contracts\DnsResolver;

class NativeDnsResolver implements DnsResolver
{
    public function lookup(string $name, string $type): array
    {
        $constant = ['A' => DNS_A, 'AAAA' => DNS_AAAA, 'CNAME' => DNS_CNAME, 'TXT' => DNS_TXT][$type] ?? DNS_A;
        $records = @dns_get_record($name, $constant) ?: [];

        return array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? $r['target'] ?? $r['txt'] ?? null, $records)));
    }
}
