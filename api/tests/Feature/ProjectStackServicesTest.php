<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Container;
use App\Models\Database;
use App\Models\Project;
use App\Support\Tenant;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** Databases, service containers and stacks that belong to a project, and how the dashboard shows them. */
class ProjectStackServicesTest extends TestCase
{
    public function test_project_database_service_container_and_dashboard(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $p = app(Tenant::class)->as($org->id, fn () => Project::create(['name' => 'Shop']));

        $db = $this->api($token)->postJson('/api/v1/databases', ['name' => 'shop-db', 'engine' => 'postgres', 'server_id' => $server->id, 'project_id' => $p->id])->assertStatus(202)->json('data.database');
        $this->assertSame($p->id, Database::allTenants()->find($db['id'])->project_id);

        $this->api($token)->postJson('/api/v1/containers', ['name' => 'shop-worker', 'image' => 'acme/worker:1', 'server_id' => $server->id, 'project_id' => $p->id])->assertStatus(202);
        $cmd = collect($this->heartbeat($secret)['commands'])->first(fn ($c) => $c['type'] === 'container.create' && ($c['payload']['name'] ?? '') === 'shop-worker');
        $this->assertSame($p->id, $cmd['payload']['labels']['io.dockermgr.project']);

        // As the agent would report them: the worker with its label, the database container with its own.
        app(Tenant::class)->as($org->id, function () use ($server, $p, $db) {
            Container::create(['server_id' => $server->id, 'name' => 'shop-worker-x', 'image' => 'acme/worker:1', 'state' => 'running', 'labels' => ['io.dockermgr.project' => $p->id]]);
            Container::allTenants()->where('name', 'db-shop-db')->update(['labels' => json_encode(['io.dockermgr.database' => $db['id']]), 'state' => 'running']);
            Application::create(['name' => 'shop', 'source_type' => 'image', 'image' => 'nginx', 'port' => 80, 'server_id' => $server->id, 'project_id' => $p->id, 'source' => ['database_id' => $db['id']]]);
        });
        Artisan::call('projects:link-containers');

        $d = $this->api($token)->getJson("/api/v1/projects/{$p->id}/dashboard")->assertOk()->json('data');
        $this->assertSame(1, $d['counts']['databases']);
        $this->assertSame('db-shop-db', $d['databases'][0]['host']);
        $this->assertSame(['shop'], $d['databases'][0]['used_by']);
        $names = collect($d['containers'])->pluck('name');
        $this->assertTrue($names->contains('shop-worker-x'));
        $this->assertTrue($names->contains('db-shop-db'));
    }
}
