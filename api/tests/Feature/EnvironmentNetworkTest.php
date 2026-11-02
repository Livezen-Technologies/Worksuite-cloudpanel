<?php

namespace Tests\Feature;

use App\Models\AgentCommand;
use App\Models\Application;
use App\Models\Network;
use App\Services\NetworkService;
use Tests\TestCase;

/**
 * PRJ-NET step one: each environment gets a Docker network of its own, and
 * containers join it *in addition to* the shared `proxy` network.
 *
 * The two things these tests exist to pin down are both about not breaking a
 * live platform: a container is never told to join a network the host has not
 * confirmed yet, and `proxy` is never taken away — Traefik reaches containers
 * by name over it, so losing it would 502 every site.
 */
class EnvironmentNetworkTest extends TestCase
{
    private function hosted(string $token, $org, string $name = 'shop'): Application
    {
        $id = $this->api($token)->postJson('/api/v1/app/apps', [
            'name' => $name, 'source' => ['type' => 'image', 'image' => 'nginx:1.27'],
            'port' => 80, 'region' => 'eu-central', 'size' => 'small',
        ])->assertStatus(202)->json('data.app.id');

        return Application::allTenants()->find($id);
    }

    public function test_an_environment_gets_one_network_per_server_and_asks_the_agent_for_it(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['region' => 'eu-central', 'ram_mb' => 32768, 'ram_pct' => 10]);
        $app = $this->hosted($token, $org);

        $env = \App\Models\Environment::allTenants()->find($app->environment_id);
        $network = Network::allTenants()->where('environment_id', $env->id)->where('server_id', $server->id)->first();
        $this->assertNotNull($network, 'hosting an app asks for its environment network');
        $this->assertSame('creating', $network->status, 'it is not usable until the agent confirms it');
        $this->assertSame($env->project_id, $network->project_id);
        $this->assertStringStartsWith('wscp-env-', $network->name);

        $command = AgentCommand::where('server_id', $server->id)->where('type', 'network.create')->latest()->first();
        $this->assertNotNull($command);
        $this->assertSame($network->name, $command->payload['name']);
        $this->assertTrue($command->payload['attachable']);
        $this->assertSame($env->id, $command->payload['labels']['com.worksuite.environment']);

        // Asking twice does not make a second network or a second command.
        app(NetworkService::class)->forEnvironment($env, $server);
        $this->assertSame(1, Network::allTenants()->where('environment_id', $env->id)->count());
        $this->assertSame(1, AgentCommand::where('server_id', $server->id)->where('type', 'network.create')->count());
    }

    public function test_a_container_joins_the_environment_network_only_once_it_exists(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['region' => 'eu-central', 'ram_mb' => 32768, 'ram_pct' => 10]);
        $app = $this->hosted($token, $org);

        $create = AgentCommand::where('server_id', $server->id)->where('type', 'container.create')->orderByDesc('created_at')->orderByDesc('id')->first();
        $this->assertNotNull($create, 'the app was deployed');
        $names = array_column($create->payload['networks'] ?? [], 'name');
        $this->assertContains('proxy', $names, 'proxy is never given up: Traefik routes over it');
        $this->assertNotContains(NetworkService::environmentNetworkName(
            \App\Models\Environment::allTenants()->find($app->environment_id)
        ), $names, 'the network is not confirmed yet, so joining it would fail the deploy');

        // Once the agent reports the network is there, the next deployment joins it.
        Network::allTenants()->where('environment_id', $app->environment_id)->update(['status' => 'active']);
        $this->api($token)->postJson("/api/v1/app/apps/{$app->id}/redeploy")->assertStatus(202);

        $create = AgentCommand::where('server_id', $server->id)->where('type', 'container.create')->orderByDesc('created_at')->orderByDesc('id')->first();
        $nets = collect($create->payload['networks'] ?? [])->keyBy('name');
        $envName = NetworkService::environmentNetworkName(\App\Models\Environment::allTenants()->find($app->environment_id));
        $this->assertTrue($nets->has('proxy'), 'still on proxy');
        $this->assertTrue($nets->has($envName), 'and now on its own environment network');
        $this->assertContains($app->name, $nets[$envName]['aliases'], 'reachable by the name siblings resolve');
    }

    public function test_apps_of_different_environments_get_different_networks(): void
    {
        [, $org, $token] = $this->org();
        $this->server($org, ['region' => 'eu-central', 'ram_mb' => 32768, 'ram_pct' => 10]);
        $a = $this->hosted($token, $org, 'one');
        $p = $this->api($token)->postJson('/api/v1/app/projects', ['name' => 'Other', 'environment' => 'production'])->assertCreated()->json('data');
        $bId = $this->api($token)->postJson('/api/v1/app/apps', [
            'name' => 'two', 'source' => ['type' => 'image', 'image' => 'nginx:1.27'],
            'port' => 80, 'region' => 'eu-central', 'size' => 'small', 'project_id' => $p['id'],
        ])->assertStatus(202)->json('data.app.id');
        $b = Application::allTenants()->find($bId);

        $this->assertNotSame($a->environment_id, $b->environment_id);
        $this->assertSame(2, Network::allTenants()->whereNotNull('environment_id')->count());
        $this->assertNotSame(
            Network::allTenants()->where('environment_id', $a->environment_id)->value('name'),
            Network::allTenants()->where('environment_id', $b->environment_id)->value('name'),
        );
    }

    public function test_the_reconcile_command_fills_in_environments_that_have_none(): void
    {
        [, $org, $token] = $this->org();
        $this->server($org, ['region' => 'eu-central', 'ram_mb' => 32768, 'ram_pct' => 10]);
        $this->hosted($token, $org);
        Network::allTenants()->whereNotNull('environment_id')->delete();

        $this->artisan('networks:reconcile')->assertExitCode(0);
        $this->assertSame(1, Network::allTenants()->whereNotNull('environment_id')->count());

        // Running it again is a no-op.
        $this->artisan('networks:reconcile')->assertExitCode(0);
        $this->assertSame(1, Network::allTenants()->whereNotNull('environment_id')->count());
    }
}
