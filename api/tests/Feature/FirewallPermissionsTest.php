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
        $this->assertSame('incident 42', $this->api($token)->getJson("/api/v1/app/apps/{$app->id}")->json('data.lock_reason'));

        $this->api($token)->postJson("/api/v1/app/apps/{$app->id}/unlock")->assertOk()->assertJsonPath('data.public_access', true);
        $y = $yaml();
        $this->assertArrayNotHasKey('dmp-shop-example-com-locked', $y['http']['middlewares']);
        $this->assertArrayHasKey('dmp-shop-example-com-blocked', $y['http']['routers'], 'unlocking an app never clears an IP block');
        $this->assertSame(1, FirewallIpList::allTenants()->count());
        unset($created, $server);
        $this->assertNotNull(Domain::allTenants()->first());
    }
}
