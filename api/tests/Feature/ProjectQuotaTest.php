<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Project;
use App\Support\Tenant;
use Tests\TestCase;

/** PWS-D: project and container quotas. */
class ProjectQuotaTest extends TestCase
{
    public function test_staff_set_quotas_owners_see_usage_and_limits_are_enforced(): void
    {
        [, $staffOrg, $staff] = $this->org('super_admin');
        [, $org, $owner] = $this->org('owner', 'Acme');
        $server = $this->server($org);
        $project = app(Tenant::class)->as($org->id, function () use ($server) {
            $p = Project::create(['name' => 'Shop']);
            Application::create(['name' => 'web', 'source_type' => 'image', 'image' => 'nginx', 'port' => 80, 'server_id' => $server->id, 'project_id' => $p->id, 'kind' => 'customer', 'memory_limit_mb' => 512, 'cpu_limit' => 1]);

            return $p;
        });

        $this->api($owner)->putJson("/api/v1/app/projects/{$project->id}/quotas", ['apps' => 10])->assertForbidden();
        $this->api($staff)->putJson("/api/v1/projects/{$project->id}/quotas", ['apps' => 1, 'ram_mb' => 1024, 'container_ram_mb' => 768])->assertOk();
        $r = $this->api($owner)->getJson("/api/v1/app/projects/{$project->id}/quotas")->assertOk()->assertJsonPath('data.can_edit', false);
        $apps = collect($r->json('data.quotas'))->firstWhere('key', 'apps');
        $this->assertEquals([1, 1, 100], [$apps['used'], $apps['limit'], $apps['pct']]);

        // A second app breaks the apps quota.
        try {
            app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'api', 'source_type' => 'image', 'image' => 'nginx', 'server_id' => $server->id, 'project_id' => $project->id]));
            $this->fail('expected quota refusal');
        } catch (\App\Exceptions\ApiException $e) {
            $this->assertStringContainsString('applications', $e->getMessage());
        }

        // Per-container memory and the project RAM total are checked on deploy.
        $quota = app(\App\Services\ProjectQuotaService::class);
        $web = Application::allTenants()->where('name', 'web')->first();
        $quota->assert($project->fresh(), $web); // 512 MB fits
        $web->forceFill(['memory_limit_mb' => 900]);
        try {
            $quota->assert($project->fresh(), $web);
            $this->fail('expected container limit');
        } catch (\App\Exceptions\ApiException $e) {
            $this->assertSame(402, $e->status);
            $this->assertStringContainsString('memory per container', $e->getMessage());
        }
        $web->forceFill(['memory_limit_mb' => 512]);
        try {
            $quota->assert($project->fresh(), $web, [], 3); // 3 × 512 > 1024
            $this->fail('expected ram limit');
        } catch (\App\Exceptions\ApiException $e) {
            $this->assertStringContainsString('memory (MB)', $e->getMessage());
        }
    }
}
