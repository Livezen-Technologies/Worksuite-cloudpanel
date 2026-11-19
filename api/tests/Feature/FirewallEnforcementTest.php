<?php

namespace Tests\Feature;

use App\Contracts\CertificateIssuer;
use App\Contracts\DnsResolver;
use App\Models\Application;
use App\Models\Domain;
use App\Models\FirewallIpList;
use App\Support\Tenant;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/** Native IP enforcement: block routers, allow-list-only mode, overlap warnings, lockout guards, break-glass. */
class FirewallEnforcementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.drivers.certificates' => 'traefik']);
        $this->app->forgetInstance(CertificateIssuer::class);
    }

    private function verifiedDomain(): array
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['ip' => '198.51.100.9']);
        [$secret] = $this->enrollAgent($server, $token);
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'shop', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'size' => 'small', 'server_id' => $server->id, 'port' => 80]));
        $created = $this->api($token)->postJson('/api/v1/app/domains', ['domain' => 'shop.example.com', 'app_id' => $app->id])->assertCreated()->json('data');
        app(DnsResolver::class)->set('shop.example.com', 'A', ['198.51.100.9']);
        $this->api($token)->postJson("/api/v1/app/domains/{$created['id']}/verify")->assertOk();

        return [$org, $token, $secret, Domain::allTenants()->find($created['id'])];
    }

    private function yaml(string $secret, string $org): array
    {
        app(Tenant::class)->as($org, fn () => app(\App\Services\TraefikConfigService::class)->push(true));
        $commands = collect($this->heartbeat($secret)['commands'])->keyBy('type');

        return Yaml::parse($commands['proxy.config']['payload']['files']['dmp-domains.yml']);
    }

    public function test_block_list_renders_a_router_that_answers_403(): void
    {
        [$org, $token, $secret] = $this->verifiedDomain();
        $this->api($token)->postJson('/api/v1/firewall/ips', ['list' => 'block', 'cidr' => '203.0.113.0/24'])->assertCreated();
        $y = $this->yaml($secret, $org->id);
        $blocked = $y['http']['routers']['dmp-shop-example-com-blocked'];
        $this->assertStringContainsString('ClientIP(`203.0.113.0/24`)', $blocked['rule']);
        $this->assertSame(['dmp-shop-example-com-ipdeny'], $blocked['middlewares']);
        $this->assertSame(['127.0.0.1/32'], $y['http']['middlewares']['dmp-shop-example-com-ipdeny']['ipAllowList']['sourceRange']);
        $this->assertGreaterThan(100000, $blocked['priority']);

        // Behind a CDN the TCP peer is the CDN, so the native block is not rendered (CrowdSec handles it).
        config(['platform.traefik.forwarded_depth' => 1]);
        $this->assertArrayNotHasKey('dmp-shop-example-com-blocked', $this->yaml($secret, $org->id)['http']['routers']);
    }

    public function test_allowlist_mode_restricts_the_domain_and_guards_against_lockout(): void
    {
        [$org, $token, $secret, $domain] = $this->verifiedDomain();
        config(['platform.firewall.always_allow' => ['192.0.2.10/32']]);

        // No allow entries yet: refused.
        $this->api($token)->patchJson("/api/v1/firewall/domains/{$domain->id}/access", ['ip_access' => 'allowlist'])->assertStatus(422);
        $this->api($token)->postJson('/api/v1/firewall/ips', ['list' => 'allow', 'cidr' => '198.51.100.7', 'domain_id' => $domain->id])->assertCreated();
        // The caller (127.0.0.1 in tests) is not allowed: needs confirm.
        $this->api($token)->patchJson("/api/v1/firewall/domains/{$domain->id}/access", ['ip_access' => 'allowlist'])->assertStatus(409)->assertSee('your own address', false);
        $this->api($token)->patchJson("/api/v1/firewall/domains/{$domain->id}/access", ['ip_access' => 'allowlist', 'confirm' => true])->assertOk()->assertJsonPath('data.ip_access', 'allowlist');

        $y = $this->yaml($secret, $org->id);
        $this->assertSame(['198.51.100.7/32', '192.0.2.10/32'], $y['http']['middlewares']['dmp-shop-example-com-ipallow']['ipAllowList']['sourceRange']);
        $this->assertSame('dmp-shop-example-com-ipallow', $y['http']['routers']['dmp-shop-example-com']['middlewares'][0]);

        // Break-glass.
        $this->assertSame(0, Artisan::call('firewall:open'));
        $this->assertSame('open', $domain->fresh()->ip_access);
        $this->assertArrayNotHasKey('dmp-shop-example-com-ipallow', $this->yaml($secret, $org->id)['http']['middlewares'] ?? []);
    }

    public function test_overlapping_allow_and_block_entries_need_confirmation(): void
    {
        [, $token] = $this->verifiedDomain();
        $this->api($token)->postJson('/api/v1/firewall/ips', ['list' => 'allow', 'cidr' => '198.51.100.0/24'])->assertCreated();
        $this->api($token)->postJson('/api/v1/firewall/ips', ['list' => 'block', 'cidr' => '198.51.100.44'])->assertStatus(409)->assertSee('overlaps', false);
        $this->api($token)->postJson('/api/v1/firewall/ips', ['list' => 'block', 'cidr' => '198.51.100.44', 'confirm' => true])->assertCreated();
        $this->api($token)->postJson('/api/v1/firewall/ips', ['list' => 'block', 'cidr' => '10.9.0.0/16'])->assertCreated(); // no overlap
        $this->assertSame(3, FirewallIpList::allTenants()->count());
    }

    public function test_ip_strategy_uses_the_tcp_peer_unless_a_depth_is_configured(): void
    {
        config(['platform.traefik.forwarded_depth' => 0]);
        $this->assertSame([], \App\Services\FirewallConfigService::ipStrategy());
        config(['platform.traefik.forwarded_depth' => 2]);
        $this->assertSame(['ipStrategy' => ['depth' => 2]], \App\Services\FirewallConfigService::ipStrategy());
    }
}
