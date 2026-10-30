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

    private array $settings = ['ssl' => 'flexible', 'always_use_https' => 'off', 'min_tls_version' => '1.0', 'cache_level' => 'aggressive'];

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
                $path === '/zones/z1/settings' && $request->method() === 'PATCH' => (function () use ($request, $ok) {
                    foreach ($request->data()['items'] as $item) {
                        $this->settings[$item['id']] = $item['value'];
                    }

                    return $ok([]);
                })(),
                $path === '/zones/z1/settings' => $ok(collect($this->settings)->map(fn ($v, $k) => ['id' => $k, 'value' => $v])->values()->all()),
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

    public function test_zone_settings_read_and_update_at_cloudflare(): void
    {
        [$token, $provider] = $this->connected();
        $zone = $this->zone($token, $provider['id']);
        $this->assertTrue($zone['supports_settings']);
        $url = "/api/v1/dns/zones/{$zone['id']}/settings";
        $this->api($token)->getJson($url)->assertOk()->assertJsonPath('data', ['ssl' => 'flexible', 'always_use_https' => false, 'min_tls_version' => '1.0', 'cache_level' => 'aggressive']);

        $this->api($token)->patchJson($url, ['ssl' => 'strict', 'always_use_https' => true, 'min_tls_version' => '1.2'])->assertOk()
            ->assertJsonPath('data.ssl', 'strict')->assertJsonPath('data.always_use_https', true)->assertJsonPath('data.cache_level', 'aggressive');
        $this->assertSame('on', $this->settings['always_use_https']);
        $log = AuditLog::where('action', 'dns.settings_updated')->firstOrFail();
        $this->assertSame(['ssl' => 'flexible', 'always_use_https' => false, 'min_tls_version' => '1.0'], $log->meta['from']);

        $this->api($token)->patchJson($url, ['ssl' => 'none', 'min_tls_version' => '1.4'])->assertStatus(422);
    }

    public function test_export_writes_a_bind_file_and_import_reads_it_back(): void
    {
        [$token, $provider] = $this->connected();
        $zone = $this->zone($token, $provider['id'], ['defaults_to' => '203.0.113.10']);
        $url = "/api/v1/dns/zones/{$zone['id']}/records";
        $this->api($token)->postJson($url, ['type' => 'MX', 'name' => '@', 'content' => 'mail.example.com', 'priority' => 10, 'ttl' => 3600])->assertCreated();
        $this->api($token)->postJson($url, ['type' => 'TXT', 'name' => '@', 'content' => 'v=spf1 include:"x" -all'])->assertCreated();

        $export = $this->api($token)->withHeaders(['Accept' => 'text/dns'])->get("/api/v1/dns/zones/{$zone['id']}/export")->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="example.com.zone"')->getContent();
        $this->assertSame($export, $this->api($token)->getJson("/api/v1/dns/zones/{$zone['id']}/export")->assertOk()->json('data.content'));
        $this->assertStringContainsString('$ORIGIN example.com.', $export);
        $this->assertMatchesRegularExpression('/^@\s+3600 IN MX\s+10 mail\.example\.com\.$/m', $export);
        $this->assertMatchesRegularExpression('/^www\s+300 IN CNAME example\.com\.$/m', $export);
        $this->assertStringContainsString('"v=spf1 include:\"x\" -all"', $export);

        // Re-importing the export changes nothing.
        $again = $this->api($token)->postJson("/api/v1/dns/zones/{$zone['id']}/import", ['zone_file' => $export])->assertOk()->json('data');
        $this->assertSame([], $again['created']);
        $this->assertSame(4, $again['unchanged']);
    }

    public function test_import_adds_new_records_and_reports_every_skipped_line(): void
    {
        [$token, $provider] = $this->connected();
        $zone = $this->zone($token, $provider['id']);
        $file = <<<'ZONE'
        $ORIGIN example.com.
        $TTL 1h
        @   IN SOA ns1.other.net. admin.example.com. (
                2024010101 ; serial
                7200 3600 1209600 300 )
        @        IN A     192.0.2.1
                 IN AAAA  2001:db8::1   ; same name as the line above
        www  300 IN CNAME @
        mail     IN MX    5 mx1.example.net.
        _sip._tcp 86400 IN SRV 10 60 5060 sip
        @        IN TXT   "part one " "part two"
        @        IN CAA   0 issue "letsencrypt.org"
        @        IN CNAME other.net.
        ftp      IN HINFO "x" "y"
        other.org. IN A 192.0.2.9
        bad      IN A     999.1.1.1
        ZONE;
        $url = "/api/v1/dns/zones/{$zone['id']}/import";

        $dry = $this->api($token)->postJson($url, ['zone_file' => $file, 'dry_run' => true])->assertOk()->json('data');
        $this->assertTrue($dry['dry_run']);
        $this->assertCount(7, $dry['created']);
        $this->assertSame([], $this->remote);

        $result = $this->api($token)->postJson($url, ['zone_file' => $file])->assertOk()->json('data');
        $this->assertCount(7, $result['created']);
        $this->assertSame([13, 14, 15, 16], array_column($result['skipped'], 'line'));
        $this->assertStringContainsString('apex', $result['skipped'][0]['reason']);
        $this->assertStringContainsString('HINFO', $result['skipped'][1]['reason']);
        $this->assertStringContainsString('outside', $result['skipped'][2]['reason']);
        $this->assertStringContainsString('IPv4', $result['skipped'][3]['reason']);

        $by = collect($this->remote)->keyBy(fn ($r) => $r['type']);
        $this->assertSame('example.com', $by['AAAA']['name']);
        $this->assertSame(3600, $by['A']['ttl']);
        $this->assertSame(['mail.example.com', 'mx1.example.net', 5], [$by['MX']['name'], $by['MX']['content'], $by['MX']['priority']]);
        $this->assertSame(['_sip._tcp.example.com', '60 5060 sip.example.com', 10, 86400], [$by['SRV']['name'], $by['SRV']['content'], $by['SRV']['priority'], $by['SRV']['ttl']]);
        $this->assertSame('part one part two', $by['TXT']['content']);
        $this->assertSame('0 issue "letsencrypt.org"', $by['CAA']['content']);
        $this->assertSame('example.com', $by['CNAME']['content']);
        $this->assertTrue(AuditLog::where('action', 'dns.zone_imported')->exists());
    }
}
