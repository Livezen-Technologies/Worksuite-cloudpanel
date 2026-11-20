<?php

namespace Tests\Feature;

use App\Contracts\CertificateIssuer;
use App\Contracts\DnsResolver;
use App\Models\Application;
use App\Models\Domain;
use App\Models\FirewallIpList;
use App\Support\Tenant;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/** Firewall RBAC (view / unblock / manage IPs / change firewall / lock app) and the application lock. */
class FirewallPermissionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.drivers.certificates' => 'traefik']);
        $this->app->forgetInstance(CertificateIssuer::class);
    }

    public function test_roles_get_the_firewall_rights_of_the_spec_table(): void
    {
        [, $org, $owner] = $this->org();
        [, $dev] = $this->member($org, 'developer');
        [, $viewer] = $this->member($org, 'billing'); // has no firewall rights at all
        $this->api($owner)->postJson('/api/v1/firewall/ips', ['list' => 'block', 'cidr' => '203.0.113.9'])->assertCreated();
        $id = FirewallIpList::allTenants()->value('id');

        // Developer: view yes, manage IPs and change firewall no.
        $this->api($dev)->getJson('/api/v1/firewall')->assertOk();
        $this->api($dev)->postJson('/api/v1/firewall/ips', ['list' => 'block', 'cidr' => '203.0.113.10'])->assertForbidden();
        $this->api($dev)->patchJson('/api/v1/firewall/under-attack', ['enabled' => true])->assertForbidden();
        $this->api($dev)->deleteJson("/api/v1/firewall/ips/{$id}")->assertForbidden();
        // No firewall right: cannot even view.
        $this->api($viewer)->getJson('/api/v1/firewall')->assertForbidden();
        // Owner can do everything.
        $this->api($owner)->deleteJson("/api/v1/firewall/ips/{$id}")->assertNoContent();
    }

    public function test_locking_an_app_closes_its_domains_and_unlocking_keeps_ip_blocks(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['ip' => '198.51.100.9']);
        [$secret] = $this->enrollAgent($server, $token);
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'shop', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'size' => 'small', 'server_id' => $server->id, 'port' => 80]));
        $created = $this->api($token)->postJson('/api/v1/app/domains', ['domain' => 'shop.example.com', 'app_id' => $app->id])->assertCreated()->json('data');
        app(DnsResolver::class)->set('shop.example.com', 'A', ['198.51.100.9']);
        $this->api($token)->postJson("/api/v1/app/domains/{$created['id']}/verify")->assertOk();
        $this->api($token)->postJson('/api/v1/firewall/ips', ['list' => 'block', 'cidr' => '203.0.113.0/24'])->assertCreated();
        $yaml = function () use ($org, $secret) {
            app(Tenant::class)->as($org->id, fn () => app(\App\Services\TraefikConfigService::class)->push(true));

            return Yaml::parse(collect($this->heartbeat($secret)['commands'])->keyBy('type')['proxy.config']['payload']['files']['dmp-domains.yml']);
        };

        $this->api($token)->postJson("/api/v1/app/apps/{$app->id}/lock", ['reason' => 'incident 42'])->assertOk()->assertJsonPath('data.public_access', false);
        $y = $yaml();
        $this->assertSame('dmp-shop-example-com-locked', $y['http']['routers']['dmp-shop-example-com']['middlewares'][0]);
        $this->assertSame(['127.0.0.1/32'], $y['http']['middlewares']['dmp-shop-example-com-locked']['ipAllowList']['sourceRange']);
        $this->assertArrayHasKey('dmp-shop-example-com-blocked', $y['http']['routers']);
        // The public gets the panel's maintenance page through a takeover router.
        $m = $y['http']['routers']['dmp-shop-example-com-maintenance'];
        $this->assertSame('dmp-panel-pages', $m['service']);
        $this->assertSame('(Host(`shop.example.com`))', $m['rule'], 'with no kept ranges everyone sees the page');
        $this->assertSame('/api/v1/hooks/maintenance/'.$app->id, $y['http']['middlewares']['dmp-shop-example-com-maintenance']['replacePath']['path']);
        $this->assertArrayHasKey('dmp-panel-pages', $y['http']['services']);
        $this->assertSame('incident 42', $this->api($token)->getJson("/api/v1/app/apps/{$app->id}")->json('data.lock_reason'));

        $this->api($token)->postJson("/api/v1/app/apps/{$app->id}/unlock")->assertOk()->assertJsonPath('data.public_access', true);
        $y = $yaml();
        $this->assertArrayNotHasKey('dmp-shop-example-com-locked', $y['http']['middlewares']);
        $this->assertArrayNotHasKey('dmp-shop-example-com-maintenance', $y['http']['routers'], 'unlocking removes the maintenance routing');
        $this->assertArrayNotHasKey('dmp-shop-example-com-maintenance', $y['http']['middlewares']);
        $this->assertArrayNotHasKey('dmp-panel-pages', $y['http']['services'] ?? []);
        $this->assertArrayHasKey('dmp-shop-example-com-blocked', $y['http']['routers'], 'unlocking an app never clears an IP block');
        $this->assertSame(1, FirewallIpList::allTenants()->count());
        unset($created, $server);
        $this->assertNotNull(Domain::allTenants()->first());
    }

    /** Locks an app on a fresh org and returns [app, yaml-reader]. */
    private function lockedShop(array $lock, ?\Closure $before = null): array
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['ip' => '198.51.100.9']);
        [$secret] = $this->enrollAgent($server, $token);
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'shop', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'size' => 'small', 'server_id' => $server->id, 'port' => 80]));
        $created = $this->api($token)->postJson('/api/v1/app/domains', ['domain' => 'shop.example.com', 'app_id' => $app->id])->assertCreated()->json('data');
        app(DnsResolver::class)->set('shop.example.com', 'A', ['198.51.100.9']);
        $this->api($token)->postJson("/api/v1/app/domains/{$created['id']}/verify")->assertOk();
        $before && $before($token);
        $this->api($token)->postJson("/api/v1/app/apps/{$app->id}/lock", $lock)->assertOk();
        app(Tenant::class)->as($org->id, fn () => app(\App\Services\TraefikConfigService::class)->push(true));
        $y = Yaml::parse(collect($this->heartbeat($secret)['commands'])->keyBy('type')['proxy.config']['payload']['files']['dmp-domains.yml']);

        return [$app->fresh(), $y];
    }

    public function test_the_maintenance_page_is_a_503_with_retry_after_and_escapes_the_reason(): void
    {
        $until = now()->addHours(2)->startOfMinute();
        [$app] = $this->lockedShop(['reason' => '<script>alert(1)</script> & DB upgrade', 'until' => $until->toIso8601String()]);
        $this->assertTrue($app->maintenance_until->equalTo($until));

        $res = $this->get('/api/v1/hooks/maintenance/'.$app->id);
        $res->assertStatus(503);
        $retry = (int) $res->headers->get('Retry-After');
        $this->assertGreaterThan(7000, $retry);
        $this->assertLessThanOrEqual(7200, $retry);
        $html = $res->getContent();
        $this->assertStringContainsString("We're making improvements", $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; DB upgrade', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('Expected back', $html);
        $this->assertStringContainsString('content="60"', $html);
        $this->assertStringNotContainsString('Sign in to CloudPanel', $html, 'the admin link is off by default');

        // An unknown app still gets a generic 503 page, never an error.
        $this->get('/api/v1/hooks/maintenance/00000000-0000-4000-8000-000000000000')->assertStatus(503)->assertHeader('Retry-After', '300');
    }

    public function test_allow_listed_addresses_skip_the_maintenance_page(): void
    {
        $allow = fn ($token) => $this->api($token)->postJson('/api/v1/firewall/ips', ['list' => 'allow', 'cidr' => '192.0.2.10'])->assertCreated();
        [, $y] = $this->lockedShop([], $allow);
        $this->assertStringContainsString('!(ClientIP(`192.0.2.10', $y['http']['routers']['dmp-shop-example-com-maintenance']['rule']);
    }

    public function test_behind_a_cdn_an_errors_middleware_serves_the_page(): void
    {
        config(['platform.traefik.forwarded_depth' => 1]);
        [$app, $y] = $this->lockedShop([]);
        $this->assertArrayNotHasKey('dmp-shop-example-com-maintenance', $y['http']['routers']);
        $mw = $y['http']['routers']['dmp-shop-example-com']['middlewares'];
        $this->assertSame(['dmp-shop-example-com-maintenance', 'dmp-shop-example-com-locked'], array_slice($mw, 0, 2), 'errors wraps the allow list');
        $this->assertSame(['status' => ['403'], 'service' => 'dmp-panel-pages', 'query' => '/api/v1/hooks/maintenance/'.$app->id], $y['http']['middlewares']['dmp-shop-example-com-maintenance']['errors']);
    }
}
