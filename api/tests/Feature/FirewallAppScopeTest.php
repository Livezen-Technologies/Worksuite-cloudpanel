<?php

namespace Tests\Feature;

use App\Contracts\CertificateIssuer;
use App\Contracts\DnsResolver;
use App\Models\Application;
use App\Models\FirewallRule;
use App\Models\Project;
use App\Services\CrowdSecConfigService;
use App\Support\Tenant;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/** Firewall rules and IP entries scoped to a project or an application; burst and block duration. */
class FirewallAppScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.drivers.certificates' => 'traefik']);
        $this->app->forgetInstance(CertificateIssuer::class);
    }

    private function site(string $token, $org, $server, string $name, ?string $projectId): Application
    {
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => $name, 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'size' => 'small', 'server_id' => $server->id, 'port' => 80, 'project_id' => $projectId]));
        $d = $this->api($token)->postJson('/api/v1/app/domains', ['domain' => "{$name}.example.com", 'app_id' => $app->id])->assertCreated()->json('data');
        app(DnsResolver::class)->set("{$name}.example.com", 'A', ['198.51.100.9']);
        $this->api($token)->postJson("/api/v1/app/domains/{$d['id']}/verify")->assertOk();

        return $app;
    }

    public function test_project_and_app_scope_reach_only_their_domains(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['ip' => '198.51.100.9']);
        [$secret] = $this->enrollAgent($server, $token);
        $p = app(Tenant::class)->as($org->id, fn () => Project::create(['name' => 'Shop']));
        $shop = $this->site($token, $org, $server, 'shop', $p->id);
        $this->site($token, $org, $server, 'blog', null);

        $this->api($token)->postJson('/api/v1/firewall-rules', ['name' => 'API', 'paths' => ['*'], 'limit' => 100, 'window_seconds' => 60, 'action' => 'throttle', 'project_id' => $p->id, 'burst' => 25])->assertCreated();
        $this->api($token)->postJson('/api/v1/firewall/ips', ['list' => 'block', 'cidr' => '203.0.113.0/24', 'application_id' => $shop->id])->assertCreated();
        $this->api($token)->postJson('/api/v1/firewall-rules', ['name' => 'Both', 'paths' => ['*'], 'limit' => 5, 'domain_id' => null, 'application_id' => $shop->id, 'project_id' => $p->id])->assertStatus(422);

        app(Tenant::class)->as($org->id, fn () => app(\App\Services\TraefikConfigService::class)->push(true));
        $y = Yaml::parse(collect($this->heartbeat($secret)['commands'])->keyBy('type')['proxy.config']['payload']['files']['dmp-domains.yml']);
        $shopMw = $y['http']['routers']['dmp-shop-example-com']['middlewares'] ?? [];
        $blogMw = $y['http']['routers']['dmp-blog-example-com']['middlewares'] ?? [];
        $limit = collect($shopMw)->first(fn ($m) => str_starts_with($m, 'dmp-fw-'));
        $this->assertNotNull($limit, 'the project rule reaches its app');
        $this->assertSame(25, $y['http']['middlewares'][$limit]['rateLimit']['burst']);
        $this->assertEmpty(array_filter($blogMw, fn ($m) => str_starts_with($m, 'dmp-fw-')), 'and not an app outside the project');
        $this->assertArrayHasKey('dmp-shop-example-com-blocked', $y['http']['routers']);
        $this->assertArrayNotHasKey('dmp-blog-example-com-blocked', $y['http']['routers']);

        $rule = $this->api($token)->getJson('/api/v1/firewall-rules')->json('data.0');
        $this->assertSame('project Shop', $rule['scope']);
    }

    public function test_block_rules_get_their_own_ban_length(): void
    {
        $svc = app(CrowdSecConfigService::class);
        $yaml = $svc->profiles(['dmp/fw-a' => 15, 'dmp/fw-b' => 60, 'dmp/block-x'], []);
        $this->assertStringContainsString('dmp_ban_15m', $yaml);
        $this->assertStringContainsString('dmp_ban_60m', $yaml);
        $this->assertStringContainsString('duration: 15m', $yaml);
        // Plain lists still produce one profile named dmp_ban.
        $this->assertStringContainsString('name: dmp_ban', $svc->profiles(['dmp/block-x'], []));
        unset($svc);
        $this->assertTrue(class_exists(FirewallRule::class));
    }
}
