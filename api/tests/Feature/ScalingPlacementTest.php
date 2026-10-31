<?php

namespace Tests\Feature;

use App\Contracts\DnsResolver;
use App\Models\Application;
use App\Models\Server;
use App\Services\ScalingService;
use App\Services\TraefikConfigService;
use App\Support\Tenant;
use Tests\TestCase;

/** SCL-6 placement across servers over the private network; SCL-7 weighted canaries. */
class ScalingPlacementTest extends TestCase
{
    public function test_copies_spread_over_mesh_servers_and_canaries_take_a_weighted_share(): void
    {
        config(['platform.drivers.certificates' => 'traefik']);
        [, $org, $token] = $this->org();
        $a = $this->server($org, ['name' => 'web-a', 'ip' => '198.51.100.9', 'mesh_ip' => '10.88.0.1']);
        $b = $this->server($org, ['name' => 'web-b', 'ip' => '198.51.100.10', 'mesh_ip' => '10.88.0.2']);
        [$secretA] = $this->enrollAgent($a, $token);
        [$secretB] = $this->enrollAgent($b, $token);
        $this->heartbeat($secretA);
        $this->heartbeat($secretB);
        $appId = $this->api($token)->postJson('/api/v1/applications', ['name' => 'shop', 'source_type' => 'image', 'image' => 'acme/shop:1', 'server_id' => $a->id, 'port' => 80])->assertCreated()->json('data.id');
        $domain = $this->api($token)->postJson('/api/v1/domains', ['name' => 'shop.acme.test', 'application_id' => $appId, 'target_port' => 80])->assertCreated()->json('data');
        app(DnsResolver::class)->set('shop.acme.test', 'A', ['198.51.100.9']);
        $this->api($token)->postJson("/api/v1/domains/{$domain['id']}/verify");
        \App\Models\Domain::allTenants()->whereKey($domain['id'])->update(['dns_status' => 'verified']);
        $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->assertStatus(202);
        $create = collect($this->heartbeat($secretA)['commands'])->firstWhere('type', 'container.create');
        $this->commandResult($secretA, $create['id'], 'succeeded', ['output' => ['container_id' => 'c1']])->assertOk();
        $this->passHealthChecks($secretA);
        $this->heartbeat($secretA);

        // Copies on B are reached on its mesh address only.
        $page = $this->api($token)->patchJson("/api/v1/applications/{$appId}/scaling", ['servers' => [$b->id], 'replicas' => 3])->assertOk()->json('data');
        $this->assertSame(['web-a', 'web-b'], array_column($page['placement'], 'name'));
        $onB = collect($this->heartbeat($secretB)['commands'])->firstWhere('type', 'container.create')['payload'];
        $port = ScalingService::hostPort(Application::allTenants()->find($appId), 2);
        $this->assertSame('app-shop-r2', $onB['name']);
        $this->assertSame([['container_port' => 80, 'host_port' => $port, 'protocol' => 'tcp', 'host_ip' => '10.88.0.2']], $onB['ports']);
        $this->assertArrayNotHasKey('networks', $onB);
        $this->assertSame(['io.dockermgr.managed' => 'true', 'io.dockermgr.app' => $appId, 'io.dockermgr.replica' => '2'], (array) $onB['labels']);
        $onA = collect($this->heartbeat($secretA)['commands'])->where('type', 'container.create')->pluck('payload.name')->all();
        $this->assertSame(['app-shop-r3'], $onA);

        $http = fn () => app(Tenant::class)->as($org->id, fn () => app(TraefikConfigService::class)->render(Server::allTenants()->find($a->id)))['http'];
        $cfg = $http();
        $router = collect((array) $cfg['routers'])->first();
        $this->assertSame('app-shop-pool', $router['service']);
        $this->assertSame(['http://10.88.0.2:'.$port, 'http://app-shop-r3:80', 'http://app-shop:80'], array_column($cfg['services']['app-shop-pool']['loadBalancer']['servers'], 'url'));
        $this->assertSame('/', $cfg['services']['app-shop-pool']['loadBalancer']['healthCheck']['path']);

        // SCL-7: a canary gets 20%, then 50%, then is promoted.
        $this->api($token)->postJson("/api/v1/applications/{$appId}/canary", ['image' => 'acme/shop:2', 'weight' => 20])->assertStatus(202);
        $canary = collect($this->heartbeat($secretA)['commands'])->firstWhere('type', 'container.create')['payload'];
        $this->assertSame(['app-shop-canary', 'acme/shop:2', '80'], [$canary['name'], $canary['image'], $canary['labels']['traefik.http.services.app-shop-canary.loadbalancer.server.port']]);
        $cfg = $http();
        $this->assertSame('app-shop-split', collect((array) $cfg['routers'])->first()['service']);
        $this->assertSame([['name' => 'app-shop-pool', 'weight' => 80], ['name' => 'app-shop-canary@docker', 'weight' => 20]], $cfg['services']['app-shop-split']['weighted']['services']);
        $this->api($token)->patchJson("/api/v1/applications/{$appId}/canary", ['weight' => 50])->assertOk()->assertJsonPath('data.settings.canary.weight', 50);
        $this->assertSame(50, $http()['services']['app-shop-split']['weighted']['services'][1]['weight']);

        $this->api($token)->postJson("/api/v1/applications/{$appId}/canary/promote")->assertStatus(202)->assertJsonPath('data.settings.canary', null);
        $this->assertSame('acme/shop:2', Application::allTenants()->find($appId)->image);
        $this->assertArrayNotHasKey('app-shop-split', $http()['services']);
        $this->api($token)->deleteJson("/api/v1/applications/{$appId}/canary")->assertStatus(409);

        // Customers cannot place copies or run canaries.
        Application::allTenants()->whereKey($appId)->update(['kind' => 'customer']);
        $this->api($token)->patchJson("/api/v1/app/apps/{$appId}/scaling", ['servers' => [$b->id]])->assertStatus(422);
    }
}
