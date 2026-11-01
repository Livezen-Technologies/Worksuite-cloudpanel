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
        $this->assertArrayNotHasKey('default/captcha.html', $writes->all(), 'the bouncer serves the bot pages, not the edge container');
        foreach (array_diff(array_keys(PublicPagesService::PAGES), PublicPagesService::BOT_PAGES) as $key) {
            $html = $writes["default/{$key}.html"]['payload']['content'];
            $this->assertLessThan(50 * 1024, strlen($html), "{$key} is under 50 KB");
            $this->assertStringNotContainsString('<script', $html);
        }
        $this->assertStringContainsString('http-equiv="refresh" content="30"', $writes['default/busy.html']['payload']['content']);
    }

    /**
     * The container reads the volume as its own unprivileged uid, and the
     * agent's unit runs UMask=0077, so the modes fs.write and fs.mkdir pass to
     * open(2) and mkdir(2) land as 0600 and 0700. Without an explicit chmod
     * nginx cannot read one page and every branded page answers 404 behind a
     * valid certificate.
     */
    public function test_the_pages_are_made_readable_by_the_unprivileged_container(): void
    {
        [$token, $secret, , , $domain] = $this->setUpEdge();
        $domain->update(['options' => ['pages' => ['text' => ['suspended' => ['title' => 'Away']]]]]);
        $this->api($token)->postJson('/api/v1/public-pages/install')->assertStatus(202);
        $cmds = collect($this->heartbeat($secret)['commands']);

        $chmods = $cmds->where('type', 'fs.chmod')->pluck('payload');
        $modes = $chmods->mapWithKeys(fn ($p) => [$p['volume'].':'.$p['mode'] => $p['paths']]);

        $this->assertSame('0755', $chmods->firstWhere('mode', '0755')['mode'] ?? null, 'folders are made traversable');
        $this->assertEqualsCanonicalizing(
            ['default', 'sites', 'sites/shop.example.com'],
            $modes[PublicPagesService::HTML_VOLUME.':0755'],
            'every folder written here, per-site folders included'
        );

        $files = $modes[PublicPagesService::HTML_VOLUME.':0644'];
        foreach (array_diff(array_keys(PublicPagesService::PAGES), PublicPagesService::BOT_PAGES) as $key) {
            $this->assertContains("default/{$key}.html", $files);
        }
        $this->assertContains('sites/shop.example.com/suspended.html', $files, 'per-site overrides too');
        $this->assertSame(['default.conf'], $modes[PublicPagesService::CONF_VOLUME.':0644'], 'and the nginx config');

        // Every path written must be chmodded, or that page alone 404s.
        $written = $cmds->where('type', 'fs.write')->map(fn ($c) => $c['payload']['volume'].':'.$c['payload']['path']);
        $chmodded = $chmods->flatMap(fn ($p) => array_map(fn ($x) => $p['volume'].':'.$x, $p['paths']));
        $this->assertEmpty($written->diff($chmodded)->all(), 'a written file with no chmod would be unreadable');
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

    /**
     * An ingress whose Traefik runs no docker provider cannot resolve
     * ws-pages@docker, and Traefik drops any router whose middleware chain does
     * not resolve - so every domain carrying the errors middleware falls
     * through to the catch-all and answers 404. With the suffix set to "@file"
     * the service has to be declared in the pushed file instead.
     */
    public function test_pages_service_is_declared_in_the_file_when_there_is_no_docker_provider(): void
    {
        [$token, $secret, $server] = $this->setUpEdge();
        $this->api($token)->postJson('/api/v1/public-pages/install')->assertStatus(202);
        $this->heartbeat($secret);

        // Default: discovered from the container's labels, nothing declared here.
        $http = $this->http($server);
        $this->assertSame('ws-pages@docker', $http['middlewares']['dmp-pages-errors']['errors']['service']);
        $this->assertArrayNotHasKey('ws-pages', $http['services'] ?? []);

        config([
            'platform.traefik.docker_service_suffix' => '@file',
            'platform.traefik.pages_url' => 'http://ws-pages:8080',
        ]);
        $http = $this->http($server);
        $this->assertSame('ws-pages@file', $http['middlewares']['dmp-pages-errors']['errors']['service']);
        $this->assertSame(
            'http://ws-pages:8080',
            $http['services']['ws-pages']['loadBalancer']['servers'][0]['url'],
            'ws-pages@file has to resolve to a service in this same file'
        );
        $this->assertSame('ws-pages@file', $http['routers']['dmp-pages-catchall']['service']);
    }

    /** Traefik reaches the container by name, which the default bridge does not allow. */
    public function test_the_pages_container_joins_the_proxy_network(): void
    {
        [$token, $secret] = $this->setUpEdge();
        $this->api($token)->postJson('/api/v1/public-pages/install')->assertStatus(202);
        $create = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'container.create')['payload'];
        $this->assertSame([['name' => 'proxy']], $create['networks']);
    }
}
