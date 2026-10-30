<?php

namespace App\Services;

use App\Contracts\DnsResolver;
use App\Exceptions\ApiException;
use App\Models\DnsProviderAccount;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use Illuminate\Support\Facades\DB;

/**
 * DNS zone management (SRS 4.18). Every change goes to the provider first and is
 * mirrored here only once the provider accepted it, so the panel never shows a
 * record that does not exist. Sync compares the mirror with the provider (DNS-012).
 */
class DnsZoneService
{
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA', 'NS'];

    public const TTLS = [1, 300, 3600, 86400];   // 1 = Auto

    public function __construct(private AuditLogger $audit, private DnsResolver $resolver) {}

    /** DNS-001: checks the credentials before they are stored. */
    public function connect(string $name, string $type, array $credentials): DnsProviderAccount
    {
        $account = new DnsProviderAccount(['name' => $name, 'type' => $type, 'credentials' => $credentials]);
        $account->account_label = $account->driver()->verify();
        $account->save();
        $this->audit->log('dns.provider_connected', 'dns_providers', $account->id, ['type' => $type, 'name' => $name]);

        return $account;
    }

    /**
     * DNS-003: creates the zone at the provider (or adopts it when it already exists there),
     * optionally with the platform's default records pointing at an ingress address.
     */
    public function createZone(DnsProviderAccount $account, string $name, ?string $defaultsTo = null): DnsZone
    {
        $name = strtolower(rtrim($name, '.'));
        if (DnsZone::where('name', $name)->exists()) {
            throw ApiException::conflict("{$name} is already a zone here.");
        }
        $driver = $account->driver();
        $remote = $driver->findZone($name) ?? $driver->createZone($name);
        $zone = DnsZone::create([
            'dns_provider_id' => $account->id, 'name' => $name, 'remote_id' => $remote['id'],
            'status' => $remote['status'], 'nameservers' => $remote['nameservers'],
        ]);
        if ($defaultsTo) {
            foreach ([['type' => 'A', 'name' => $name], ['type' => 'CNAME', 'name' => 'www.'.$name, 'content' => $name]] as $r) {
                $this->createRecord($zone, $r + ['content' => $defaultsTo, 'ttl' => 1]);
            }
        }
        $this->sync($zone);
        $this->audit->log('dns.zone_created', 'dns_zones', $zone->id, ['name' => $name, 'defaults' => (bool) $defaultsTo]);

        return $zone->fresh();
    }

    /** DNS-004: re-reads the zone status and checks the delegation from the public DNS. */
    public function checkNameservers(DnsZone $zone): array
    {
        $status = $zone->provider->driver()->zoneStatus($zone->remote_id);
        $expected = array_map('strtolower', $status['nameservers']);
        $found = array_map(fn ($ns) => strtolower(rtrim((string) $ns, '.')), $this->resolver->lookup($zone->name, 'NS'));
        $delegated = $expected && ! array_diff($expected, $found);
        $zone->update(['status' => $status['status'] === 'active' || $delegated ? 'active' : 'pending', 'nameservers' => $status['nameservers']]);

        return ['status' => $zone->status, 'expected' => $status['nameservers'], 'found' => array_values($found), 'delegated' => $delegated];
    }

    /** DNS-005/006/007: validation shared by create and update. Returns the record to send. */
    public function validateRecord(DnsZone $zone, array $r): array
    {
        $type = strtoupper((string) ($r['type'] ?? ''));
        $name = strtolower(rtrim(trim((string) ($r['name'] ?? '')), '.'));
        $name = $name === '' || $name === '@' ? $zone->name : (str_ends_with($name, '.'.$zone->name) || $name === $zone->name ? $name : $name.'.'.$zone->name);
        $content = trim((string) ($r['content'] ?? ''));
        $ttl = (int) ($r['ttl'] ?? 1);
        $errors = [];
        if (! in_array($type, self::TYPES, true)) {
            $errors['type'][] = 'Use one of '.implode(', ', self::TYPES).'.';
        }
        if (! in_array($ttl, self::TTLS, true)) {
            $errors['ttl'][] = 'TTL must be Auto, 300, 3600 or 86400.';
        }
        if (! preg_match('/^(\*\.)?([a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?\.)*[a-z0-9-]+$/', $name)) {
            $errors['name'][] = 'Not a valid host name.';
        }
        $ok = match ($type) {
            'A' => filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
            'AAAA' => filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
            'CNAME', 'NS', 'MX' => (bool) preg_match('/^([a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\.?$/i', $content),
            'TXT' => $content !== '' && strlen($content) <= 2048,
            'SRV' => (bool) preg_match('/^\d{1,5} \d{1,5} \S+$/', $content),
            'CAA' => (bool) preg_match('/^\d{1,3} (issue|issuewild|iodef) "?[^"]+"?$/', $content),
            default => false,
        };
        if (! $ok && ! isset($errors['type'])) {
            $errors['content'][] = match ($type) {
                'A' => 'An A record needs an IPv4 address.',
                'AAAA' => 'An AAAA record needs an IPv6 address.',
                'SRV' => 'Use "weight port target", with priority set separately.',
                'CAA' => 'Use "flags tag value", for example 0 issue "letsencrypt.org".',
                'TXT' => 'Text must be 1–2048 characters.',
                default => 'Needs a host name.',
            };
        }
        if ($type === 'CNAME' && $name === $zone->name) {
            $errors['name'][] = 'A CNAME cannot sit at the zone apex; use A or AAAA.';
        }
        $proxied = (bool) ($r['proxied'] ?? false);
        if ($proxied && ! $zone->provider->driver()->supportsProxy()) {
            $errors['proxied'][] = 'This provider has no proxy; turn it off.';
        } elseif ($proxied && ! in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
            $errors['proxied'][] = 'Only A, AAAA and CNAME records can be proxied.';
        }
        if ($errors) {
            throw ApiException::validation($errors);
        }

        return [
            'type' => $type, 'name' => $name, 'content' => rtrim($content, $type === 'TXT' ? '' : '.'),
            'ttl' => $proxied ? 1 : $ttl, 'priority' => in_array($type, ['MX', 'SRV'], true) ? (int) ($r['priority'] ?? 10) : null,
            'proxied' => $proxied,
        ];
    }

    public function createRecord(DnsZone $zone, array $input): DnsRecord
    {
        $record = $this->validateRecord($zone, $input);
        $remote = $zone->provider->driver()->createRecord($zone->remote_id, $record);
        $row = $zone->records()->create($this->row($zone, $remote));
        $zone->update(['records_count' => $zone->records()->count()]);
        $this->audit->log('dns.record_created', 'dns_zones', $zone->id, ['record' => $record]);

        return $row;
    }

    public function updateRecord(DnsZone $zone, DnsRecord $row, array $input): DnsRecord
    {
        $record = $this->validateRecord($zone, $input + ['type' => $row->type]);
        $remote = $zone->provider->driver()->updateRecord($zone->remote_id, $row->remote_id, $record);
        $row->update($this->row($zone, $remote));
        $this->audit->log('dns.record_updated', 'dns_zones', $zone->id, ['record' => $record]);

        return $row->fresh();
    }

    public function deleteRecord(DnsZone $zone, DnsRecord $row): void
    {
        $zone->provider->driver()->deleteRecord($zone->remote_id, $row->remote_id);
        $row->delete();
        $zone->update(['records_count' => $zone->records()->count()]);
        $this->audit->log('dns.record_deleted', 'dns_zones', $zone->id, ['record' => ['type' => $row->type, 'name' => $row->name, 'content' => $row->content]]);
    }

    /** DNS-008: reads the provider's DNSSEC state and keeps the DS records to hand to the registrar. */
    public function dnssec(DnsZone $zone): array
    {
        return $this->keepDnssec($zone, $zone->provider->driver()->dnssec($zone->remote_id));
    }

    public function setDnssec(DnsZone $zone, bool $enabled): array
    {
        $state = $this->keepDnssec($zone, $zone->provider->driver()->setDnssec($zone->remote_id, $enabled));
        $this->audit->log($enabled ? 'dns.dnssec_enabled' : 'dns.dnssec_disabled', 'dns_zones', $zone->id, ['name' => $zone->name, 'status' => $state['status']]);

        return $state;
    }

    private function keepDnssec(DnsZone $zone, array $state): array
    {
        $zone->update(['dnssec_status' => $state['status'], 'ds_records' => $state['ds']]);

        return $state;
    }

    /** DNS-012: compares the mirror with the provider, reports drift, then adopts the provider's state. */
    public function sync(DnsZone $zone): array
    {
        $remote = collect($zone->provider->driver()->records($zone->remote_id))->keyBy('id');
        $local = $zone->records()->get()->keyBy('remote_id');
        $describe = fn ($r) => ['type' => $r['type'], 'name' => $r['name'], 'content' => $r['content']];
        $drift = [
            'added' => $remote->diffKeys($local)->map($describe)->values()->all(),
            'removed' => $local->diffKeys($remote)->map(fn ($r) => $describe($r->toArray()))->values()->all(),
            'changed' => $remote->intersectByKeys($local)->filter(function ($r, $id) use ($local) {
                $l = $local[$id];

                return $l->content !== $r['content'] || $l->ttl !== (int) $r['ttl'] || $l->proxied !== (bool) $r['proxied'] || $l->name !== $r['name'];
            })->map($describe)->values()->all(),
        ];
        DB::transaction(function () use ($zone, $remote) {
            $zone->records()->delete();
            foreach ($remote as $r) {
                $zone->records()->create($this->row($zone, $r));
            }
            $zone->update(['records_count' => $remote->count(), 'last_synced_at' => now()]);
        });

        return $drift + ['in_sync' => ! array_filter($drift)];
    }

    private function row(DnsZone $zone, array $r): array
    {
        return [
            'organization_id' => $zone->organization_id, 'remote_id' => $r['id'], 'type' => $r['type'], 'name' => $r['name'], 'content' => $r['content'],
            'ttl' => (int) $r['ttl'], 'priority' => $r['priority'] ?? null, 'proxied' => (bool) ($r['proxied'] ?? false),
        ];
    }
}
