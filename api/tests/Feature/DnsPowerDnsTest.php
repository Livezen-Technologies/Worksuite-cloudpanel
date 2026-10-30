<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** SRS 4.18: PowerDNS as a provider and DNSSEC (DNS-008), against fake PowerDNS and Cloudflare APIs. */
class DnsPowerDnsTest extends TestCase
{
    private const API = 'https://pdns.example.net:8081/api/v1/servers/localhost';

    /** @var array<string, array> rrsets by "name type" */
    private array $sets = [];

    private array $keys = [];

    private array $patches = [];

    private string $cfDnssec = 'disabled';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), 'https://api.cloudflare.com/')) {
                return $this->cloudflare($request);
            }
            if (($request->header('X-API-Key')[0] ?? '') !== 'pdns-key') {
                return Http::response(['error' => 'Unauthorized'], 401);
            }
            $path = substr(strtok($request->url(), '?'), strlen(self::API));
            $method = $request->method();

            return match (true) {
                $path === '' => Http::response(['id' => 'localhost', 'version' => '4.9.4']),
                $path === '/zones' && $method === 'GET' => Http::response([]),
                $path === '/zones' => Http::response(['id' => 'example.org.', 'name' => 'example.org.'], 201),
                $path === '/zones/example.org.' && $method === 'GET' => Http::response(['id' => 'example.org.', 'rrsets' => array_values($this->sets)]),
                $path === '/zones/example.org.' && $method === 'PATCH' => $this->applyPatch($request),
                $path === '/zones/example.org./cryptokeys' && $method === 'GET' => Http::response(array_values($this->keys)),
                $path === '/zones/example.org./cryptokeys' => Http::response($this->keys[7] = ['id' => 7, 'active' => true, 'keytype' => 'csk', 'ds' => ['2371 13 2 abcdef']], 201),
                str_starts_with($path, '/zones/example.org./cryptokeys/') => (function () use ($path) {
                    unset($this->keys[(int) basename($path)]);

                    return Http::response(null, 204);
                })(),
                $path === '/zones/example.org./rectify' => Http::response(['result' => 'Rectified']),
                default => Http::response(['error' => 'Not Found'], 404),
            };
        });
        $this->sets['example.org. SOA'] = ['name' => 'example.org.', 'type' => 'SOA', 'ttl' => 3600, 'records' => [['content' => 'ns1.example.net. hostmaster.example.org. 1 10800 3600 604800 3600']]];
        $this->sets['example.org. NS'] = ['name' => 'example.org.', 'type' => 'NS', 'ttl' => 3600, 'records' => [['content' => 'ns1.example.net.'], ['content' => 'ns2.example.net.']]];
    }

    private function applyPatch(Request $request)
    {
        foreach ($request->data()['rrsets'] as $set) {
            $this->patches[] = $set;
            $key = $set['name'].' '.$set['type'];
            if ($set['changetype'] === 'DELETE') {
                unset($this->sets[$key]);
            } else {
                $this->sets[$key] = ['name' => $set['name'], 'type' => $set['type'], 'ttl' => $set['ttl'], 'records' => $set['records']];
            }
        }

        return Http::response(null, 204);
    }

    private function cloudflare(Request $request)
    {
        $path = substr(parse_url($request->url(), PHP_URL_PATH), strlen('/client/v4'));
        $ok = fn ($r) => Http::response(['success' => true, 'errors' => [], 'result' => $r]);
        if ($path === '/zones/z1/dnssec' && $request->method() === 'PATCH') {
            $this->cfDnssec = $request->data()['status'] === 'active' ? 'pending' : 'disabled';
        }

        return match (true) {
            $path === '/user/tokens/verify' => $ok(['id' => 'abcdef1234567890', 'status' => 'active']),
            $path === '/zones' && $request->method() === 'GET' => $ok([]),
            $path === '/zones' => $ok(['id' => 'z1', 'status' => 'pending', 'name_servers' => ['ada.ns.cloudflare.com']]),
            $path === '/zones/z1/dns_records' => $ok([]),
            $path === '/zones/z1/dnssec' => $ok(['status' => $this->cfDnssec, 'ds' => $this->cfDnssec === 'disabled' ? null : 'example.com. 3600 IN DS 2371 13 2 ffff']),
            default => Http::response(['success' => false, 'errors' => [['message' => 'not found']]], 404),
        };
    }

    private function powerdnsZone(): array
    {
        [, , $token] = $this->org();
        $provider = $this->api($token)->postJson('/api/v1/dns/providers', [
            'name' => 'Own DNS', 'type' => 'powerdns', 'api_url' => 'https://pdns.example.net:8081', 'api_key' => 'pdns-key',
            'nameservers' => ['ns1.example.net', 'ns2.example.net.'],
        ])->assertCreated()->json('data');
        $zone = $this->api($token)->postJson('/api/v1/dns/zones', ['dns_provider_id' => $provider['id'], 'name' => 'example.org'])->assertCreated()->json('data');

        return [$token, $provider, $zone];
    }

    public function test_powerdns_connects_and_creates_a_zone_with_its_nameservers(): void
    {
        [$token, $provider, $zone] = $this->powerdnsZone();
        $this->assertSame('PowerDNS 4.9.4 (localhost)', $provider['account']);
        $this->assertSame(['ns1.example.net', 'ns2.example.net'], $zone['nameservers']);
        $this->assertFalse($zone['supports_proxy']);
        // The apex NS set is listed; SOA is PowerDNS's own and left out.
        $this->assertSame(2, $zone['records_count']);

        $this->api($token)->postJson('/api/v1/dns/providers', ['name' => 'x', 'type' => 'powerdns', 'api_url' => 'https://pdns.example.net:8081', 'api_key' => 'wrong', 'nameservers' => ['ns1.example.net']])
            ->assertStatus(502)->assertJsonPath('error.message', 'PowerDNS: Unauthorized');
        $this->api($token)->postJson('/api/v1/dns/providers', ['name' => 'x', 'type' => 'powerdns', 'api_url' => 'not a url', 'api_key' => 'k'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['api_url', 'nameservers']]]);
    }

    public function test_powerdns_records_are_written_as_record_sets(): void
    {
        [$token, , $zone] = $this->powerdnsZone();
        $url = "/api/v1/dns/zones/{$zone['id']}/records";

        $a1 = $this->api($token)->postJson($url, ['type' => 'A', 'name' => 'app', 'content' => '192.0.2.1'])->assertCreated()->json('data');
        $this->assertSame(3600, $a1['ttl']);   // Auto becomes an hour: PowerDNS has no automatic TTL
        $this->api($token)->postJson($url, ['type' => 'A', 'name' => 'app', 'content' => '192.0.2.2', 'ttl' => 300])->assertCreated();
        $this->assertSame(['192.0.2.1', '192.0.2.2'], array_column($this->sets['app.example.org. A']['records'], 'content'));
        $this->assertSame(300, $this->sets['app.example.org. A']['ttl']);

        $this->api($token)->postJson($url, ['type' => 'MX', 'name' => '@', 'content' => 'mail.example.org', 'priority' => 5])->assertCreated();
        $this->api($token)->postJson($url, ['type' => 'TXT', 'name' => '@', 'content' => 'v=spf1 "mx" -all'])->assertCreated();
        $this->assertSame('5 mail.example.org.', $this->sets['example.org. MX']['records'][0]['content']);
        $this->assertSame('"v=spf1 \"mx\" -all"', $this->sets['example.org. TXT']['records'][0]['content']);

        $this->api($token)->postJson($url, ['type' => 'A', 'name' => 'cdn', 'content' => '192.0.2.9', 'proxied' => true])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['proxied']]]);

        // Moving a value to another name rewrites both sets in one PATCH.
        $this->patches = [];
        $moved = $this->api($token)->patchJson("{$url}/{$a1['id']}", ['name' => 'web', 'content' => '192.0.2.1', 'ttl' => 3600])->assertOk()->json('data');
        $this->assertSame('web.example.org', $moved['name']);
        $this->assertCount(2, $this->patches);
        $this->assertSame(['192.0.2.2'], array_column($this->sets['app.example.org. A']['records'], 'content'));

        // Deleting the last value deletes the set.
        $this->api($token)->deleteJson("{$url}/{$moved['id']}")->assertNoContent();
        $this->assertArrayNotHasKey('web.example.org. A', $this->sets);

        $sync = $this->api($token)->postJson("/api/v1/dns/zones/{$zone['id']}/sync")->assertOk()->json('data');
        $this->assertTrue($sync['in_sync']);
        $records = collect($this->api($token)->getJson("/api/v1/dns/zones/{$zone['id']}")->json('data.records'));
        $this->assertSame('v=spf1 "mx" -all', $records->firstWhere('type', 'TXT')['content']);
        $this->assertSame(5, $records->firstWhere('type', 'MX')['priority']);
    }

    public function test_dnssec_on_powerdns_creates_a_signing_key_and_returns_the_ds(): void
    {
        [$token, , $zone] = $this->powerdnsZone();
        $url = "/api/v1/dns/zones/{$zone['id']}/dnssec";
        $this->api($token)->getJson($url)->assertOk()->assertJsonPath('data.status', 'disabled');

        $this->api($token)->putJson($url, ['enabled' => true])->assertOk()
            ->assertJsonPath('data.status', 'active')->assertJsonPath('data.ds', ['example.org. IN DS 2371 13 2 abcdef']);
        $this->api($token)->putJson($url, ['enabled' => true])->assertOk();
        $this->assertCount(1, $this->keys);   // enabling twice does not add a second key
        $this->assertSame('active', $this->api($token)->getJson("/api/v1/dns/zones/{$zone['id']}")->json('data.dnssec.status'));
        $this->assertTrue(AuditLog::where('action', 'dns.dnssec_enabled')->exists());

        $this->api($token)->putJson($url, ['enabled' => false])->assertOk()->assertJsonPath('data.status', 'disabled')->assertJsonPath('data.ds', []);
        $this->assertSame([], $this->keys);
    }

    public function test_dnssec_on_cloudflare_waits_for_the_registrar(): void
    {
        [, , $token] = $this->org();
        $provider = $this->api($token)->postJson('/api/v1/dns/providers', ['name' => 'CF', 'type' => 'cloudflare', 'token' => 't'])->assertCreated()->json('data');
        $zone = $this->api($token)->postJson('/api/v1/dns/zones', ['dns_provider_id' => $provider['id'], 'name' => 'example.com'])->assertCreated()->json('data');

        $this->api($token)->putJson("/api/v1/dns/zones/{$zone['id']}/dnssec", ['enabled' => true])->assertOk()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.ds', ['example.com. 3600 IN DS 2371 13 2 ffff']);
        [, , $other] = $this->org();
        $this->api($other)->putJson("/api/v1/dns/zones/{$zone['id']}/dnssec", ['enabled' => false])->assertNotFound();
    }
}
