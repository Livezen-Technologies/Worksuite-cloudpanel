<?php

namespace App\Services;

use App\Contracts\CertificateIssuer;
use App\Contracts\DnsResolver;
use App\Exceptions\ApiException;
use App\Models\Application;
use App\Models\Certificate;
use App\Models\DnsCheck;
use App\Models\Domain;
use App\Models\Route;

/** Domains, DNS readiness (DOM-003), certificates (DOM-002), conflicts (DOM-004, UDOM-006). */
class DomainService
{
    public function __construct(private DnsResolver $dns, private CertificateIssuer $issuer, private CommandBus $bus) {}

    public function create(array $data, bool $internal = false): Domain
    {
        $name = strtolower(trim($data['name'], '. '));
        $this->assertAvailable($name, null, $internal);
        $domain = Domain::create([
            'name' => $name,
            'application_id' => $data['application_id'] ?? null,
            'target_port' => $data['target_port'] ?? null,
            'wildcard' => str_starts_with($name, '*.') || ! empty($data['wildcard']),
            'www_redirect' => (bool) ($data['www_redirect'] ?? false),
            'challenge' => str_starts_with($name, '*.') ? 'dns-01' : ($data['challenge'] ?? 'http-01'),
            'platform_subdomain' => (bool) ($data['platform_subdomain'] ?? false),
            'options' => $data['options'] ?? null,
            'dns_provider' => $data['dns_provider'] ?? null,
            'dns_status' => ! empty($data['platform_subdomain']) ? 'verified' : 'pending',
        ]);
        if (! empty($data['dns_token'])) {
            $this->setDnsToken($domain, $data['dns_token']);
        }
        if ($domain->application_id) {
            Route::create(['domain_id' => $domain->id, 'application_id' => $domain->application_id, 'port' => $domain->target_port]);
        }

        return $domain;
    }

    /** DNS-01 provider token, stored encrypted and write-only. */
    public function setDnsToken(Domain $domain, string $token): void
    {
        $secret = $domain->dns_secret_id ? \App\Models\Secret::find($domain->dns_secret_id) : null;
        $secret ? $secret->update(['value' => $token])
            : $domain->update(['dns_secret_id' => \App\Models\Secret::create(['name' => 'dns-token-'.$domain->name, 'value' => $token, 'owner_type' => 'Domain', 'owner_id' => $domain->id])->id]);
    }

    /** Refuses names used by any organisation (checked across tenants). */
    public function assertAvailable(string $name, ?string $ignoreId = null, bool $internal = false): void
    {
        $existing = Domain::allTenants()->where('name', $name)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->first();
        if ($existing) {
            $sameOrg = $existing->organization_id === \App\Support\Tenant::current()?->id;
            throw ApiException::conflict($sameOrg ? "{$name} is already connected in your organisation." : "{$name} is already used by another account. If you own it, contact support.");
        }
        $platform = config('platform.domain');
        if (! $internal && ($name === $platform || str_ends_with($name, '.'.$platform))) {
            throw ApiException::validation(['domain' => ["Subdomains of {$platform} are assigned automatically."]]);
        }
    }

    /** DNS records the customer must create (UDOM-002). */
    public function expectedRecords(Domain $domain): array
    {
        $ip = $this->ingressIp($domain);
        $records = [['type' => 'A', 'name' => $domain->name, 'value' => $ip]];
        if ($domain->www_redirect) {
            $records[] = ['type' => 'CNAME', 'name' => 'www.'.$domain->name, 'value' => $domain->name];
        }
        if ($domain->challenge === 'dns-01') {
            $records[] = ['type' => 'TXT', 'name' => '_acme-challenge.'.ltrim($domain->name, '*.'), 'value' => substr(hash('sha256', $domain->id), 0, 43)];
        }

        return $records;
    }

    public function ingressIp(Domain $domain): string
    {
        $server = $domain->application_id ? Application::find($domain->application_id)?->server : null;

        return $server?->ip ?: config('platform.ingress_ip');
    }

    /** Checks each record; issues the certificate as soon as DNS verifies (UDOM-004). */
    public function verify(Domain $domain): array
    {
        $records = [];
        foreach ($this->expectedRecords($domain) as $record) {
            $lookupName = ltrim($record['name'], '*.');
            $values = $domain->platform_subdomain ? [$record['value']] : $this->dns->lookup($lookupName, $record['type']);
            $ok = in_array(rtrim($record['value'], '.'), array_map(fn ($v) => rtrim($v, '.'), $values), true);
            $records[] = $record + ['status' => $ok ? 'verified' : 'waiting', 'found' => $values];
        }
        $allOk = collect($records)->every(fn ($r) => $r['status'] === 'verified');
        DnsCheck::create(['domain_id' => $domain->id, 'records' => $records, 'ok' => $allOk, 'created_at' => now()]);
        $domain->update(['dns_status' => $allOk ? 'verified' : 'pending', 'dns_checked_at' => now()]);

        if ($allOk && $domain->https_status !== 'active') {
            $this->issueCertificate($domain);
        }

        return ['domain_id' => $domain->id, 'records' => array_map(fn ($r) => array_diff_key($r, ['found' => 1]), $records), 'dns_status' => $domain->dns_status, 'https_status' => $domain->fresh()->https_status];
    }

    public function issueCertificate(Domain $domain): Certificate
    {
        if ($domain->dns_status !== 'verified') {
            throw ApiException::conflict('DNS is not pointing at the platform yet. Create the records shown and check again.');
        }
        $domain->update(['https_status' => 'issuing']);
        $names = $domain->www_redirect ? [$domain->name, 'www.'.$domain->name] : [$domain->name];
        try {
            $issued = $this->issuer->issue($domain, $names, $domain->challenge);
        } catch (\Throwable $e) {
            $domain->update(['https_status' => 'failed']);

            return Certificate::create(['domain_id' => $domain->id, 'status' => 'failed', 'names' => $names, 'last_error' => $e->getMessage()]);
        }
        if (! empty($issued['pending'])) {
            // Traefik (ACME) issues asynchronously; status/expiry come from acme.json (proxy.certs).
            return Certificate::where('domain_id', $domain->id)->where('status', 'issuing')->first()
                ?? Certificate::create(['domain_id' => $domain->id, 'issuer' => $issued['issuer'], 'status' => 'issuing', 'names' => $issued['names'], 'resolver' => $issued['resolver'] ?? null]);
        }
        Certificate::where('domain_id', $domain->id)->where('status', 'active')->update(['status' => 'replaced']);
        $certificate = Certificate::create([
            'domain_id' => $domain->id, 'issuer' => $issued['issuer'], 'status' => 'active', 'names' => $issued['names'],
            'issued_at' => $issued['issued_at'], 'expires_at' => $issued['expires_at'],
        ]);
        $domain->update(['https_status' => 'active']);

        return $certificate;
    }

    public function toApi(Domain $domain): array
    {
        $certificate = $domain->certificate;
        $app = $domain->application_id ? Application::find($domain->application_id) : null;

        return [
            'id' => $domain->id,
            'name' => $domain->name,
            'domain' => $domain->name,
            'application_id' => $domain->application_id,
            'app_id' => $domain->application_id,
            'app_name' => $app?->name,
            'target_port' => $domain->target_port,
            'wildcard' => $domain->wildcard,
            'www_redirect' => $domain->www_redirect,
            'redirect_www' => $domain->www_redirect,
            'platform_subdomain' => $domain->platform_subdomain,
            'challenge' => $domain->challenge,
            'dns_provider' => $domain->dns_provider,
            'has_dns_token' => (bool) $domain->dns_secret_id,
            'dns_status' => $domain->dns_status,
            'https_status' => $domain->https_status,
            'records' => $this->expectedRecords($domain),
            'certificate' => $certificate ? [
                'issuer' => $certificate->issuer, 'status' => $certificate->status,
                'expires_at' => $certificate->expires_at?->toIso8601ZuluString(),
                'days_left' => $certificate->expires_at ? (int) now()->diffInDays($certificate->expires_at, false) : null,
                'resolver' => $certificate->resolver, 'checked_at' => $certificate->checked_at?->toIso8601ZuluString(),
            ] : null,
            'renewal_at' => $certificate?->expires_at?->copy()->subDays(30)->toIso8601ZuluString(),
            'expiring' => $certificate?->expires_at && $certificate->expires_at->lt(now()->addDays(14)),
            'options' => $domain->options,
            'dns_checked_at' => $domain->dns_checked_at?->toIso8601ZuluString(),
            'created_at' => $domain->created_at?->toIso8601ZuluString(),
        ];
    }
}
