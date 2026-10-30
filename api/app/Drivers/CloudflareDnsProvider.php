<?php

namespace App\Drivers;

use App\Contracts\DnsProvider;
use App\Exceptions\ApiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Cloudflare API v4 with an API token scoped to Zone:Read, Zone:Edit and DNS:Edit (DNS-001). */
class CloudflareDnsProvider implements DnsProvider
{
    public const BASE = 'https://api.cloudflare.com/client/v4';

    public function __construct(private string $token, private ?string $accountId = null) {}

    private function http(): PendingRequest
    {
        return Http::baseUrl(self::BASE)->withToken($this->token)->acceptJson()->timeout(20);
    }

    /** Cloudflare answers {success, errors[{code, message}], result}; turn a failure into a readable error. */
    private function result(Response $response): mixed
    {
        $body = $response->json() ?? [];
        if (! $response->successful() || ! ($body['success'] ?? false)) {
            $message = collect($body['errors'] ?? [])->map(fn ($e) => $e['message'] ?? '')->filter()->implode('; ');
            throw ApiException::upstream('Cloudflare: '.($message ?: 'HTTP '.$response->status()));
        }

        return $body['result'] ?? null;
    }

    public function verify(): string
    {
        $token = $this->result($this->http()->get('/user/tokens/verify'));
        if (($token['status'] ?? '') !== 'active') {
            throw ApiException::upstream('Cloudflare: the API token is not active.');
        }

        return 'Cloudflare token '.substr((string) ($token['id'] ?? ''), 0, 8);
    }

    private function zone(array $z): array
    {
        return ['id' => $z['id'], 'status' => $z['status'] === 'active' ? 'active' : 'pending', 'nameservers' => array_values($z['name_servers'] ?? [])];
    }

    public function createZone(string $name): array
    {
        $body = ['name' => $name, 'type' => 'full'];
        if ($this->accountId) {
            $body['account'] = ['id' => $this->accountId];
        }

        return $this->zone($this->result($this->http()->post('/zones', $body)));
    }

    public function findZone(string $name): ?array
    {
        $zones = $this->result($this->http()->get('/zones', ['name' => $name]));

        return $zones ? $this->zone($zones[0]) : null;
    }

    public function zoneStatus(string $zoneId): array
    {
        $z = $this->zone($this->result($this->http()->get("/zones/{$zoneId}")));

        return ['status' => $z['status'], 'nameservers' => $z['nameservers']];
    }

    public function deleteZone(string $zoneId): void
    {
        $this->result($this->http()->delete("/zones/{$zoneId}"));
    }

    private function record(array $r): array
    {
        return [
            'id' => $r['id'], 'type' => $r['type'], 'name' => $r['name'], 'content' => $r['content'],
            'ttl' => (int) ($r['ttl'] ?? 1), 'priority' => $r['priority'] ?? null, 'proxied' => (bool) ($r['proxied'] ?? false),
        ];
    }

    private function body(array $record): array
    {
        $body = ['type' => $record['type'], 'name' => $record['name'], 'content' => $record['content'], 'ttl' => (int) ($record['ttl'] ?? 1)];
        if (in_array($record['type'], ['A', 'AAAA', 'CNAME'], true)) {
            $body['proxied'] = (bool) ($record['proxied'] ?? false);
        }
        if (in_array($record['type'], ['MX', 'SRV'], true)) {
            $body['priority'] = (int) ($record['priority'] ?? 10);
        }

        return $body;
    }

    public function records(string $zoneId): array
    {
        $out = [];
        for ($page = 1; $page <= 50; $page++) {
            $response = $this->http()->get("/zones/{$zoneId}/dns_records", ['page' => $page, 'per_page' => 500]);
            $batch = $this->result($response) ?? [];
            foreach ($batch as $r) {
                $out[] = $this->record($r);
            }
            if ($page >= (int) ($response->json('result_info.total_pages') ?? 1)) {
                break;
            }
        }

        return $out;
    }

    public function createRecord(string $zoneId, array $record): array
    {
        return $this->record($this->result($this->http()->post("/zones/{$zoneId}/dns_records", $this->body($record))));
    }

    public function updateRecord(string $zoneId, string $recordId, array $record): array
    {
        return $this->record($this->result($this->http()->put("/zones/{$zoneId}/dns_records/{$recordId}", $this->body($record))));
    }

    public function deleteRecord(string $zoneId, string $recordId): void
    {
        $this->result($this->http()->delete("/zones/{$zoneId}/dns_records/{$recordId}"));
    }
}
