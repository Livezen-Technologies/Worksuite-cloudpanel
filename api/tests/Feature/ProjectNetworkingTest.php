<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Network;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Support\Tenant;
use Tests\TestCase;

/** PWS-F: project networks and how its domains are routed. */
class ProjectNetworkingTest extends TestCase
{
    public function test_networking_reports_routing_and_reconcile_creates_the_environment_network(): void
    {
        [, $org, $owner] = $this->org('owner', 'Acme');
        $server = $this->server($org, ['ip' => '198.51.100.9']);
        [$project, $app] = app(Tenant::class)->as($org->id, function () use ($server) {
            $p = Project::create(['name' => 'Shop']);
            $e = Environment::create(['project_id' => $p->id, 'name' => 'production']);
            $a = Application::create(['name' => 'shop', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'server_id' => $server->id, 'port' => 80, 'project_id' => $p->id, 'environment_id' => $e->id]);
            Domain::create(['name' => 'shop.example.com', 'application_id' => $a->id, 'dns_status' => 'verified']);

            return [$p, $a];
        });

        $d = $this->api($owner)->getJson("/api/v1/app/projects/{$project->id}/networking")->assertOk()->json('data');
        $this->assertSame('node', $d['domains'][0]['routing']);
        $this->assertSame('198.51.100.9', $d['domains'][0]['pinned_ip']);
        $this->assertStringContainsString('not been created', $d['apps'][0]['note']);

        [$viewer, $viewerToken] = $this->member($org, 'developer');
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $viewer->id, 'role' => 'viewer']);
        $this->api($viewerToken)->postJson("/api/v1/app/projects/{$project->id}/networking/reconcile")->assertForbidden();

        $this->api($owner)->postJson("/api/v1/app/projects/{$project->id}/networking/reconcile")->assertOk()->assertJsonPath('data.created', 1);
        $this->assertSame(1, Network::allTenants()->where('project_id', $project->id)->count());
        $this->api($owner)->postJson("/api/v1/app/projects/{$project->id}/networking/reconcile")->assertOk()->assertJsonPath('data.created', 0);
        $this->assertSame($app->id, $this->api($owner)->getJson("/api/v1/app/projects/{$project->id}/networking")->json('data.apps.0.id'));
    }
}
