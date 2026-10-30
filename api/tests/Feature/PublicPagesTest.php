<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Domain;
use App\Services\PublicPagesService;
use App\Services\TraefikConfigService;
use App\Support\Tenant;
use Tests\TestCase;

/** SRS PUB-1..4: branded pages served from the edge. */
class PublicPagesTest extends TestCase
{
    private function setUpEdge(): array
    {
        config(['platform.drivers.certificates' => 'traefik']);
        [, $org, $token] = $this->org('super_admin');
        $server = $this->server($org, ['ip' => '198.51.100.9', 'tags' => ['ingress']]);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'shop', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'server_id' => $server->id, 'port' => 80, 'status' => 'running']));
        $domain = Domain::allTenants()->create(['organization_id' => $org->id, 'name' => 'shop.example.com', 'application_id' => $app->id, 'dns_status' => 'verified', 'platform_subdomain' => false]);
        Domain::allTenants()->create(['organization_id' => $org->id, 'name' => 'parked.example.com', 'dns_status' => 'verified', 'platform_subdomain' => false]);

        return [$token, $secret, $server, $app, $domain];
    }

    private function http(\App\Models\Server $server): array
    {
        return json_decode(json_encode(app(TraefikConfigService::class)->render($server)), true)['http'];
    }

    public function test_install_writes_pages_and_starts_the_container(): void
    {
        [$token, $secret, $server] = $this->setUpEdge();
        $this->assertArrayNotHasKey('dmp-pages-catchall', $this->http($server)['routers'], 'nothing changes until the pages container is installed');
        $this->api($token)->postJson('/api/v1/public-pages/install')->assertStatus(202)->assertJsonPath('data.servers', 1);
        $cmds = collect($this->heartbeat($secret)['commands']);
        $create = $cmds->firstWhere('type', 'container.create')['payload'];
        $this->assertSame([PublicPagesService::IMAGE, '8080', true], [$create['image'], $create['labels']['traefik.http.services.ws-pages.loadbalancer.server.port'], $create['read_only_rootfs']]);
        $writes = $cmds->where('type', 'fs.write')->keyBy(fn ($c) => $c['payload']['path']);
        $this->assertStringContainsString('error_page 503 = @maintenance', $writes['default.conf']['payload']['content']);
        $this->assertStringContainsString('add_header Retry-After 3600 always', $writes['default.conf']['payload']['content']);
        foreach (array_keys(PublicPagesService::PAGES) as $key) {
            $html = $writes["default/{$key}.html"]['payload']['content'];
            $this->assertLessThan(50 * 1024, strlen($html), "{$key} is under 50 KB");
            $this->assertStringNotContainsString('<script', $html);
        }
        $this->assertStringContainsString('http-equiv="refresh" content="30"', $writes['default/busy.html']['payload']['content']);
    }

    public function test_routing_for_errors_maintenance_suspended_and_welcome(): void
    {
        [$token, $secret, $server, $app, $domain] = $this->setUpEdge();
        $this->api($token)->postJson('/api/v1/public-pages/install')->assertStatus(202);
        $http = $this->http($server);
        $name = TraefikConfigService::routerName($domain);
        $this->assertContains('dmp-pages-errors', $http['routers'][$name]['middlewares']);
        $this->assertSame(['502-504'], $http['middlewares']['dmp-pages-errors']['errors']['status'], 'apps keep their own 404 pages');
        $this->assertSame(['HostRegexp(`.+`)', 1], [$http['routers']['dmp-pages-catchall']['rule'], $http['routers']['dmp-pages-catchall']['priority']]);
        $parked = TraefikConfigService::routerName(Domain::allTenants()->where('name', 'parked.example.com')->sole());
        $this->assertSame(['dmp-pages-p-welcome'], $http['routers'][$parked.'-page']['middlewares']);
        $this->assertArrayNotHasKey($name.'-page', $http['routers']);

        // PUB-3: site maintenance with an admin bypass.
        $this->api($token)->patchJson("/api/v1/public-pages/sites/{$domain->id}", ['maintenance' => true, 'pages' => ['maintenance' => ['title' => 'Back at 6 pm']]])->assertOk()
            ->assertJsonPath('data.sites.1.showing', 'maintenance');
        $tok = explode('=', $this->api($token)->getJson('/api/v1/public-pages')->json('data.bypass_query'))[1];
        $http = $this->http($server);
        $this->assertStringContainsString("!HeaderRegexp(`Cookie`, `ws_bypass={$tok}`)", $http['routers'][$name.'-page']['rule']);
        $this->assertSame(100000, $http['routers'][$name.'-page']['priority']);
        $this->assertStringContainsString("Query(`ws_bypass`, `{$tok}`)", $http['routers'][$name.'-bypass']['rule']);
        $this->assertSame('/__page/maintenance', $http['middlewares']['dmp-pages-p-maintenance']['replacePath']['path']);
        $all = collect();
        for ($i = 0; $i < 20 && ($batch = $this->heartbeat($secret)['commands']); $i++) {
            foreach ($batch as $c) {
                $all->push($c);
                $this->commandResult($secret, $c['id'], 'succeeded', ['output' => []]);
            }
        }
        $site = $all->where('type', 'fs.write')->firstWhere('payload.path', 'sites/shop.example.com/maintenance.html');
        $this->assertStringContainsString('Back at 6 pm', $site['payload']['content']);

        // PUB-1: a suspended app shows the 403 page once maintenance ends.
        $this->api($token)->patchJson("/api/v1/public-pages/sites/{$domain->id}", ['maintenance' => false])->assertOk();
        $app->update(['status' => 'suspended']);
        $this->assertSame(['dmp-pages-p-suspended'], $this->http($server)['routers'][$name.'-page']['middlewares']);

        // Platform-wide maintenance.
        $app->update(['status' => 'running']);
        $this->api($token)->patchJson('/api/v1/public-pages', ['maintenance' => true, 'retry_after' => 600])->assertOk()->assertJsonPath('data.maintenance', true);
        $this->assertSame(['dmp-pages-p-maintenance'], $this->http($server)['routers'][$name.'-page']['middlewares']);
        $this->assertStringContainsString('Retry-After 600', app(PublicPagesService::class)->nginxConf());

        $this->api($token)->patchJson('/api/v1/public-pages', ['pages' => ['not_found' => ['link_url' => 'javascript:alert(1)']]])->assertStatus(422);
        $this->assertStringContainsString('We will be right back', $this->api($token)->getJson('/api/v1/public-pages/preview/maintenance')->assertOk()->json('data.html'));
        [, , $viewer] = $this->org('viewer');
        $this->api($viewer)->patchJson('/api/v1/public-pages', ['maintenance' => false])->assertForbidden();
    }
}
