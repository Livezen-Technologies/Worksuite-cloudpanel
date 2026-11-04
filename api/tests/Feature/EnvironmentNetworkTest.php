<?php

namespace Tests\Feature;

use App\Models\AgentCommand;
use App\Models\Application;
use App\Models\Network;
use App\Models\Server;
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

    public function test_reconcile_creates_a_network_on_a_server_another_organisation_owns(): void
    {
        // livezen-panel-01 belongs to one organisation and hosts others' apps.
        // The reconcile used to resolve the server inside the app's tenant, so
        // find() returned null and the environment was skipped without a word.
        [, $tenant, $token] = $this->org();
        $this->server($tenant, ['region' => 'eu-central', 'ram_mb' => 32768, 'ram_pct' => 10]);
        $app = $this->hosted($token, $tenant);

        // Placement only ever picks a server in the app's own organisation, so
        // the shared-server state has to be built the way production got it:
        // the app was moved onto a server another organisation owns.
        [, $owner] = $this->org();
        $foreign = $this->server($owner, ['region' => 'eu-central', 'ram_mb' => 32768, 'ram_pct' => 10]);
        Application::allTenants()->where('id', $app->id)->update(['server_id' => $foreign->id]);
        $server = $foreign;

        Network::allTenants()->whereNotNull('environment_id')->delete();
        $this->artisan('networks:reconcile')->expectsOutputToContain('Environment networks requested: 1')->assertExitCode(0);

        $network = Network::allTenants()->where('environment_id', $app->environment_id)->first();
        $this->assertNotNull($network, 'the environment got its network');
        $this->assertSame($server->id, $network->server_id);
        $this->assertSame($tenant->id, $network->organization_id, 'owned by the app\'s organisation, not the server\'s');
    }

    /**
     * Completes the `network.create` the agent was given, the way a real agent
     * would, and returns [agent secret, the environment's Network].
     */
    private function completeNetworkCreate(Application $app, $server, string $token): array
    {
        [$secret] = $this->enrollAgent($server, $token);
        // The heartbeat is what tells the panel which agent version is out
        // there, and the ingress attach is gated on it, so this has to report
        // the version the fleet is actually updated to.
        $beat = $this->heartbeat($secret, ['agent_version' => $server->agent_version ?: '1.2.5']);
        $create = collect($beat['commands'])->firstWhere('type', 'network.create');
        $this->assertNotNull($create, 'the agent was asked to create the environment network');
        $this->commandResult($secret, $create['id'], 'succeeded', ['output' => ['network_id' => 'net123']])->assertOk();

        return [$secret, Network::allTenants()->where('environment_id', $app->environment_id)->first()];
    }

    public function test_the_ingress_is_attached_once_the_environment_network_exists_and_only_once(): void
    {
        // Traefik reaches a container by Docker DNS over a shared network, so
        // it must be on the environment network before anything can leave
        // `proxy`. The attach is additive: no routing changes either way.
        config(['platform.traefik.container' => 'host-traefik-1']);
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['region' => 'eu-central', 'ram_mb' => 32768, 'ram_pct' => 10, 'tags' => ['ingress'], 'agent_version' => '1.2.5']);
        $app = $this->hosted($token, $org);
        [$secret, $network] = $this->completeNetworkCreate($app, $server, $token);

        $this->assertSame('active', $network->fresh()->status);
        $connect = AgentCommand::where('server_id', $server->id)->where('type', 'network.connect')->get();
        $this->assertCount(1, $connect, 'one attach, dispatched when the network went active');
        $this->assertSame(['network' => $network->name, 'container' => 'host-traefik-1'], $connect->first()->payload);

        // Confirmed by the agent, the panel records it and stops asking.
        $this->commandResult($secret, $connect->first()->id, 'succeeded', ['output' => ['attached' => true]])->assertOk();
        $this->assertNotNull($network->fresh()->ingress_attached_at, 'the agent confirmed it');

        $this->artisan('networks:reconcile')->assertExitCode(0);
        $this->assertSame(1, AgentCommand::where('server_id', $server->id)->where('type', 'network.connect')->count(),
            'a confirmed attach is not re-queued every fifteen minutes');
    }

    public function test_the_reconcile_attaches_an_ingress_that_was_never_confirmed(): void
    {
        // The three networks already on the live panel were created before the
        // attach existed, so the reconcile has to pick them up retroactively.
        config(['platform.traefik.container' => 'host-traefik-1']);
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['region' => 'eu-central', 'ram_mb' => 32768, 'ram_pct' => 10, 'tags' => ['ingress'], 'agent_version' => '1.2.5']);
        $app = $this->hosted($token, $org);
        [, $network] = $this->completeNetworkCreate($app, $server, $token);

        Network::allTenants()->where('id', $network->id)->update(['ingress_attached_at' => null]);
        AgentCommand::where('type', 'network.connect')->delete();

        $this->artisan('networks:reconcile')->assertExitCode(0);
        $this->assertSame(1, AgentCommand::where('server_id', $server->id)->where('type', 'network.connect')->count(),
            'the missing attach was dispatched');
    }

    public function test_no_ingress_attach_is_dispatched_when_no_container_is_configured(): void
    {
        // An installation whose ingress is not a container we manage must not
        // have commands queued at it that can never succeed.
        config(['platform.traefik.container' => '']);
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['region' => 'eu-central', 'ram_mb' => 32768, 'ram_pct' => 10, 'tags' => ['ingress'], 'agent_version' => '1.2.5']);
        $this->hosted($token, $org);

        $this->assertSame(0, AgentCommand::where('server_id', $server->id)->where('type', 'network.connect')->count());
    }

    public function test_an_old_agent_is_never_asked_to_attach_a_network(): void
    {
        // The fleet ran 1.2.4 when this shipped. Dispatching to it would fail
        // the command on every reconcile, for ever, and fix nothing.
        config(['platform.traefik.container' => 'host-traefik-1']);
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['region' => 'eu-central', 'ram_mb' => 32768, 'ram_pct' => 10,
            'tags' => ['ingress'], 'agent_version' => '1.2.4']);
        $app = $this->hosted($token, $org);
        $this->completeNetworkCreate($app, $server, $token);

        $this->assertSame(0, AgentCommand::where('type', 'network.connect')->count(), 'nothing asked of a 1.2.4 agent');
        $this->assertNull(Network::allTenants()->where('environment_id', $app->environment_id)->value('ingress_attached_at'));

        // And it happens by itself once the agent reports a new enough version.
        Server::allTenants()->where('id', $server->id)->update(['agent_version' => '1.2.5']);
        $this->artisan('networks:reconcile')->assertExitCode(0);
        $this->assertSame(1, AgentCommand::where('type', 'network.connect')->count(), 'attached after the update');
    }

    public function test_finding_an_existing_environment_network_does_not_need_a_tenant(): void
    {
        // A deploy driven by an agent command result runs with no tenant set.
        // A tenant-scoped lookup finds nothing, tries to create the network a
        // second time and dies on the (server_id, name) unique index, which
        // surfaces as a 500 from inside the deployment pipeline.
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['region' => 'eu-central', 'ram_mb' => 32768, 'ram_pct' => 10]);
        $app = $this->hosted($token, $org);
        $environment = \App\Models\Environment::allTenants()->find($app->environment_id);
        $existing = Network::allTenants()->where('environment_id', $environment->id)->firstOrFail();

        app(\App\Support\Tenant::class)->set(null);
        $found = app(NetworkService::class)->forEnvironment($environment, Server::allTenants()->find($server->id));

        $this->assertSame($existing->id, $found->id, 'the same network, not a second one');
        $this->assertSame(1, Network::allTenants()->where('environment_id', $environment->id)->count());
        $this->assertSame($org->id, $found->organization_id, 'and it still belongs to the customer');
    }

    public function test_two_environments_created_in_the_same_millisecond_get_different_networks(): void
    {
        // The first twelve hex characters of a UUIDv7 are its millisecond
        // timestamp, so a prefix is not an identifier. Environments created
        // back to back -- which is what duplicating one does -- collided on
        // the (server_id, name) unique index and 500'd the deploy that hit it.
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['region' => 'eu-central', 'ram_mb' => 32768, 'ram_pct' => 10]);
        $project = \App\Models\Project::allTenants()->create(['organization_id' => $org->id, 'name' => 'alpha']);

        $envs = collect(['production', 'staging', 'preview'])->map(fn ($kind) => \App\Models\Environment::allTenants()->create([
            'organization_id' => $org->id, 'project_id' => $project->id, 'name' => ucfirst($kind), 'slug' => $kind, 'kind' => $kind,
        ]));
        // Deterministic, rather than hoping three inserts land in one
        // millisecond: two ids that share their timestamp must still differ.
        $a = new \App\Models\Environment(['name' => 'a']);
        $b = new \App\Models\Environment(['name' => 'b']);
        $a->id = '01a19bff-7455-701a-b138-f7179a3c216e';
        $b->id = '01a19bff-7455-7abc-9def-0123456789ab';
        $this->assertSame(substr(str_replace('-', '', $a->id), 0, 12), substr(str_replace('-', '', $b->id), 0, 12),
            'same millisecond: the old twelve-character prefix was identical');
        $this->assertNotSame(NetworkService::environmentNetworkName($a), NetworkService::environmentNetworkName($b),
            'and the network names must not be');

        $names = $envs->map(fn ($e) => NetworkService::environmentNetworkName($e));
        $this->assertCount(3, $names->unique(), 'every environment still gets its own network name');

        // And all three are genuinely creatable on one host.
        $service = app(NetworkService::class);
        $server = Server::allTenants()->find($server->id);
        $made = $envs->map(fn ($e) => $service->forEnvironment($e, $server)->name);
        $this->assertCount(3, $made->unique());
    }

    public function test_losing_the_race_to_create_the_network_returns_the_winners_row(): void
    {
        // A deploy and the reconcile are different processes resolving the same
        // (environment, server) pair, so withoutOverlapping does not help. On
        // the live panel the scheduled reconcile died on
        // networks_server_id_name_unique at 11:30:07 while a manual run was
        // creating the same row. Losing that race must not be an error.
        [, $org, $token] = $this->org();
        $server = Server::allTenants()->find($this->server($org, ['region' => 'eu-central', 'ram_mb' => 32768, 'ram_pct' => 10])->id);
        $environment = \App\Models\Environment::allTenants()->create([
            'organization_id' => $org->id, 'name' => 'Production', 'slug' => 'production', 'kind' => 'production',
        ]);
        $name = NetworkService::environmentNetworkName($environment);

        // The winner's row as the other process left it: the right name on the
        // right server, but not yet visible to a lookup by environment.
        $winnerId = (string) \Illuminate\Support\Str::uuid7();
        \Illuminate\Support\Facades\DB::table('networks')->insert([
            'id' => $winnerId, 'organization_id' => $org->id, 'server_id' => $server->id,
            'name' => $name, 'driver' => 'bridge', 'internal' => false, 'exposure' => 'private',
            'status' => 'creating', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $found = app(NetworkService::class)->forEnvironment($environment, $server);

        $this->assertSame($winnerId, $found->id, "the winner's row, not an exception");
        $this->assertSame(1, Network::allTenants()->where('server_id', $server->id)->where('name', $name)->count(),
            'and no second row for the same network');
    }
}
