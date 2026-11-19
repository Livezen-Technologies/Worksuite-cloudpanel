<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Container;
use App\Models\Project;
use App\Support\Tenant;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** A site's database, Redis and worker containers are grouped under the app's project, not linked to the app. */
class ProjectContainersTest extends TestCase
{
    public function test_compose_siblings_join_the_project_without_becoming_app_containers(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$p, $web, $db, $stray] = app(Tenant::class)->as($org->id, function () use ($server) {
            $p = Project::create(['name' => 'GPET']);
            $app = Application::create(['name' => 'gpet', 'source_type' => 'image', 'image' => 'nginx', 'port' => 80, 'server_id' => $server->id, 'project_id' => $p->id, 'status' => 'running']);
            $web = Container::create(['application_id' => $app->id, 'server_id' => $server->id, 'name' => 'gpet-nginx', 'image' => 'nginx', 'state' => 'running', 'labels' => ['com.docker.compose.project' => 'gpet']]);
            $db = Container::create(['server_id' => $server->id, 'name' => 'gpet-db', 'image' => 'mysql:8.4', 'state' => 'running', 'labels' => ['com.docker.compose.project' => 'gpet']]);
            $stray = Container::create(['server_id' => $server->id, 'name' => 'other-db', 'image' => 'mysql:8.4', 'state' => 'running', 'labels' => ['com.docker.compose.project' => 'other']]);

            return [$p, $web, $db, $stray];
        });

        $this->assertSame(0, Artisan::call('projects:link-containers'));
        $this->assertSame($p->id, Container::allTenants()->find($db->id)->project_id);
        $this->assertNull(Container::allTenants()->find($db->id)->application_id, 'never linked to the app, so a redeploy cannot remove it');
        $this->assertSame($p->id, Container::allTenants()->find($web->id)->project_id);
        $this->assertNull(Container::allTenants()->find($stray->id)->project_id);

        $d = $this->api($token)->getJson("/api/v1/projects/{$p->id}/dashboard")->assertOk()->json('data');
        $this->assertSame(2, $d['counts']['containers']);
        $this->assertSame(1, $d['services']);
        $this->assertSame('service', collect($d['containers'])->firstWhere('name', 'gpet-db')['role']);
    }
}
