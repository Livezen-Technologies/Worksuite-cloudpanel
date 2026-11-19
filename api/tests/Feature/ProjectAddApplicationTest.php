<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Support\Tenant;
use Tests\TestCase;

/** Project → Add application: a catalogue install lands in the project; an existing app can be moved in. */
class ProjectAddApplicationTest extends TestCase
{
    public function test_catalogue_install_lands_in_the_project_environment(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        $p = $this->api($token)->postJson('/api/v1/projects', ['name' => 'Ops'])->assertCreated()->json('data');
        $this->api($token)->getJson('/api/v1/templates')->assertOk();
        $res = $this->api($token)->postJson('/api/v1/templates/grafana/install', ['name' => 'metrics', 'server_id' => $server->id, 'project_id' => $p['id']])->assertCreated()->json('data');
        $app = Application::allTenants()->find($res['resource_id']);
        $this->assertSame($p['id'], $app->project_id);
        $env = Environment::allTenants()->find($app->environment_id);
        $this->assertSame($p['id'], $env->project_id);
        $this->assertSame('production', $env->kind);
    }

    public function test_admin_moves_an_existing_app_into_a_project(): void
    {
        [, $org, $token] = $this->org();
        [$app, $p] = app(Tenant::class)->as($org->id, fn () => [
            Application::create(['name' => 'legacy', 'source_type' => 'image', 'image' => 'nginx', 'port' => 80, 'environment' => 'production']),
            Project::create(['name' => 'Shop']),
        ]);
        $this->api($token)->postJson("/api/v1/projects/{$p->id}/apps", ['app_id' => $app->id])->assertOk();
        $app->refresh();
        $this->assertSame($p->id, $app->project_id);
        $this->assertSame($p->id, Environment::allTenants()->find($app->environment_id)->project_id);
    }
}
