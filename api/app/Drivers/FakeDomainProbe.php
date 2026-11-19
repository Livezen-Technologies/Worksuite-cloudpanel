<?php

namespace App\Drivers;

use App\Contracts\DnsResolver;
use App\Contracts\DomainProbe;

/** PDNS: tests and offline demos. Propagation mirrors the fake resolver unless a resolver is set. */
class FakeDomainProbe implements DomainProbe
{
    public array $resolvers = [];   // [label][name][type] => values|null

    public array $certificates = [];

    public array $responses = [];

    public function propagation(string $name, string $type): array
    {
        $base = app(DnsResolver::class)->lookup($name, $type);
        $out = [];
        foreach (array_keys((array) config('platform.ingress.resolvers', [])) as $label) {
            $out[$label] = array_key_exists($label, $this->resolvers) && array_key_exists(strtolower($name), $this->resolvers[$label])
                ? ($this->resolvers[$label][strtolower($name)][$type] ?? [])
                : array_map(fn ($v) => rtrim($v, '.'), $base);
        }

        return $out;
    }

    public function certificate(string $host): array
    {
        return $this->certificates[strtolower($host)] ?? ['ok' => false, 'error' => 'Connection refused.'];
    }

    public function https(string $host): array
    {
        return $this->responses[strtolower($host)] ?? ['ok' => false, 'error' => 'Connection refused.'];
    }
}
