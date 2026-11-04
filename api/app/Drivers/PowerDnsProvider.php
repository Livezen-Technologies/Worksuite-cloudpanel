<?php

namespace App\Drivers;

use App\Contracts\DnsProvider;
use App\Exceptions\ApiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * PowerDNS Authoritative HTTP API (DNS-001). PowerDNS keeps record sets (one name
 * and type, many values) rather than records, so each value gets a stable id made
 * from its name, type and content, and a change rewrites the whole set.
 */
class PowerDnsProvider implements DnsProvider
{
    /** TTL used when the panel asks for Auto; PowerDNS has no automatic TTL. */
    public const AUTO_TTL = 3600;

    /** @param  string[]  $nameservers  the host names this PowerDNS answers on, set as NS for new zones */
    public function __construct(private string $url, private string $key, private string $server = 'localhost', private array $nameservers = []) {}

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->url, '/').'/api/v1/servers')
            ->withHeaders(['X-API-Key' => $this->key])->acceptJson()->timeout(20);
    }

    private function at(string $path): string
    {
        return '/'.rawurlencode($this->server).$path;
    }

    private function result(Response $response): mixed
    {
        if (! $response->successful()) {
            throw ApiException::upstream('PowerDNS: '.($response->json('error') ?: 'HTTP '.$response->status()));
        }

        return $response->json();
    }

    private static function fqdn(string $name): string
    {
        return rtrim($name, '.').'.';
    }

    public function verify(): string
    {
        $server = $this->result($this->http()->get($this->at('')));

        return 'PowerDNS '.($server['version'] ?? '').' ('.$this->server.')';
    }

    public function supportsProxy(): bool
    {
        return false;
    }

    /** PowerDNS answers for the zone at once; the panel marks it active once the delegation is seen. */
    private function zone(array $z): array
    {
        return ['id' => $z['id'], 'status' => 'pending', 'nameservers' => $this->nameservers];
    }

    public function createZone(string $name): array
    {
        return $this->zone($this->result($this->http()->post($this->at('/zones'), [
            'name' => self::fqdn($name), 'kind' => 'Native', 'soa_edit_api' => 'INCREASE',
            'nameservers' => array_map(self::fqdn(...), $this->nameservers),
        ])));
    }

    public function findZone(string $name): ?array
    {
        $zones = $this->result($this->http()->get($this->at('/zones'), ['zone' => self::fqdn($name)]));

        return $zones ? $this->zone($zones[0]) : null;
    }

    public function zoneStatus(string $zoneId): array
    {
        $ns = collect($this->rrsets($zoneId))->first(fn ($s) => $s['type'] === 'NS' && $s['name'] === $zoneId);
        $names = $ns ? array_map(fn ($r) => rtrim($r['content'], '.'), $ns['records']) : $this->nameservers;

        return ['status' => 'pending', 'nameservers' => array_values($names)];
    }

    public function deleteZone(string $zoneId): void
    {
        $this->result($this->http()->delete($this->at('/zones/'.rawurlencode($zoneId))));
    }

    private function rrsets(string $zoneId): array
    {
        return $this->result($this->http()->get($this->at('/zones/'.rawurlencode($zoneId))))['rrsets'] ?? [];
    }

    /** One panel record from one value of a record set. SOA is managed by PowerDNS and left out. */
    private function record(array $set, string $content): array
    {
        $type = $set['type'];
        $priority = null;
        if (in_array($type, ['MX', 'SRV'], true)) {
            [$priority, $content] = explode(' ', $content, 2) + [1 => ''];
            $priority = (int) $priority;
        }
        $content = match ($type) {
            'TXT' => stripcslashes(trim($content, '"')),
            'CNAME', 'NS', 'MX' => rtrim($content, '.'),
            'SRV' => preg_replace('/\.$/', '', $content),
            default => $content,
        };
        $name = rtrim($set['name'], '.');

        return [
            'id' => self::recordId($name, $type, $content, $priority), 'type' => $type, 'name' => $name, 'content' => $content,
            'ttl' => (int) ($set['ttl'] ?? self::AUTO_TTL), 'priority' => $priority, 'proxied' => false,
        ];
    }

    private static function recordId(string $name, string $type, string $content, ?int $priority): string
    {
        return substr(hash('sha256', strtolower($name)."\0".$type."\0".($priority ?? '')."\0".$content), 0, 32);
    }

    /** The panel record as PowerDNS zone-file content. */
    private static function content(array $r): string
    {
        $c = $r['content'];

        return match ($r['type']) {
            'TXT' => '"'.addcslashes($c, '"\\').'"',
            'CNAME', 'NS' => self::fqdn($c),
            'MX' => (int) ($r['priority'] ?? 10).' '.self::fqdn($c),
            'SRV' => (int) ($r['priority'] ?? 10).' '.preg_replace('/(\S+)$/', '$1.', rtrim($c, '.')),
            default => $c,
        };
    }

    public function records(string $zoneId): array
    {
        $out = [];
        foreach ($this->rrsets($zoneId) as $set) {
            if ($set['type'] === 'SOA') {
                continue;
            }
            foreach ($set['records'] ?? [] as $r) {
                $out[] = $this->record($set, $r['content']);
            }
        }

        return $out;
    }

    /** Rewrites the record sets touched by removing $old and/or adding $new, in one PATCH. */
    private function change(string $zoneId, ?array $old, ?array $new): void
    {
        $sets = collect($this->rrsets($zoneId))->keyBy(fn ($s) => $s['name'].' '.$s['type']);
        $changes = [];
        $values = function (string $key) use (&$changes, $sets) {
            return $changes[$key]['records'] ?? array_column($sets[$key]['records'] ?? [], 'content');
        };
        if ($old) {
            $key = self::fqdn($old['name']).' '.$old['type'];
            $remaining = array_values(array_filter($values($key), fn ($c) => $this->record(['name' => $old['name'], 'type' => $old['type']], $c)['id'] !== $old['id']));
            $changes[$key] = ['records' => $remaining, 'ttl' => $sets[$key]['ttl'] ?? self::AUTO_TTL];
        }
        if ($new) {
            $key = self::fqdn($new['name']).' '.$new['type'];
            $content = self::content($new);
            $current = $values($key);
            $changes[$key] = ['records' => in_array($content, $current, true) ? $current : [...$current, $content], 'ttl' => (int) $new['ttl'] === 1 ? self::AUTO_TTL : (int) $new['ttl']];
        }
        $rrsets = [];
        foreach ($changes as $key => $c) {
            [$name, $type] = explode(' ', $key);
            $rrsets[] = $c['records']
                ? ['name' => $name, 'type' => $type, 'ttl' => $c['ttl'], 'changetype' => 'REPLACE', 'records' => array_map(fn ($v) => ['content' => $v, 'disabled' => false], $c['records'])]
                : ['name' => $name, 'type' => $type, 'changetype' => 'DELETE'];
        }
        $this->result($this->http()->patch($this->at('/zones/'.rawurlencode($zoneId)), ['rrsets' => $rrsets]));
    }

    private function find(string $zoneId, string $recordId): array
    {
        foreach ($this->records($zoneId) as $r) {
            if ($r['id'] === $recordId) {
                return $r;
            }
        }
        throw ApiException::upstream('PowerDNS: the record no longer exists; sync the zone.');
    }

    private function written(array $record): array
    {
        $ttl = (int) $record['ttl'] === 1 ? self::AUTO_TTL : (int) $record['ttl'];
        $priority = in_array($record['type'], ['MX', 'SRV'], true) ? (int) ($record['priority'] ?? 10) : null;

        return ['id' => self::recordId($record['name'], $record['type'], $record['content'], $priority), 'type' => $record['type'], 'name' => $record['name'],
            'content' => $record['content'], 'ttl' => $ttl, 'priority' => $priority, 'proxied' => false];
    }

    public function createRecord(string $zoneId, array $record): array
    {
        $this->change($zoneId, null, $record);

        return $this->written($record);
    }

    public function updateRecord(string $zoneId, string $recordId, array $record): array
    {
        $this->change($zoneId, $this->find($zoneId, $recordId), $record);

        return $this->written($record);
    }

    public function deleteRecord(string $zoneId, string $recordId): void
    {
        $this->change($zoneId, $this->find($zoneId, $recordId), null);
    }

    /** PowerDNS signs as soon as an active key exists; the DS must then go to the registrar. */
    public function dnssec(string $zoneId): array
    {
        $keys = array_values(array_filter($this->result($this->http()->get($this->at('/zones/'.rawurlencode($zoneId)).'/cryptokeys')) ?? [], fn ($k) => $k['active'] ?? false));
        if (! $keys) {
            return ['status' => 'disabled', 'ds' => []];
        }
        $ds = [];
        foreach ($keys as $k) {
            foreach ($k['ds'] ?? [] as $line) {
                $ds[] = $zoneId.' IN DS '.$line;
            }
        }

        return ['status' => 'active', 'ds' => $ds];
    }

    public function setDnssec(string $zoneId, bool $enabled): array
    {
        $path = $this->at('/zones/'.rawurlencode($zoneId).'/cryptokeys');
        $keys = $this->result($this->http()->get($path)) ?? [];
        if ($enabled && ! array_filter($keys, fn ($k) => $k['active'] ?? false)) {
            $this->result($this->http()->post($path, ['keytype' => 'csk', 'active' => true, 'algorithm' => 'ECDSAP256SHA256']));
            $this->result($this->http()->put($this->at('/zones/'.rawurlencode($zoneId)).'/rectify'));
        }
        if (! $enabled) {
            foreach ($keys as $k) {
                $this->result($this->http()->delete($path.'/'.$k['id']));
            }
        }

        return $this->dnssec($zoneId);
    }

    public function settings(string $zoneId): array
    {
        throw ApiException::conflict('PowerDNS has no edge settings; SSL mode, HTTPS redirects and caching are Cloudflare features.');
    }

    public function updateSettings(string $zoneId, array $settings): array
    {
        return $this->settings($zoneId);
    }
}
