<?php

namespace Tests\Feature;

use App\Contracts\CertificateIssuer;
use App\Contracts\DnsResolver;
use App\Models\Application;
use App\Models\Domain;
use App\Support\Tenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/** DNS-013: Domains & SSL use the organisation's DNS zones for DNS-01 automatically. */
class DnsAutoCertificateTest extends TestCase
{
    private array $remote = [];

    private int $next = 1;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.drivers.certificates' => 'traefik']);
        $this->app->forgetInstance(CertificateIssuer::class);
        Http::fake(function (Request $request) {
            $path = substr(parse_url($request->url(), PHP_URL_PATH), strlen('/client/v4'));
            $ok = fn ($r, $extra = []) => Http::response(['success' => true, 'errors' => [], 'result' => $r] + $extra);

            return match (true) {
                $path === '/user/tokens/verify' => $ok(['id' => 'abcdef1234567890', 'status' => 'active']),
                $path === '/zones' && $request->method() === 'GET' => $ok([]),
                $path === '/zones' => $ok(['id' => 'z1', 'status' => 'active', 'name_servers' => ['ada.ns.cloudflare.com']]),
                $path === '/zones/z1/dns_records' && $request->method() === 'GET' => $ok(array_values($this->remote), ['result_info' => ['total_pages' => 1]]),
                $path === '/zones/z1/dns_records' => $ok($this->remote[$id = 'r'.$this->next++] = ['id' => $id] + $request->data()),
                default => Http::response(['success' => false, 'errors' => [['message' => 'not found']]], 404),
            };
        });
    }

    /** An ingress server with an app, and a Cloudflare zone for example.com. */
    private function setUpZone(): array
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['ip' => '198.51.100.9', 'tags' => ['ingress']]);
        [$secret] = $this->enrollAgent($server, $token);
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'shop', 'kind' => 'platform', 'source_type' => 'image', 'image' => 'nginx', 'server_id' => $server->id, 'port' => 8080]));
        $provider = $this->api($token)->postJson('/api/v1/dns/providers', ['name' => 'CF', 'type' => 'cloudflare', 'token' => 'zone-token'])->assertCreated()->json('data');
        $this->api($token)->postJson('/api/v1/dns/zones', ['dns_provider_id' => $provider['id'], 'name' => 'example.com'])->assertCreated();

        return [$token, $secret, $app];
    }

    private function resolveFromZone(): void
    {
        $dns = app(DnsResolver::class);
        foreach (collect($this->remote)->groupBy(fn ($r) => ltrim($r['name'], '*.').' '.$r['type']) as $key => $rows) {
            [$name, $type] = explode(' ', $key);
            $dns->set($name, $type, $rows->pluck('content')->all());
        }
    }

    public function test_a_domain_in_a_zone_publishes_its_records_and_uses_the_zone_for_dns01(): void
    {
        [$token, $secret, $app] = $this->setUpZone();
        $domain = $this->api($token)->postJson('/api/v1/domains', ['name' => 'shop.example.com', 'application_id' => $app->id])->assertCreated()
            ->assertJsonPath('data.challenge', 'dns-01')->assertJsonPath('data.has_dns_token', false)
            ->assertJsonPath('data.dns_zone.name', 'example.com')->assertJsonPath('data.dns_zone.provider', 'cloudflare')->json('data');

        // The A record and the ownership TXT went straight into the zone, unproxied.
        $records = collect($this->remote)->keyBy('type');
        $this->assertSame(['shop.example.com', '198.51.100.9', false], [$records['A']['name'], $records['A']['content'], $records['A']['proxied']]);
        $this->assertSame('_acme-challenge.shop.example.com', $records['TXT']['name']);

        $this->resolveFromZone();
        $verify = $this->api($token)->postJson("/api/v1/domains/{$domain['id']}/verify")->assertOk()->assertJsonPath('data.https_status', 'issuing')->json('data');
        $this->assertSame(['present', 'present'], array_column($verify['zone_records'], 'status'));

        $config = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'proxy.config')['payload'];
        $this->assertSame(['CF_DNS_API_TOKEN' => 'zone-token'], $config['secrets']);
        $router = Yaml::parse($config['files']['dmp-domains.yml'])['http']['routers']['dmp-shop-example-com'];
        $this->assertSame('le-dns-cloudflare', $router['tls']['certResolver']);
    }

    public function test_conflicting_records_are_reported_not_overwritten(): void
    {
        [$token, , $app] = $this->setUpZone();
        $this->remote['old'] = ['id' => 'old', 'type' => 'A', 'name' => 'blog.example.com', 'content' => '203.0.113.50', 'ttl' => 1, 'proxied' => false];
        $zoneId = $this->api($token)->getJson('/api/v1/dns/zones')->json('data.0.id');
        $this->api($token)->postJson("/api/v1/dns/zones/{$zoneId}/sync")->assertOk();

        $domain = $this->api($token)->postJson('/api/v1/domains', ['name' => 'blog.example.com', 'application_id' => $app->id])->assertCreated()->json('data');
        $verify = $this->api($token)->postJson("/api/v1/domains/{$domain['id']}/verify")->assertOk()->json('data');
        $a = collect($verify['zone_records'])->firstWhere('type', 'A');
        $this->assertSame('conflict', $a['status']);
        $this->assertStringContainsString('203.0.113.50', $a['message']);
        $this->assertSame('203.0.113.50', $this->remote['old']['content']);
        $this->assertSame('pending', $verify['dns_status']);
    }

    public function test_an_explicit_token_or_challenge_still_wins_and_other_tenants_zones_are_ignored(): void
    {
        [$token, $secret, $app] = $this->setUpZone();
        $this->api($token)->postJson('/api/v1/domains', ['name' => 'www2.example.com', 'application_id' => $app->id, 'challenge' => 'http-01'])->assertCreated()
            ->assertJsonPath('data.challenge', 'http-01');
        $own = $this->api($token)->postJson('/api/v1/domains', ['name' => '*.apps.example.com', 'application_id' => $app->id, 'dns_provider' => 'cloudflare', 'dns_token' => 'own-token'])
            ->assertCreated()->assertJsonPath('data.dns_zone', null)->json('data');
        $this->assertSame(0, collect($this->remote)->where('name', '*.apps.example.com')->count());

        Domain::allTenants()->whereKey($own['id'])->update(['dns_status' => 'verified']);
        app(\App\Services\TraefikConfigService::class)->push(true);
        $config = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'proxy.config')['payload'];
        $this->assertSame('own-token', $config['secrets']['CF_DNS_API_TOKEN']);

        [, , $other] = $this->org();
        $this->api($other)->postJson('/api/v1/domains', ['name' => 'evil.example.com'])->assertCreated()
            ->assertJsonPath('data.challenge', 'http-01')->assertJsonPath('data.dns_zone', null);
    }

    public function test_a_powerdns_zone_uses_the_pdns_resolver_and_its_api_credentials(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['ip' => '198.51.100.9', 'tags' => ['ingress']]);
        [$secret] = $this->enrollAgent($server, $token);
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'web', 'kind' => 'platform', 'source_type' => 'image', 'image' => 'nginx', 'server_id' => $server->id, 'port' => 80]));
        $provider = \App\Models\DnsProviderAccount::allTenants()->create(['organization_id' => $org->id, 'name' => 'Own', 'type' => 'powerdns',
            'credentials' => ['api_url' => 'https://pdns.example.net:8081', 'api_key' => 'pdns-key', 'server_id' => 'localhost', 'nameservers' => ['ns1.example.net']]]);
        \App\Models\DnsZone::allTenants()->create(['organization_id' => $org->id, 'dns_provider_id' => $provider->id, 'name' => 'example.net', 'remote_id' => 'example.net.', 'status' => 'active']);

        $domain = $this->api($token)->postJson('/api/v1/domains', ['name' => 'app.example.net', 'application_id' => $app->id])->assertCreated()
            ->assertJsonPath('data.dns_zone.provider', 'powerdns')->json('data');
        Domain::allTenants()->whereKey($domain['id'])->update(['dns_status' => 'verified']);
        app(\App\Services\TraefikConfigService::class)->push(true);
        $config = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'proxy.config')['payload'];
        $this->assertSame(['PDNS_API_URL' => 'https://pdns.example.net:8081', 'PDNS_API_KEY' => 'pdns-key', 'PDNS_SERVER_NAME' => 'localhost'], $config['secrets']);
        $this->assertSame('le-dns-pdns', Yaml::parse($config['files']['dmp-domains.yml'])['http']['routers']['dmp-app-example-net']['tls']['certResolver']);
    }
}
