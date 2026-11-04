<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Application;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Models\Server;
use App\Support\Tenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** SCL-8: A records follow the online ingress servers. */
class EdgeFailoverTest extends TestCase
{
    private array $remote = [];

    private int $next = 1;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(function (Request $request) {
            $path = substr(parse_url($request->url(), PHP_URL_PATH), strlen('/client/v4'));
            $ok = fn ($r, $extra = []) => Http::response(['success' => true, 'errors' => [], 'result' => $r] + $extra);
            if (preg_match('#^/zones/z1/dns_records/(\w+)$#', $path, $m) && $request->method() === 'DELETE') {
                unset($this->remote[$m[1]]);

                return $ok(['id' => $m[1]]);
            }

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

    public function test_records_follow_online_edge_servers_and_leave_others_alone(): void
    {
        config(['platform.edge.failover' => true]);
        [, $org, $token] = $this->org();
        $a = $this->server($org, ['name' => 'edge-a', 'ip' => '198.51.100.1', 'tags' => ['ingress']]);
        $b = $this->server($org, ['name' => 'edge-b', 'ip' => '198.51.100.2', 'tags' => ['ingress']]);
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'shop', 'kind' => 'admin', 'source_type' => 'image', 'image' => 'nginx', 'server_id' => $a->id, 'port' => 80]));
        $provider = $this->api($token)->postJson('/api/v1/dns/providers', ['name' => 'CF', 'type' => 'cloudflare', 'token' => 't'])->assertCreated()->json('data');
        $zone = $this->api($token)->postJson('/api/v1/dns/zones', ['dns_provider_id' => $provider['id'], 'name' => 'example.com'])->assertCreated()->json('data');
        $this->api($token)->postJson("/api/v1/dns/zones/{$zone['id']}/records", ['type' => 'A', 'name' => 'shop', 'content' => '198.51.100.1', 'ttl' => 300])->assertCreated();
        $this->api($token)->postJson("/api/v1/dns/zones/{$zone['id']}/records", ['type' => 'A', 'name' => 'other', 'content' => '203.0.113.50', 'ttl' => 300])->assertCreated();
        foreach (['shop.example.com', 'other.example.com'] as $name) {
            app(Tenant::class)->as($org->id, fn () => Domain::create(['name' => $name, 'application_id' => $app->id, 'dns_status' => 'verified']));
        }
        $ips = fn (string $name) => DnsRecord::allTenants()->where('name', $name)->where('type', 'A')->orderBy('content')->pluck('content')->all();

        // Both edges online: the second is added; the domain hosted elsewhere is untouched.
        $this->artisan('edge:failover')->assertSuccessful();
        $this->assertSame(['198.51.100.1', '198.51.100.2'], $ips('shop.example.com'));
        $this->assertSame(['203.0.113.50'], $ips('other.example.com'));

        // edge-a goes offline: removed and alerted, once.
        Server::allTenants()->whereKey($a->id)->update(['last_heartbeat_at' => now()->subHour()]);
        $this->artisan('edge:failover')->assertSuccessful();
        $this->artisan('edge:failover')->assertSuccessful();
        $this->assertSame(['198.51.100.2'], $ips('shop.example.com'));
        $this->assertSame(1, Alert::allTenants()->where('title', 'Edge server edge-a taken out of DNS')->count());

        // Both down: nothing is removed, so the domain never loses its last record.
        Server::allTenants()->whereKey($b->id)->update(['last_heartbeat_at' => now()->subHour()]);
        $this->artisan('edge:failover')->assertSuccessful();
        $this->assertSame(['198.51.100.2'], $ips('shop.example.com'));

        // edge-a returns: added back and its alert resolved.
        Server::allTenants()->whereIn('id', [$a->id, $b->id])->update(['last_heartbeat_at' => now()]);
        $this->artisan('edge:failover')->assertSuccessful();
        $this->assertSame(['198.51.100.1', '198.51.100.2'], $ips('shop.example.com'));
        $this->assertSame('resolved', Alert::allTenants()->where('title', 'Edge server edge-a taken out of DNS')->value('status'));

        config(['platform.edge.failover' => false]);
        Server::allTenants()->whereKey($a->id)->update(['last_heartbeat_at' => now()->subHour()]);
        $this->artisan('edge:failover')->assertSuccessful();
        $this->assertCount(2, $ips('shop.example.com'), 'off by default');
    }

    public function test_least_connections_becomes_traefiks_p2c_strategy(): void
    {
        [, $org] = $this->org();
        $server = $this->server($org);
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'shop', 'kind' => 'admin', 'source_type' => 'image', 'image' => 'nginx', 'server_id' => $server->id, 'port' => 80,
            'scaling' => ['strategy' => 'least_connections'], 'replicas' => 2]));
        $labels = \App\Services\TraefikLabels::balancing($app, 'app-shop');
        $this->assertSame('p2c', $labels['traefik.http.services.app-shop.loadbalancer.strategy']);
    }
}
