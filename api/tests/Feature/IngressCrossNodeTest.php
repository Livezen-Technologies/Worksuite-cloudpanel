<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Domain;
use App\Models\Server;
use App\Services\EnterpriseProvisioningService;
use App\Services\TraefikConfigService;
use App\Support\Tenant;
use Tests\TestCase;

/**
 * XNODE-1: the one ingress (tagged `ingress`, runs Traefik) routes domains of apps whose
 * home server is another node over the WireGuard private network, at the app's dmp-mesh
 * address. A remote app it cannot reach is left out rather than published pointing nowhere.
 */
class IngressCrossNodeTest extends TestCase
{
    /** [token, ingress, remote node] */
    private function fleet(array $remote = [], array $ingress = []): array
    {
        config(['platform.drivers.certificates' => 'traefik']);
        [, $org, $token] = $this->org();
        $in = $this->server($org, array_merge(['name' => 'panel-01', 'tags' => ['ingress']], $ingress));
        $node = $this->server($org, array_merge(['name' => 'node-05'], $remote));

        return [$token, $in, $node, $org];
    }

    private function app(string $token, Server $server, string $name = 'shop'): Application
    {
        $id = $this->api($token)->postJson('/api/v1/applications', ['name' => $name, 'source_type' => 'image', 'image' => 'ghcr.io/acme/shop:1.0', 'server_id' => $server->id, 'port' => 8080])->assertCreated()->json('data.id');
        $this->api($token)->postJson('/api/v1/domains', ['name' => "{$name}.acme.test", 'application_id' => $id, 'target_port' => 8080])->assertCreated();
        Domain::allTenants()->where('name', "{$name}.acme.test")->update(['dns_status' => 'verified']);

        return Application::allTenants()->findOrFail($id);
    }

    private function http(Server $server): array
    {
        return json_decode(json_encode(app(TraefikConfigService::class)->render($server)), true)['http'] ?? [];
    }

    private function routerFor(array $http, string $host): ?array
    {
        return collect($http['routers'] ?? [])->first(fn ($r) => str_contains($r['rule'] ?? '', "`{$host}`"));
    }

    public function test_an_app_on_the_ingress_itself_is_unchanged(): void
    {
        [$token, $in] = $this->fleet([], ['mesh_ip' => '10.88.0.1']);
        $app = $this->app($token, $in);
        $this->assertFalse((bool) $app->mesh_network, 'no new network for apps on the ingress host');

        $http = $this->http($in);
        $this->assertSame('app-shop@docker', $this->routerFor($http, 'shop.acme.test')['service']);
        $this->assertArrayNotHasKey('app-shop', $http['services'] ?? []);

        config(['platform.traefik.docker_service_suffix' => '@file']);
        $this->assertSame([['url' => 'http://app-shop:8080']], $this->http($in)['services']['app-shop']['loadBalancer']['servers']);
    }

    public function test_a_remote_app_on_the_mesh_is_routed_to_its_mesh_address(): void
    {
        [$token, $in, $node] = $this->fleet(['mesh_ip' => '10.88.0.5'], ['mesh_ip' => '10.88.0.1']);
        $app = $this->app($token, $node);
        $this->assertTrue((bool) $app->mesh_network, 'placed off the ingress → joins dmp-mesh');

        $http = $this->http($in);
        $router = $this->routerFor($http, 'shop.acme.test');
        $this->assertSame('app-shop', $router['service'], 'the docker provider cannot see another node: a file service is named');
        $this->assertSame([['url' => 'http://10.89.5.10:8080']], $http['services']['app-shop']['loadBalancer']['servers']);
        $svc = app(TraefikConfigService::class);
        $svc->render($in);
        $this->assertSame([], $svc->unroutable());

        config(['platform.traefik.docker_service_suffix' => '@file']);
        $http = $this->http($in);
        $this->assertSame('app-shop@file', $this->routerFor($http, 'shop.acme.test')['service']);
        $this->assertSame([['url' => 'http://10.89.5.10:8080']], $http['services']['app-shop']['loadBalancer']['servers']);
    }

    public function test_a_remote_app_off_the_mesh_publishes_no_broken_url(): void
    {
        [$token, $in, $node] = $this->fleet([], ['mesh_ip' => '10.88.0.1']);
        $this->app($token, $in, 'local');
        $this->app($token, $node);

        $http = $this->http($in);
        $this->assertNull($this->routerFor($http, 'shop.acme.test'), 'no router for an unreachable app');
        $this->assertArrayNotHasKey('app-shop', $http['services'] ?? []);
        $this->assertStringNotContainsString('http://app-shop:', json_encode($http));
        $this->assertNotNull($this->routerFor($http, 'local.acme.test'), 'other sites keep routing');
        $svc = app(TraefikConfigService::class);
        $svc->render($in);
        $this->assertStringContainsString('node-05 is not on the private network', implode(' ', $svc->unroutable()));
    }

    public function test_enterprise_placement_needs_a_mesh_member_and_sets_mesh_network(): void
    {
        [, , , $org] = $this->fleet();
        $this->server($org, ['name' => 'ingress-x', 'tags' => ['ingress']]);
        $off = $this->server($org, ['name' => 'ent-off', 'ram_mb' => 65536, 'tags' => ['enterprise']]);
        app(Tenant::class)->set(null);
        $svc = app(EnterpriseProvisioningService::class);
        $size = config('enterprise.sizes.small');

        try {
            $svc->place($org->id, $size, $off->id, null);
            $this->fail('placement on a non-mesh remote node must be refused');
        } catch (\App\Exceptions\ApiException $e) {
            $this->assertStringContainsString('not on the private network', $e->getMessage().json_encode($e->getPrevious()?->getMessage()).json_encode((array) $e));
        }

        $off->update(['mesh_ip' => '10.88.0.7']);
        $placed = $svc->place($org->id, $size, $off->id, null);
        $this->assertSame($off->id, $placed->id);
        $this->assertTrue(Application::needsMesh($placed));
        $this->assertFalse(Application::needsMesh(Server::allTenants()->where('name', 'ingress-x')->first()));
    }
}
