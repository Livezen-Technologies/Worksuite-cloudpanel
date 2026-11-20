<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Container;
use App\Models\Project;
use App\Support\Tenant;
use Tests\TestCase;

/** Project → Overview: counts, health and the rows behind the tabs. */
class ProjectDashboardTest extends TestCase
{
    public function test_dashboard_counts_health_and_resources(): void
    {
        [, $org, $token] = $this->org();
        $a = $this->server($org, ['name' => 'node-a']);
        $b = $this->server($org, ['name' => 'node-b']);
        $project = app(Tenant::class)->as($org->id, function () use ($a, $b) {
            $p = Project::create(['name' => 'WorkSuite Cloud']);
            $web = Application::create(['name' => 'web', 'source_type' => 'image', 'image' => 'nginx', 'port' => 80, 'server_id' => $a->id, 'project_id' => $p->id, 'status' => 'running', 'cpu_limit' => 2, 'memory_limit_mb' => 4096, 'replicas' => 2]);
            $api = Application::create(['name' => 'api', 'source_type' => 'image', 'image' => 'acme/api', 'port' => 8080, 'server_id' => $b->id, 'project_id' => $p->id, 'status' => 'running', 'cpu_limit' => 1, 'memory_limit_mb' => 1024]);
            Container::create(['application_id' => $web->id, 'server_id' => $a->id, 'name' => 'web-1', 'image' => 'nginx', 'state' => 'running', 'cpu_pct' => 5, 'mem_mb' => 200]);
            Container::create(['application_id' => $web->id, 'server_id' => $a->id, 'name' => 'web-2', 'image' => 'nginx', 'state' => 'running', 'cpu_pct' => 3, 'mem_mb' => 180]);
            Container::create(['application_id' => $api->id, 'server_id' => $b->id, 'name' => 'api-1', 'image' => 'acme/api', 'state' => 'exited']);

            return $p;
        });

        $d = $this->api($token)->getJson("/api/v1/projects/{$project->id}/dashboard")->assertOk()->json('data');
        $this->assertSame(['applications' => 2, 'containers' => 3, 'running' => 2, 'nodes' => 2, 'backups' => 0, 'databases' => 0], $d['counts']);
        $this->assertSame('degraded', $d['health'], 'one app has a stopped container');
        $this->assertEquals(5.0, $d['resources']['cpu_reserved']);          // 2 x 2 + 1
        $this->assertSame(9216, $d['resources']['memory_reserved_mb']);    // 4096 x 2 + 1024
        $this->assertSame(380, $d['resources']['mem_mb']);
        $this->assertCount(3, $d['containers']);
        $this->assertSame('node-a', collect($d['applications'])->firstWhere('name', 'web')['server']['name']);
    }

    public function test_an_empty_project_says_so(): void
    {
        [, $org, $token] = $this->org();
        $p = app(Tenant::class)->as($org->id, fn () => Project::create(['name' => 'Empty']));
        $this->api($token)->getJson("/api/v1/projects/{$p->id}/dashboard")->assertOk()->assertJsonPath('data.health', 'empty')->assertJsonPath('data.counts.applications', 0);
    }
}
