<?php

namespace Tests\Feature;

use App\Contracts\DnsResolver;
use App\Models\AuditLog;
use App\Models\DnsProviderAccount;
use App\Models\DnsZone;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** SRS 4.18: Cloudflare accounts, zones, records and sync, against a fake Cloudflare API. */
class DnsZoneTest extends TestCase
{
    /** @var array<string, array> remote records by id */
    private array $remote = [];

    private int $next = 1;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(function (Request $request) {
            $path = substr(parse_url($request->url(), PHP_URL_PATH), strlen('/client/v4'));
            $ok = fn ($result, $extra = []) => Http::response(['success' => true, 'errors' => [], 'result' => $result] + $extra);
            if ($request->header('Authorization')[0] !== 'Bearer good-token') {
                return Http::response(['success' => false, 'errors' => [['code' => 1000, 'message' => 'Invalid API Token']]], 403);
            }

            return match (true) {
                $path === '/user/tokens/verify' => $ok(['id' => 'abcdef1234567890', 'status' => 'active']),
                $path === '/zones' && $request->method() === 'GET' => $ok([]),
                $path === '/zones' => $ok(['id' => 'z1', 'status' => 'pending', 'name_servers' => ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com']]),
                $path === '/zones/z1' => $ok(['id' => 'z1', 'status' => 'pending', 'name_servers' => ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com']]),
                $path === '/zones/z1/dns_records' && $request->method() === 'GET' => $ok(array_values($this->remote), ['result_info' => ['total_pages' => 1]]),
                $path === '/zones/z1/dns_records' => $ok($this->remote[$id = 'r'.$this->next++] = ['id' => $id] + $request->data()),
                str_starts_with($path, '/zones/z1/dns_records/') && $request->method() === 'PUT' => $ok($this->remote[basename($path)] = ['id' => basename($path)] + $request->data()),
                str_starts_with($path, '/zones/z1/dns_records/') && $request->method() === 'DELETE' => (function () use ($path, $ok) {
                    unset($this->remote[basename($path)]);

                    return $ok(['id' => basename($path)]);
                })(),
                default => Http::response(['success' => false, 'errors' => [['message' => 'not found']]], 404),
            };
        });
    }

    private function connected(): array
    {
        [$user, $org, $token] = $this->org();
        $provider = $this->api($token)->postJson('/api/v1/dns/providers', ['name' => 'Main', 'type' => 'cloudflare', 'token' => 'good-token'])
            ->assertCreated()->json('data');

        return [$token, $provider, $org];
    }

    private function zone(string $token, string $providerId, array $extra = []): array
    {
        return $this->api($token)->postJson('/api/v1/dns/zones', ['dns_provider_id' => $providerId, 'name' => 'example.com'] + $extra)->assertCreated()->json('data');
    }

    public function test_connecting_verifies_the_token_and_never_returns_it(): void
    {
        [$token, $provider] = $this->connected();
        $this->assertSame('Cloudflare token abcdef12', $provider['account']);
        $this->assertArrayNotHasKey('credentials', $provider);
        $this->assertSame('good-token', DnsProviderAccount::allTenants()->first()->credentials['token']);
        $this->assertStringNotContainsString('good-token', $this->api($token)->getJson('/api/v1/dns/providers')->getContent());

        $this->api($token)->postJson('/api/v1/dns/providers', ['name' => 'Bad', 'type' => 'cloudflare', 'token' => 'bad'])
            ->assertStatus(502)->assertJsonPath('error.message', 'Cloudflare: Invalid API Token');
        $this->assertSame(1, DnsProviderAccount::allTenants()->count());
    }

    public function test_a_zone_with_defaults_gets_an_apex_and_www_record(): void
    {
        [$token, $provider] = $this->connected();
        $zone = $this->zone($token, $provider['id'], ['defaults_to' => '203.0.113.10']);
        $this->assertSame('pending', $zone['status']);
        $this->assertSame(['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'], $zone['nameservers']);
        $this->assertSame(2, $zone['records_count']);
        $records = collect($this->api($token)->getJson("/api/v1/dns/zones/{$zone['id']}")->assertOk()->json('data.records'));
        $this->assertEqualsCanonicalizing(['A example.com 203.0.113.10', 'CNAME www.example.com example.com'], $records->map(fn ($r) => "{$r['type']} {$r['name']} {$r['content']}")->all());
        $this->assertTrue(AuditLog::where('action', 'dns.zone_created')->exists());

        $this->api($token)->postJson('/api/v1/dns/zones', ['dns_provider_id' => $provider['id'], 'name' => 'example.com'])->assertStatus(409);
    }

    public function test_records_are_validated_and_relative_names_join_the_zone(): void
    {
        [$token, $provider] = $this->connected();
        $zone = $this->zone($token, $provider['id']);
        $url = "/api/v1/dns/zones/{$zone['id']}/records";

        $this->api($token)->postJson($url, ['type' => 'A', 'name' => 'app', 'content' => 'not-an-ip'])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['content']]]);
        $this->api($token)->postJson($url, ['type' => 'CNAME', 'name' => '@', 'content' => 'other.net'])->assertStatus(422);
        $this->api($token)->postJson($url, ['type' => 'TXT', 'name' => 'x', 'content' => 'hi', 'proxied' => true])->assertStatus(422);
        $this->api($token)->postJson($url, ['type' => 'A', 'name' => 'app', 'content' => '1.2.3.4', 'ttl' => 42])->assertStatus(422);

        $mx = $this->api($token)->postJson($url, ['type' => 'MX', 'name' => '@', 'content' => 'mail.example.com', 'priority' => 5, 'ttl' => 3600])->assertCreated()->json('data');
        $this->assertSame(['example.com', 5, 3600], [$mx['name'], $mx['priority'], $mx['ttl']]);

        // Proxied records are always Auto TTL.
        $a = $this->api($token)->postJson($url, ['type' => 'A', 'name' => 'app', 'content' => '1.2.3.4', 'ttl' => 300, 'proxied' => true])->assertCreated()->json('data');
        $this->assertSame(['app.example.com', 1, true], [$a['name'], $a['ttl'], $a['proxied']]);

        $this->api($token)->putJson("{$url}/{$a['id']}", ['name' => 'app', 'content' => '5.6.7.8', 'ttl' => 300])->assertOk()->assertJsonPath('data.content', '5.6.7.8')->assertJsonPath('data.ttl', 300);
        $this->assertSame('5.6.7.8', collect($this->remote)->firstWhere('name', 'app.example.com')['content']);

        $this->api($token)->deleteJson("{$url}/{$a['id']}")->assertNoContent();
        $this->assertCount(1, $this->remote);
        $this->assertSame(1, DnsZone::allTenants()->first()->records_count);
    }

    public function test_sync_reports_drift_and_adopts_the_providers_records(): void
    {
        [$token, $provider] = $this->connected();
        $zone = $this->zone($token, $provider['id'], ['defaults_to' => '203.0.113.10']);
        $sync = "/api/v1/dns/zones/{$zone['id']}/sync";
        $this->api($token)->postJson($sync)->assertOk()->assertJsonPath('data.in_sync', true);

        // Someone edits the zone in Cloudflare's dashboard.
        $apex = collect($this->remote)->firstWhere('type', 'A')['id'];
        $this->remote[$apex]['content'] = '198.51.100.1';
        $this->remote['r99'] = ['id' => 'r99', 'type' => 'TXT', 'name' => 'example.com', 'content' => 'v=spf1 -all', 'ttl' => 1];
        unset($this->remote[collect($this->remote)->firstWhere('type', 'CNAME')['id']]);

        $drift = $this->api($token)->postJson($sync)->assertOk()->json('data');
        $this->assertFalse($drift['in_sync']);
        $this->assertSame('TXT', $drift['added'][0]['type']);
        $this->assertSame('CNAME', $drift['removed'][0]['type']);
        $this->assertSame('198.51.100.1', $drift['changed'][0]['content']);
        $this->assertSame(2, $this->api($token)->getJson("/api/v1/dns/zones/{$zone['id']}")->json('data.records_count'));
    }

    public function test_nameserver_check_detects_delegation(): void
    {
        [$token, $provider] = $this->connected();
        $zone = $this->zone($token, $provider['id']);
        $url = "/api/v1/dns/zones/{$zone['id']}/check-nameservers";
        $this->api($token)->postJson($url)->assertOk()->assertJsonPath('data.delegated', false)->assertJsonPath('data.status', 'pending');

        app(DnsResolver::class)->set('example.com', 'NS', ['ada.ns.cloudflare.com.', 'bob.ns.cloudflare.com.']);
        $this->api($token)->postJson($url)->assertOk()->assertJsonPath('data.delegated', true)->assertJsonPath('data.status', 'active');
    }

    public function test_zones_and_providers_are_tenant_scoped(): void
    {
        [$token, $provider] = $this->connected();
        $zone = $this->zone($token, $provider['id']);
        [, , $other] = $this->org();

        $this->api($other)->getJson('/api/v1/dns/zones')->assertOk()->assertJsonCount(0, 'data');
        $this->api($other)->getJson("/api/v1/dns/zones/{$zone['id']}")->assertNotFound();
        $this->api($other)->postJson('/api/v1/dns/zones', ['dns_provider_id' => $provider['id'], 'name' => 'evil.com'])->assertNotFound();
        $this->api($other)->deleteJson("/api/v1/dns/providers/{$provider['id']}")->assertNotFound();

        $this->api($token)->deleteJson("/api/v1/dns/providers/{$provider['id']}")->assertStatus(409);
        $this->api($token)->deleteJson("/api/v1/dns/zones/{$zone['id']}")->assertNoContent();
        $this->api($token)->deleteJson("/api/v1/dns/providers/{$provider['id']}")->assertNoContent();
    }
}
