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
    public function __construct(private DnsResolver $dns, private CertificateIssuer $issuer, private CommandBus $bus, private DnsZoneService $zones) {}

    public function create(array $data, bool $internal = false): Domain
    {
        $name = strtolower(trim($data['name'], '. '));
        $this->assertAvailable($name, null, $internal);
        // DNS-013: a domain inside one of the organisation's DNS zones gets its certificate by DNS-01
        // through that zone, unless a challenge or a provider token is given.
        $zone = empty($data['platform_subdomain']) && empty($data['dns_token']) ? \App\Models\DnsZone::forDomain($name, \App\Support\Tenant::current()?->id) : null;
        $projectId = $data['project_id'] ?? (! empty($data['application_id']) ? Application::find($data['application_id'])?->project_id : null);
        $method = $data['dns_method'] ?? null;
        $domain = Domain::create([
            'name' => $name,
            'application_id' => $data['application_id'] ?? null,
            'project_id' => $projectId,
            'dns_method' => $method,
            // PDNS: a custom domain added through a project proves ownership with a TXT record first.
            'ownership_token' => $method && $method !== 'platform' && empty($data['platform_subdomain']) ? \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(32)) : null,
            'is_primary' => (bool) ($data['is_primary'] ?? false),
            'target_port' => $data['target_port'] ?? null,
            'wildcard' => str_starts_with($name, '*.') || ! empty($data['wildcard']),
            'www_redirect' => (bool) ($data['www_redirect'] ?? false),
            'challenge' => str_starts_with($name, '*.') ? 'dns-01' : ($data['challenge'] ?? ($zone ? 'dns-01' : 'http-01')),
            'platform_subdomain' => (bool) ($data['platform_subdomain'] ?? false),
            'options' => isset($data['options']) ? array_diff_key($data['options'], ['waf' => 1]) : null,
            'dns_provider' => $data['dns_provider'] ?? null,
            'dns_status' => ! empty($data['platform_subdomain']) ? 'verified' : 'pending',
        ]);
        if (! empty($data['dns_token'])) {
            $this->setDnsToken($domain, $data['dns_token']);
        }
        // ACME-DLG: on the edges HTTP-01 cannot work (each edge orders its own certificate), so a
        // customer-owned Enterprise domain gets DNS-01 through a delegation CNAME instead.
        if (empty($data['challenge']) && $domain->challenge === 'http-01' && AcmeDelegation::eligible($domain)) {
            $domain->update(['challenge' => 'dns-01']);
        }
        // PDNS: a project domain the customer chose to manage by hand is never written to the zone.
        if ($zone && $method !== 'manual') {
            $domain->publishedRecords = $this->publishToZone($domain);
        }
        if ($domain->application_id) {
            Route::create(['domain_id' => $domain->id, 'application_id' => $domain->application_id, 'port' => $domain->target_port]);
        }

        return $domain;
    }

    /**
     * DNS-013: writes the records the domain needs into the managed zone that holds it. Records the
     * zone already has are left alone; a different value under the same name is reported, never
     * overwritten. Returns [name, type, status: created|present|conflict|failed, message?] per record.
     */
    public function publishToZone(Domain $domain): array
    {
        $zone = \App\Models\DnsZone::forDomain($domain->name, $domain->organization_id);
        if (! $zone || $domain->platform_subdomain || $domain->dns_secret_id) {
            return [];
        }
        // PDNS-2: read the provider first, so a record the customer added there since the last
        // sync is seen (and kept) rather than clashed with.
        rescue(fn () => $this->zones->sync($zone), report: false);
        $existing = $zone->records()->withoutGlobalScope('organization')->get();
        $out = [];
        $expected = $this->expectedRecords($domain);
        foreach ($expected as $r) {
            // An address record for the routing name that is not ours means the name already serves
            // something (an existing website): adding ours beside it would split traffic. Refuse.
            if (in_array($r['type'], ['A', 'AAAA'], true)) {
                $ours = collect($expected)->whereIn('type', ['A', 'AAAA'])->where('name', $r['name'])->pluck('value')->map(fn ($v) => rtrim($v, '.'))->all();
                $foreign = $existing->first(fn ($e) => $e->name === $r['name'] && in_array($e->type, ['A', 'AAAA'], true) && ! in_array(rtrim($e->content, '.'), $ours, true));
                if ($foreign) {
                    $out[] = ['name' => $r['name'], 'type' => $r['type'], 'status' => 'conflict', 'message' => 'The zone already has a '.$foreign->type.' record there ('.$foreign->content.'), probably an existing website. It was left alone.'];
                    continue;
                }
            }
            $same = $existing->filter(fn ($e) => $e->type === $r['type'] && $e->name === $r['name']);
            // A CNAME cannot share its name with any other record, and nothing else may sit beside a
            // CNAME: never replace a customer's existing record (website, MX, SPF, DKIM, DMARC…).
            $clash = $existing->filter(fn ($e) => $e->name === $r['name'] && $e->type !== $r['type'] && ($r['type'] === 'CNAME' || $e->type === 'CNAME'));
            if ($clash->isNotEmpty()) {
                $out[] = ['name' => $r['name'], 'type' => $r['type'], 'status' => 'conflict', 'message' => 'The zone already has a '.$clash->first()->type.' record there ('.$clash->first()->content.'). It was left alone.'];
                continue;
            }
            if ($same->contains(fn ($e) => rtrim($e->content, '.') === rtrim($r['value'], '.'))) {
                $out[] = ['name' => $r['name'], 'type' => $r['type'], 'status' => 'present'];
                continue;
            }
            // Several TXT values may share a name; any other type with a different value is a conflict.
            if ($same->isNotEmpty() && $r['type'] !== 'TXT') {
                $out[] = ['name' => $r['name'], 'type' => $r['type'], 'status' => 'conflict', 'message' => 'The zone already has '.$same->first()->content.'.'];
                continue;
            }
            try {
                $this->zones->createRecord($zone, ['type' => $r['type'], 'name' => $r['name'], 'content' => $r['value'], 'ttl' => 1,
                    'proxied' => ! empty($r['proxied']) && $zone->provider?->driver()->supportsProxy()]);
                $out[] = ['name' => $r['name'], 'type' => $r['type'], 'status' => 'created'];
            } catch (\Throwable $e) {
                $out[] = ['name' => $r['name'], 'type' => $r['type'], 'status' => 'failed', 'message' => $e->getMessage()];
            }
        }

        return $out;
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
        if ($domain->dns_method && ! $domain->platform_subdomain) {
            return $this->projectRecords($domain);
        }
        // XNODE-4: an enterprise domain on the edges is proxied (orange cloud) so the watchdog can
        // drop a failed edge's record without visitors' resolvers caching it.
        $proxied = $this->edgeProxied($domain);
        $records = array_map(fn ($ip) => array_filter(['type' => 'A', 'name' => $domain->name, 'value' => $ip, 'proxied' => $proxied ?: null]), $this->routingIps($domain));
        if ($domain->www_redirect) {
            $records[] = ['type' => 'CNAME', 'name' => 'www.'.$domain->name, 'value' => $domain->name];
        }
        if (AcmeDelegation::zoneFor($domain)) {
            $records[] = array_diff_key(AcmeDelegation::record($domain), ['purpose' => 1]);
        } elseif ($domain->challenge === 'dns-01') {
            $records[] = ['type' => 'TXT', 'name' => '_acme-challenge.'.ltrim($domain->name, '*.'), 'value' => substr(hash('sha256', $domain->id), 0, 43)];
        }

        return $records;
    }

    /**
     * The records the customer must create, each with the status the last check found:
     * verified | missing | unchecked (never checked yet). Every list of a domain's DNS uses this,
     * so a record (the ACME-DLG delegation CNAME included) always shows with its state.
     */
    public function recordsWithStatus(Domain $domain): array
    {
        $last = DnsCheck::allTenants()->where('domain_id', $domain->id)->latest('created_at')->first();
        $seen = collect($last?->records ?? []);

        return array_map(function ($r) use ($seen, $domain) {
            $hit = $seen->first(fn ($c) => ($c['type'] ?? null) === $r['type'] && ($c['name'] ?? null) === $r['name'] && rtrim((string) ($c['value'] ?? ''), '.') === rtrim((string) $r['value'], '.'));

            return $r + ['purpose' => $this->purposeOf($domain, $r), 'status' => $hit ? (in_array($hit['status'] ?? null, ['verified', 'pass'], true) ? 'verified' : 'missing') : 'unchecked'];
        }, $this->expectedRecords($domain));
    }

    /** routing | ownership | www | certificate (expectedRecords() outside a project names none). */
    private function purposeOf(Domain $domain, array $r): string
    {
        return $r['purpose'] ?? match (true) {
            str_starts_with($r['name'], '_acme-challenge.') => 'certificate',
            $r['name'] === $this->ownershipName($domain) => 'ownership',
            $r['name'] === 'www.'.$domain->name => 'www',
            default => 'routing',
        };
    }

    /**
     * PDNS: records for a domain added through a project. They name the cluster ingress, never the
     * node a container runs on: a subdomain is a CNAME to the ingress host name when one is set,
     * an apex domain gets A (and AAAA) records for the ingress addresses.
     */
    public function projectRecords(Domain $domain): array
    {
        $name = $domain->name;
        $host = $this->ingressHostname();
        $records = [];
        $edges = $this->enterpriseEdgeIps($domain);
        if ($edges) {
            // XNODE-2: an Enterprise deployment is served only by the edge servers, never by the
            // cluster ingress, so a CNAME to the ingress host would send visitors (and Let's Encrypt)
            // to a server that answers 404. Name every edge instead, as the deployment's own address does.
            foreach ($edges as $ip) {
                $records[] = ['type' => 'A', 'name' => $name, 'value' => $ip, 'purpose' => 'routing'];
            }
        } elseif ($this->isSubdomain($name) && $host && ! $domain->wildcard) {
            $records[] = ['type' => 'CNAME', 'name' => $name, 'value' => $host, 'purpose' => 'routing'];
        } else {
            foreach ($this->ingressIps() as $ip) {
                $records[] = ['type' => 'A', 'name' => $name, 'value' => $ip, 'purpose' => 'routing'];
            }
            foreach ((array) config('platform.ingress.ipv6', []) as $ip) {
                $records[] = ['type' => 'AAAA', 'name' => $name, 'value' => $ip, 'purpose' => 'routing'];
            }
        }
        if ($domain->www_redirect) {
            $records[] = ['type' => 'CNAME', 'name' => 'www.'.$name, 'value' => $name, 'purpose' => 'www'];
        }
        if ($domain->ownership_token) {
            $records[] = ['type' => 'TXT', 'name' => $this->ownershipName($domain), 'value' => $this->ownershipValue($domain), 'purpose' => 'ownership'];
        }
        if (AcmeDelegation::zoneFor($domain)) {
            // ACME-DLG: the challenge is delegated by CNAME to a zone the panel writes, so this
            // replaces the TXT (a CNAME cannot share its name with any other record).
            $records[] = AcmeDelegation::record($domain);
        } elseif ($domain->challenge === 'dns-01') {
            $records[] = ['type' => 'TXT', 'name' => '_acme-challenge.'.ltrim($name, '*.'), 'value' => substr(hash('sha256', $domain->id), 0, 43), 'purpose' => 'certificate'];
        }

        return $records;
    }

    /** The edge servers' addresses when the domain belongs to an Enterprise deployment and edges exist; [] otherwise. */
    public function enterpriseEdgeIps(Domain $domain): array
    {
        if (! \App\Services\TraefikConfigService::isEnterpriseDomain($domain)) {
            return [];
        }

        return \App\Services\TraefikConfigService::edgeServers()->pluck('ip')->filter()->values()->all();
    }

    public function ownershipName(Domain $domain): string
    {
        return config('platform.ingress.ownership_label', '_livezen-verify').'.'.ltrim($domain->name, '*.');
    }

    public function ownershipValue(Domain $domain): string
    {
        return 'livezen-verify='.$domain->ownership_token;
    }

    /** The configured ingress addresses; the single legacy PLATFORM_INGRESS_IP when none are listed. */
    public function ingressIps(): array
    {
        return (array) config('platform.ingress.ips') ?: [(string) config('platform.ingress_ip')];
    }

    public function ingressHostname(): ?string
    {
        $h = strtolower(rtrim(trim((string) config('platform.ingress.hostname')), '.'));

        return $h !== '' ? $h : null;
    }

    /**
     * PDNS-2: decided by the Public Suffix List, so "example.co.uk" is an apex and
     * "erp.example.co.uk" a subdomain ("shop.example.com" a subdomain, "example.com" an apex).
     */
    public function isSubdomain(string $name): bool
    {
        return \App\Support\DomainName::isSubdomain($name);
    }

    /**
     * XNODE-2: the addresses a domain's A records name. An enterprise domain, once enterprise
     * edges exist, names every edge (customer DNS points at all of them for redundancy);
     * everything else keeps the single address it had.
     */
    public function routingIps(Domain $domain): array
    {
        if (\App\Services\TraefikConfigService::isEnterpriseDomain($domain)) {
            $edges = \App\Services\TraefikConfigService::edgeServers()->pluck('ip')->filter()->values()->all();
            if ($edges) {
                return $edges;
            }
        }

        return [$this->ingressIp($domain)];
    }

    private function zoneHasEdgeRecord(Domain $domain, string $name): bool
    {
        $zone = \App\Models\DnsZone::forDomain($domain->name, $domain->organization_id);
        $edges = \App\Services\TraefikConfigService::edgeServers()->pluck('ip')->filter()->all();

        return $zone && $edges && \App\Models\DnsRecord::allTenants()->where('dns_zone_id', $zone->id)->where('type', 'A')->where('name', $name)->whereIn('content', $edges)->exists();
    }

    /** XNODE-4: enterprise domains served by edges are published proxied. */
    public function edgeProxied(Domain $domain): bool
    {
        return \App\Services\TraefikConfigService::isEnterpriseDomain($domain) && \App\Services\TraefikConfigService::edgeServers()->isNotEmpty();
    }

    public function ingressIp(Domain $domain): string
    {
        $server = $domain->application_id ? Application::find($domain->application_id)?->server : null;

        return $server?->ip ?: config('platform.ingress_ip');
    }

    /** Checks each record; issues the certificate as soon as DNS verifies (UDOM-004). */
    public function verify(Domain $domain): array
    {
        $published = $this->publishToZone($domain);
        $records = [];
        foreach ($this->expectedRecords($domain) as $record) {
            $lookupName = ltrim($record['name'], '*.');
            $values = $domain->platform_subdomain ? [$record['value']] : $this->dns->lookup($lookupName, $record['type']);
            $ok = in_array(rtrim($record['value'], '.'), array_map(fn ($v) => rtrim($v, '.'), $values), true);
            if (! $ok && ! empty($record['proxied'])) {
                // XNODE-4: a proxied record resolves to Cloudflare, and the edge watchdog may have
                // taken this edge out: one edge record still in the managed zone is enough.
                $ok = $this->zoneHasEdgeRecord($domain, $record['name']);
                $values = $ok ? ['proxied'] : $values;
            }
            $records[] = $record + ['status' => $ok ? 'verified' : 'waiting', 'found' => $values];
        }
        // ACME-DLG: a missing delegation CNAME holds back a domain that has no certificate yet (its
        // router then appears with the CNAME in place, so Traefik orders at once), but never takes
        // a domain that is already serving a valid certificate offline.
        $live = $domain->https_status === 'active';
        $allOk = collect($records)->every(fn ($r) => $r['status'] === 'verified' || ($live && AcmeDelegation::isDelegationRecord($domain, $r)));
        $owned = collect($records)->first(fn ($r) => ($r['purpose'] ?? null) === 'ownership');
        if ($owned && $owned['status'] === 'verified' && ! $domain->ownership_verified_at) {
            $domain->update(['ownership_verified_at' => now()]);
            app(AuditLogger::class)->log('domain.ownership_verified', 'domains', $domain->id, ['domain' => $domain->name, 'project_id' => $domain->project_id], $domain->organization_id);
        }
        DnsCheck::create(['domain_id' => $domain->id, 'records' => $records, 'ok' => $allOk, 'created_at' => now()]);
        $wasVerified = $domain->dns_status === 'verified';
        $domain->update(['dns_status' => $allOk ? 'verified' : 'pending', 'dns_checked_at' => now()]);
        // A project domain's changes are told by DomainStateNotifier (domains:check-dns) instead.
        if ($allOk && ! $wasVerified && ! $domain->project_id) {
            app(\App\Services\EmailTemplateService::class)->notifyOrganization($domain->organization_id, 'domain_connected', [
                'domain' => $domain->name, 'app' => (string) (Application::find($domain->application_id)?->name ?? '—'),
            ]);
        }

        if ($allOk && $domain->https_status !== 'active') {
            $this->issueCertificate($domain);
        }

        return array_filter(['domain_id' => $domain->id, 'records' => array_map(fn ($r) => array_diff_key($r, ['found' => 1]), $records), 'dns_status' => $domain->dns_status,
            'https_status' => $domain->fresh()->https_status, 'zone_records' => $published ?: null], fn ($v) => $v !== null);
    }

    /**
     * RFC 8659: the closest CAA record set up the tree decides which CAs may issue. Returns why
     * Let's Encrypt may not issue for this domain, or null when it may (or no CAA records exist).
     */
    public function caaBlocks(Domain $domain): ?string
    {
        $ca = (string) config('platform.acme_caa', 'letsencrypt.org');
        $labels = explode('.', ltrim($domain->name, '*.'));
        for ($i = 0; $i < count($labels) - 1; $i++) {
            $name = implode('.', array_slice($labels, $i));
            $records = rescue(fn () => $this->dns->lookup($name, 'CAA'), [], report: false);
            if (! $records) {
                continue;
            }
            $tags = $domain->wildcard ? ['issuewild', 'issue'] : ['issue'];
            foreach ($tags as $tag) {
                $values = [];
                foreach ($records as $r) {
                    if (preg_match('/^\d+\s+(\S+)\s+"?([^";\s]*)/', trim($r), $m) && strtolower($m[1]) === $tag) {
                        $values[] = strtolower($m[2]);
                    }
                }
                if ($values) {
                    return in_array($ca, $values, true) ? null
                        : "The CAA record on {$name} only allows ".(implode(', ', array_filter($values)) ?: 'no one')." to issue certificates. Add: {$name} CAA 0 {$tag} \"{$ca}\"";
                }
            }

            return null;   // CAA exists but has no issue tags: anyone may issue
        }

        return null;
    }

    public function issueCertificate(Domain $domain): Certificate
    {
        if ($domain->dns_status !== 'verified') {
            throw ApiException::conflict('DNS is not pointing at the platform yet. Create the records shown and check again.');
        }
        if ($blocked = $this->caaBlocks($domain)) {
            $domain->update(['https_status' => 'failed']);
            $last = Certificate::where('domain_id', $domain->id)->latest()->first();
            if ($last && $last->status === 'failed' && $last->last_error === $blocked) {
                $last->touch();   // the re-check every 2 minutes does not pile up rows

                return $last;
            }

            return Certificate::create(['domain_id' => $domain->id, 'status' => 'failed', 'names' => [$domain->name], 'last_error' => $blocked]);
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

    /**
     * Renew certificates that expire within $days on domains whose DNS still points here.
     * A renewal that is pending (ACME) or fails keeps HTTPS active while the old certificate is valid.
     *
     * @return array{renewed: int, failed: int}
     */
    public function renewExpiring(int $days = 21, ?string $organizationId = null): array
    {
        $renewed = $failed = 0;
        $certs = Certificate::allTenants()->where('status', 'active')->whereNotNull('expires_at')->where('expires_at', '<', now()->addDays($days))
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))->get();
        foreach ($certs as $old) {
            $domain = Domain::allTenants()->find($old->domain_id);
            if (! $domain || $domain->dns_status !== 'verified') {
                continue;
            }
            app(\App\Support\Tenant::class)->as($domain->organization_id, function () use ($domain, $old, &$renewed, &$failed) {
                try {
                    $new = $this->issueCertificate($domain);
                } catch (\Throwable) {
                    $new = null;
                }
                $ok = $new && $new->status === 'active' && $new->id !== $old->id;
                $ok ? $renewed++ : $failed++;
                if (! $ok && $old->expires_at->isFuture()) {
                    $domain->update(['https_status' => 'active']);
                    // Tell the customer once a day while it keeps failing; the reason says what to fix.
                    $reason = $new?->last_error ? 'The last attempt failed: '.$new->last_error : 'Automatic renewal did not succeed. Check that DNS still points at the platform.';
                    app(\App\Services\EmailTemplateService::class)->notifyOrganization($domain->organization_id, 'certificate_expiring', [
                        'domain' => $domain->name, 'days' => (string) max(0, (int) now()->diffInDays($old->expires_at)), 'reason' => $reason,
                    ], $domain->id, 1440);
                }
                app(\App\Services\AuditLogger::class)->log($ok ? 'certificate.renewed' : 'certificate.renewal_failed', 'domains', $domain->id,
                    ['domain' => $domain->name, 'expires_at' => $old->expires_at->toIso8601ZuluString()], $domain->organization_id, null, 'system');
            });
        }

        return ['renewed' => $renewed, 'failed' => $failed];
    }

    public function toApi(Domain $domain): array
    {
        // Show the certificate that is serving traffic; a newer failed attempt only adds its error.
        $latest = $domain->certificate;
        $certificate = $latest?->status === 'failed'
            ? (Certificate::allTenants()->where('domain_id', $domain->id)->where('status', 'active')->latest()->first() ?? $latest)
            : $latest;
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
            'acme_delegation' => AcmeDelegation::zoneFor($domain) ? ['name' => AcmeDelegation::recordName($domain), 'type' => 'CNAME', 'target' => AcmeDelegation::target($domain)] : null,
            'dns_zone' => ($z = $domain->dns_secret_id ? null : \App\Models\DnsZone::forDomain($domain->name, $domain->organization_id)) ? ['id' => $z->id, 'name' => $z->name, 'provider' => $z->provider?->type] : null,
            'project_id' => $domain->project_id,
            'is_primary' => (bool) $domain->is_primary,
            'dns_method' => $domain->dns_method,
            'ownership_required' => (bool) $domain->ownership_token,
            'ownership_verified' => ! $domain->ownership_token || (bool) $domain->ownership_verified_at,
            'dns_status' => $domain->dns_status,
            'https_status' => $domain->https_status,
            'records' => $this->recordsWithStatus($domain),
            'certificate' => $certificate ? [
                'issuer' => $certificate->issuer, 'status' => $certificate->status,
                'expires_at' => $certificate->expires_at?->toIso8601ZuluString(),
                'days_left' => $certificate->expires_at ? (int) now()->diffInDays($certificate->expires_at, false) : null,
                'resolver' => $certificate->resolver, 'checked_at' => $certificate->checked_at?->toIso8601ZuluString(),
                'last_error' => $certificate->last_error,
            ] : null,
            // Why the latest issuance failed, so the panel can say what to fix.
            'last_error' => $latest?->status === 'failed' ? $latest->last_error : null,
            'renewal_at' => $certificate?->expires_at?->copy()->subDays(30)->toIso8601ZuluString(),
            'expiring' => $certificate?->expires_at && $certificate->expires_at->lt(now()->addDays(14)),
            'options' => $domain->options,
            'dns_checked_at' => $domain->dns_checked_at?->toIso8601ZuluString(),
            'created_at' => $domain->created_at?->toIso8601ZuluString(),
        ];
    }
}
