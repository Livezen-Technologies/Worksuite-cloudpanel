<?php

namespace App\Drivers;

use App\Contracts\DnsResolver;

/** In-memory DNS for tests and offline demos. */
class FakeDnsResolver implements DnsResolver
{
    private array $records = [];

    public function set(string $name, string $type, array $values): void
    {
        $this->records[strtolower($name)][$type] = $values;
    }

    public function lookup(string $name, string $type): array
    {
        return $this->records[strtolower($name)][$type] ?? [];
    }
}
