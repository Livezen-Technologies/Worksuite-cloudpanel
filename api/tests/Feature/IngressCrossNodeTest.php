<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\AgentCommand;
use App\Models\Application;
use App\Models\Domain;
use App\Models\Server;
use App\Services\EdgeRoutingService;
use App\Services\EnterpriseProvisioningService;
use App\Services\TraefikConfigService;
use App\Support\Tenant;
use Tests\TestCase;

/**
 * XNODE-3: the ingress routes domains of apps homed on another node to that node's public IP
 * at a fixed host port, which the node filters to the ingress IPs (agent edge.ports). No
 * WireGuard mesh is involved; a node with no public IP is left out and reported.
 */
class IngressCrossNodeTest extends TestCase
{
    /** [token, ingress, remote node, org] */
    private function fleet(array $remote = [], array $ingress = []): array
    {
        config(['platform.drivers.certificates' => 'traefik']);
        [, $org, $token] = $this->org();
        $in = $this->server($org, array_merge(['name' => 'panel-01', 'ip' => '51.89.7.73', 'tags' => ['ingress']], $ingress));
        $node = $this->server($org, array_merge(['name' => 'node-05', 'ip' => '203.0.113.5'], $remote));

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
        [$token, $in] = $this->fleet();
        $app = $this->app($token, $in);
        $this->assertFalse((bool) $app->mesh_network);

        $http = $this->http($in);
        $this->assertSame('app-shop@docker', $this->routerFor($http, 'shop.acme.test')['service']);
        $this->assertArrayNotHasKey('app-shop', $http['services'] ?? []);
        $this->assertArrayNotHasKey('edge_port', (array) $app->fresh()->source, 'no host port for an app on the ingress');
    }

    public function test_a_remote_app_is_routed_to_its_node_public_ip_with_a_health_check(): void
    {
        [$token, $in, $node] = $this->fleet();
        $app = $this->app($token, $node);
        $this->assertFalse((bool) $app->mesh_network, 'no mesh needed');

        $port = app(EdgeRoutingService::class)->port($app);
        $this->assertGreaterThanOrEqual(51000, $port);
        $this->assertLessThan(61000, $port);
        $this->assertSame($port, app(EdgeRoutingService::class)->port($app->fresh()), 'stable');

        $http = $this->http($in);
        $this->assertSame('app-shop', $this->routerFor($http, 'shop.acme.test')['service']);
        $lb = $http['services']['app-shop']['loadBalancer'];
        $this->assertSame([['url' => "http://203.0.113.5:{$port}"]], $lb['servers']);
        $this->assertSame(['path' => '/', 'interval' => '10s', 'timeout' => '3s'], $lb['healthCheck']);
        $svc = app(TraefikConfigService::class);
        $svc->render($in);
        $this->assertSame([], $svc->unroutable());

        config(['platform.edge.health_path' => '/healthz']);
        $this->assertSame('/healthz', $this->http($in)['services']['app-shop']['loadBalancer']['healthCheck']['path']);
    }

    public function test_two_apps_on_one_node_never_share_a_host_port(): void
    {
        [$token, , $node] = $this->fleet();
        config(['platform.edge.port_span' => 1, 'platform.edge.port_base' => 52000]);
        $a = $this->app($token, $node, 'one');
        $this->assertSame(52000, app(EdgeRoutingService::class)->port($a));
        config(['platform.edge.port_span' => 2]);
        $b = $this->app($token, $node, 'two');
        $this->assertSame(52001, app(EdgeRoutingService::class)->port($b));
    }

    public function test_a_remote_node_without_a_public_ip_publishes_no_broken_url(): void
    {
        [$token, $in, $node] = $this->fleet(['ip' => '']);
        $this->app($token, $in, 'local');
        $this->app($token, $node);

        $http = $this->http($in);
        $this->assertNull($this->routerFor($http, 'shop.acme.test'));
        $this->assertArrayNotHasKey('app-shop', $http['services'] ?? []);
        $this->assertNotNull($this->routerFor($http, 'local.acme.test'));
        $svc = app(TraefikConfigService::class);
        $svc->render($in);
        $this->assertStringContainsString('node-05 has no public IP', implode(' ', $svc->unroutable()));
    }

    public function test_backend_tls_flag_refuses_rather_than_silently_sending_http(): void
    {
        [$token, $in, $node] = $this->fleet();
        $this->app($token, $node);
        config(['platform.edge.backend_tls' => true]);
        $svc = app(TraefikConfigService::class);
        $http = json_decode(json_encode($svc->render($in)), true)['http'] ?? [];
        $this->assertNull($this->routerFor($http, 'shop.acme.test'));
        $this->assertStringContainsString('EDGE_BACKEND_TLS', implode(' ', $svc->unroutable()));
    }

    public function test_the_host_port_is_filtered_to_the_ingress_ips_only(): void
    {
        [$token, $in, $node, $org] = $this->fleet();
        $this->server($org, ['name' => 'frontdoor-01', 'ip' => '51.254.220.203', 'tags' => ['ingress', 'enterprise-edge']]);
        $plain = $this->app($token, $node, 'plain');
        $ent = $this->app($token, $node, 'ent');
        $ent->update(['source' => array_merge((array) $ent->source, ['enterprise_deployment_id' => 'dep-1'])]);
        $routing = app(EdgeRoutingService::class);

        $rules = collect($routing->firewallFor($node)['rules'])->keyBy('port');
        $this->assertSame(['51.89.7.73'], $rules[$routing->port($plain)]['allow'], 'a plain app: the non-edge ingress only');
        $this->assertSame(['51.254.220.203'], $rules[$routing->port($ent->fresh())]['allow'], 'an enterprise app: the edges only');
        $this->assertSame(['rules' => []], $routing->firewallFor($in), 'nothing on an ingress');

        $this->assertTrue($routing->syncFirewall($node));
        $this->assertFalse($routing->syncFirewall($node), 'unchanged rules are not resent');
        $cmd = AgentCommand::allTenants()->where('server_id', $node->id)->where('type', 'edge.ports')->sole();
        $this->assertCount(2, $cmd->payload['rules']);
        $this->assertSame(0, AgentCommand::allTenants()->where('server_id', $in->id)->where('type', 'edge.ports')->count());
    }

    public function test_a_deploy_on_a_remote_node_publishes_the_host_port_after_filtering_it(): void
    {
        [$token, , $node] = $this->fleet();
        $app = $this->app($token, $node);
        $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $app->id])->assertStatus(202);

        $types = AgentCommand::allTenants()->where('server_id', $node->id)->orderBy('created_at')->orderBy('id')->pluck('type')->all();
        $this->assertContains('edge.ports', $types);
        $create = AgentCommand::allTenants()->where('server_id', $node->id)->where('type', 'container.create')->latest()->first();
        $this->assertNotNull($create, 'deploy dispatched');
        $this->assertLessThan(array_search('container.create', $types, true), array_search('edge.ports', $types, true), 'filter first');
        $port = app(EdgeRoutingService::class)->port($app->fresh());
        $this->assertSame($port, (int) $create->payload['ports'][0]['host_port']);
        $this->assertSame(8080, (int) $create->payload['ports'][0]['container_port']);
    }

    public function test_enterprise_placement_needs_a_public_ip_not_the_mesh(): void
    {
        [, , , $org] = $this->fleet();
        $off = $this->server($org, ['name' => 'ent-off', 'ip' => '', 'ram_mb' => 65536, 'tags' => ['enterprise']]);
        app(Tenant::class)->set(null);
        $svc = app(EnterpriseProvisioningService::class);
        $size = config('enterprise.sizes.small');

        try {
            $svc->place($org->id, $size, $off->id, null);
            $this->fail('placement on a remote node without a public IP must be refused');
        } catch (ApiException $e) {
            $this->assertStringContainsString('no public IP', $e->getMessage().json_encode((array) $e));
        }

        $off->update(['ip' => '203.0.113.9']);
        $placed = $svc->place($org->id, $size, $off->id, null);
        $this->assertSame($off->id, $placed->id);
        $this->assertNull($placed->mesh_ip, 'off the mesh and still placed');
        $this->assertTrue(EdgeRoutingService::offIngress($placed));
        $this->assertFalse(EdgeRoutingService::offIngress(Server::allTenants()->where('name', 'panel-01')->first()));
    }
}
