<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Environment;
use App\Models\Network;
use App\Models\Project;
use App\Support\Tenant;
use Tests\TestCase;

/** An environment network names its owner, project and the apps in that environment. */
class NetworkOwnershipTest extends TestCase
{
    public function test_environment_networks_carry_owner_project_and_applications(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        app(Tenant::class)->as($org->id, function () use ($server) {
            $p = Project::create(['name' => 'Shop']);
            $env = Environment::create(['project_id' => $p->id, 'name' => 'Production', 'kind' => 'production']);
            Application::create(['name' => 'shop-web', 'source_type' => 'image', 'image' => 'nginx', 'project_id' => $p->id, 'environment_id' => $env->id]);
            Network::create(['server_id' => $server->id, 'name' => 'wscp-env-x', 'driver' => 'bridge', 'project_id' => $p->id, 'environment_id' => $env->id]);
            Network::create(['server_id' => $server->id, 'name' => 'internal', 'driver' => 'bridge']);
        });

        $rows = collect($this->api($token)->getJson('/api/v1/networks?per_page=200')->assertOk()->json('data'))->keyBy('name');
        $this->assertSame([$org->name, 'Shop', ['shop-web']], [$rows['wscp-env-x']['owner_name'], $rows['wscp-env-x']['project_name'], $rows['wscp-env-x']['applications']]);
        $this->assertSame([$org->name, null, []], [$rows['internal']['owner_name'], $rows['internal']['project_name'], $rows['internal']['applications']]);
    }
}
