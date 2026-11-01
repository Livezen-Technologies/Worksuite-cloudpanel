<?php

namespace Tests\Feature;

use App\Models\Container;
use App\Models\Domain;
use App\Models\Server;
use App\Services\TraefikConfigService;
use Tests\TestCase;

/**
 * An ingress whose Traefik runs no docker provider has nothing to turn an app's container
 * labels into `app-<name>@docker`, so the service its routers name must be declared in the
 * pushed file instead. Traefik drops a router whose service does not resolve, which makes the
 * domain answer the catch-all 404 while its certificate still terminates correctly.
 */
class TraefikAppServiceTest extends TestCase
{
    /** A deployed app with a domain; returns [token, secret, appId, server]. */
    private function deployed(): array
    {
        config(['platform.drivers.certificates' => 'traefik']);
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['tags' => ['ingress']]);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $appId = $this->api($token)->postJson('/api/v1/applications', ['name' => 'shop', 'source_type' => 'image', 'image' => 'ghcr.io/acme/shop:1.0', 'server_id' => $server->id, 'port' => 8080])->assertCreated()->json('data.id');
        $this->api($token)->postJson('/api/v1/domains', ['name' => 'shop.acme.test', 'application_id' => $appId, 'target_port' => 8080])->assertCreated();
        // Only a verified domain is rendered into the ingress configuration.
        Domain::allTenants()->where('name', 'shop.acme.test')->update(['dns_status' => 'verified']);
        $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->assertStatus(202);
        $create = $this->heartbeat($secret)['commands'][0];
        $this->commandResult($secret, $create['id'], 'succeeded', ['output' => ['container_id' => 'd-1']])->assertOk();
        $this->passHealthChecks($secret);
        $this->heartbeat($secret);

        return [$token, $secret, $appId, $server];
    }

    private function http(Server $server): array
    {
        return json_decode(json_encode(app(TraefikConfigService::class)->render($server)), true)['http'];
    }

    public function test_the_app_service_is_declared_in_the_file_when_there_is_no_docker_provider(): void
    {
        [, , , $server] = $this->deployed();

        // Default: discovered from the container's labels, nothing declared here.
        $http = $this->http($server);
        $router = collect($http['routers'])->first(fn ($r) => ($r['service'] ?? '') === 'app-shop@docker');
        $this->assertNotNull($router, 'the domain router names the app service');
        $this->assertArrayNotHasKey('app-shop', $http['services'] ?? []);

        config(['platform.traefik.docker_service_suffix' => '@file']);
        $http = $this->http($server);
        $this->assertNotNull(collect($http['routers'])->first(fn ($r) => ($r['service'] ?? '') === 'app-shop@file'));
        $this->assertSame(
            [['url' => 'http://app-shop:8080']],
            $http['services']['app-shop']['loadBalancer']['servers'],
            'app-shop@file has to resolve to a service in this same file'
        );
    }

    public function test_every_replica_on_the_app_server_is_listed_as_the_docker_provider_would_have(): void
    {
        [$token, $secret, $appId, $server] = $this->deployed();
        config(['platform.traefik.docker_service_suffix' => '@file']);
        $this->api($token)->patchJson("/api/v1/applications/{$appId}/scaling", ['replicas' => 3])->assertOk();
        foreach ($this->heartbeat($secret)['commands'] as $i => $c) {
            $this->commandResult($secret, $c['id'], 'succeeded', ['output' => ['container_id' => 'd-r'.$i]])->assertOk();
        }

        $this->assertSame(
            ['http://app-shop:8080', 'http://app-shop-r2:8080', 'http://app-shop-r3:8080'],
            array_column($this->http($server)['services']['app-shop']['loadBalancer']['servers'], 'url')
        );
    }

    public function test_a_removed_container_is_left_out_and_an_unreported_one_falls_back_to_its_name(): void
    {
        [, , $appId, $server] = $this->deployed();
        config(['platform.traefik.docker_service_suffix' => '@file']);

        Container::allTenants()->where('application_id', $appId)->update(['state' => 'removed']);
        $this->assertSame(
            [['url' => 'http://app-shop:8080']],
            $this->http($server)['services']['app-shop']['loadBalancer']['servers'],
            'with no container reported it still routes, by the name the panel gives it'
        );
    }
}
