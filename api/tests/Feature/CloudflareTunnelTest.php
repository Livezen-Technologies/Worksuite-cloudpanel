<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\CloudflareTunnel;
use App\Models\DnsProviderAccount;
use App\Support\Tenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** CFT-1..6: tunnel creation, connector, ingress, DNS, health and removal. */
class CloudflareTunnelTest extends TestCase
{
    private array $calls = [];

    private string $health = 'healthy';

    private function fakeCloudflare(): void
    {
        $ok = fn ($result) => Http::response(['success' => true, 'errors' => [], 'result' => $result]);
        Http::fake(function (Request $r) use ($ok) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            $this->calls[] = $r->method().' '.$path.(in_array($r->method(), ['POST', 'PUT'], true) ? ' '.$r->body() : '');

            return match (true) {
                $r->method() === 'POST' && str_ends_with($path, '/cfd_tunnel') => $ok(['id' => 'tun-1', 'name' => json_decode($r->body(), true)['name']]),
                str_ends_with($path, '/cfd_tunnel/tun-1/token') => $ok('eyJ0b2tlbiI6InNlY3JldCJ9'),
                str_ends_with($path, '/cfd_tunnel/tun-1/configurations') => $ok([]),
                $r->method() === 'GET' && str_ends_with($path, '/cfd_tunnel/tun-1') => $ok(['status' => $this->health, 'connections' => $this->health === 'down' ? [] : [['id' => 'c1'], ['id' => 'c2']]]),
                $r->method() === 'DELETE' && str_ends_with($path, '/cfd_tunnel/tun-1') => $ok(null),
                str_ends_with($path, '/zones') => $ok(str_contains($r->url(), 'name=acme.test') ? [['id' => 'z1', 'status' => 'active', 'name_servers' => []]] : []),
                $r->method() === 'GET' && str_ends_with($path, '/dns_records') => $ok([['id' => 'old-a', 'type' => 'A', 'name' => 'shop.acme.test', 'content' => '203.0.113.9', 'ttl' => 1, 'proxied' => false]]),
                str_contains($path, '/dns_records') => $ok(['id' => 'rec-'.count($this->calls), 'type' => 'CNAME', 'name' => 'x', 'content' => 'tun-1.cfargotunnel.com', 'ttl' => 1, 'proxied' => true]),
                default => Http::response(['success' => false, 'errors' => [['message' => 'unexpected '.$path]]], 404),
            };
        });
    }

    public function test_tunnel_publishes_the_servers_domains_without_open_ports(): void
    {
        $this->fakeCloudflare();
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $provider = app(Tenant::class)->as($org->id, fn () => DnsProviderAccount::create(['name' => 'cf', 'type' => 'cloudflare', 'credentials' => ['token' => 'cf-token', 'account_id' => 'acc1']]));
        $noAccount = app(Tenant::class)->as($org->id, fn () => DnsProviderAccount::create(['name' => 'cf2', 'type' => 'cloudflare', 'credentials' => ['token' => 't']]));
        $appId = $this->api($token)->postJson('/api/v1/applications', ['name' => 'shop', 'source_type' => 'image', 'image' => 'acme/shop:1', 'server_id' => $server->id, 'port' => 8080])->assertCreated()->json('data.id');
        $this->api($token)->postJson('/api/v1/domains', ['name' => 'shop.acme.test', 'application_id' => $appId, 'target_port' => 8080])->assertCreated();
        $this->api($token)->postJson('/api/v1/domains', ['name' => 'shop.other.test', 'application_id' => $appId, 'target_port' => 8080])->assertCreated();

        $this->api($token)->postJson("/api/v1/servers/{$server->id}/tunnel", ['dns_provider_id' => $noAccount->id])->assertStatus(422);
        $t = $this->api($token)->postJson("/api/v1/servers/{$server->id}/tunnel", ['dns_provider_id' => $provider->id])->assertCreated()->json('data');
        $this->assertSame(['healthy', 2, ['shop.acme.test'], 'tun-1.cfargotunnel.com'], [$t['status'], $t['connections'], $t['hostnames'], $t['cname_target']]);
        $this->assertStringContainsString('shop.other.test: no zone', $t['error']);
        $this->assertArrayNotHasKey('token', $t);

        $config = collect($this->calls)->first(fn ($c) => str_contains($c, '/configurations'));
        $this->assertStringContainsString('"hostname":"shop.acme.test","service":"http:\/\/traefik:80"', $config);
        $this->assertStringContainsString('"service":"http_status:404"', $config);
        $this->assertNotNull(collect($this->calls)->first(fn ($c) => str_starts_with($c, 'PUT /client/v4/zones/z1/dns_records/old-a') && str_contains($c, '"proxied":true') && str_contains($c, 'cfargotunnel.com')), 'the existing A record becomes a proxied CNAME');

        $cmds = $this->heartbeat($secret)['commands'];
        $create = collect($cmds)->firstWhere('type', 'container.create')['payload'];
        $this->assertSame(['dmp-cloudflared', 'cloudflare/cloudflared:2024.10.1'], [$create['name'], $create['image']]);
        $this->assertSame('eyJ0b2tlbiI6InNlY3JldCJ9', $create['env']['TUNNEL_TOKEN']);
        $this->assertArrayNotHasKey('ports', $create, 'the connector only dials out');
        $this->assertSame([['name' => 'proxy']], $create['networks']);

        // Health: a down tunnel opens one alert.
        $this->health = 'down';
        $this->artisan('tunnels:sync')->assertSuccessful();
        $this->artisan('tunnels:sync')->assertSuccessful();
        $this->assertSame(1, Alert::allTenants()->where('title', 'like', 'Cloudflare Tunnel down%')->count());

        // Disabling removes the CNAME, the connector and the tunnel.
        $this->calls = [];
        $this->api($token)->deleteJson("/api/v1/servers/{$server->id}/tunnel")->assertNoContent();
        $this->assertNotNull(collect($this->calls)->first(fn ($c) => str_starts_with($c, 'DELETE /client/v4/zones/z1/dns_records/rec-')), 'the CNAME is removed');
        $this->assertNotNull(collect($this->calls)->first(fn ($c) => str_starts_with($c, 'DELETE /client/v4/accounts/acc1/cfd_tunnel/tun-1')));
        $this->assertSame('container.remove', $this->heartbeat($secret)['commands'][0]['type']);
        $this->assertSame(0, CloudflareTunnel::allTenants()->count());
    }

    public function test_ports_close_only_while_every_domain_rides_a_healthy_tunnel(): void
    {
        $this->fakeCloudflare();
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $provider = app(Tenant::class)->as($org->id, fn () => DnsProviderAccount::create(['name' => 'cf', 'type' => 'cloudflare', 'credentials' => ['token' => 'cf-token', 'account_id' => 'acc1']]));
        $appId = $this->api($token)->postJson('/api/v1/applications', ['name' => 'shop', 'source_type' => 'image', 'image' => 'acme/shop:1', 'server_id' => $server->id, 'port' => 8080])->assertCreated()->json('data.id');
        $this->api($token)->postJson('/api/v1/domains', ['name' => 'shop.acme.test', 'application_id' => $appId, 'target_port' => 8080])->assertCreated();
        $this->api($token)->postJson("/api/v1/servers/{$server->id}/tunnel", ['dns_provider_id' => $provider->id])->assertCreated();
        $this->heartbeat($secret);
        $lock = fn () => collect($this->heartbeat($secret)['commands'])->where('type', 'tunnel.lockdown')->pluck('payload.enabled')->all();

        $t = $this->api($token)->patchJson("/api/v1/servers/{$server->id}/tunnel", ['close_ports' => true])->assertOk()->json('data');
        $this->assertSame([true, true], [$t['close_ports'], $t['ports_closed']]);
        $this->assertSame([true], $lock());

        // A domain Cloudflare can't route reopens the ports at once.
        $this->calls = [];
        $other = $this->api($token)->postJson('/api/v1/domains', ['name' => 'shop.other.test', 'application_id' => $appId, 'target_port' => 8080])->assertCreated()->json('data.id');
        $this->assertNotEmpty($this->calls, 'adding a domain syncs the tunnel');
        $this->assertSame([false], $lock());
        $this->api($token)->deleteJson("/api/v1/domains/{$other}")->assertSuccessful();
        $this->assertSame([true], $lock());

        // A down tunnel reopens; disabling the tunnel reopens.
        $this->health = 'down';
        $this->artisan('tunnels:sync')->assertSuccessful();
        $this->assertSame([false], $lock());
        $this->health = 'healthy';
        $this->artisan('tunnels:sync')->assertSuccessful();
        $this->assertSame([true], $lock());
        $this->api($token)->deleteJson("/api/v1/servers/{$server->id}/tunnel")->assertNoContent();
        $this->assertSame([false], $lock());
    }
}
