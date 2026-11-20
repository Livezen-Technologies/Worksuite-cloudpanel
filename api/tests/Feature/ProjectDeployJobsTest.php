<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Release;
use App\Support\Tenant;
use Tests\TestCase;

/** PWS-G: deploy jobs in the workspace: progress, failure, retry and rollback by project role. */
class ProjectDeployJobsTest extends TestCase
{
    public function test_developers_retry_the_latest_failure_and_roll_back_viewers_only_watch(): void
    {
        [, $org, $owner] = $this->org('owner', 'Acme');
        $server = $this->server($org);
        [$project, $app, $release, $failed] = app(Tenant::class)->as($org->id, function () use ($server) {
            $p = Project::create(['name' => 'Shop']);
            $a = Application::create(['name' => 'shop', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx:1', 'server_id' => $server->id, 'port' => 80, 'project_id' => $p->id, 'status' => 'running']);
            $r = Release::create(['application_id' => $a->id, 'version' => 1, 'image' => 'nginx:1', 'status' => 'active', 'config' => ['image' => 'nginx:1', 'port' => 80, 'env_encrypted' => null, 'auto_restart' => true, 'cpus' => null, 'memory_mb' => null, 'volume_path' => null, 'registry_auth' => null]]);
            $a->forceFill(['current_release_id' => $r->id])->save();
            $f = Deployment::create(['application_id' => $a->id, 'status' => 'failed', 'source' => 'manual', 'image' => 'nginx:2']);

            return [$p, $a, $r, $f];
        });
        [$dev, $devToken] = $this->member($org, 'developer');
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $dev->id, 'role' => 'developer']);
        [$viewer, $viewerToken] = $this->member($org, 'developer');
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $viewer->id, 'role' => 'viewer']);

        $list = $this->api($viewerToken)->getJson("/api/v1/app/projects/{$project->id}/deploy-jobs")->assertOk();
        $list->assertJsonPath('data.can_deploy', false)->assertJsonPath('data.deployments.0.can_retry', true)->assertJsonPath('data.releases.0.current', true);
        $this->api($viewerToken)->postJson("/api/v1/app/projects/{$project->id}/deploy-jobs/{$failed->id}/retry")->assertForbidden();

        $this->api($devToken)->postJson("/api/v1/app/projects/{$project->id}/deploy-jobs/{$failed->id}/retry")->assertStatus(202);
        $this->api($devToken)->postJson("/api/v1/app/projects/{$project->id}/deploy-jobs/{$failed->id}/retry")->assertStatus(409);
        $this->api($devToken)->postJson("/api/v1/app/projects/{$project->id}/deploy-jobs/rollback", ['application_id' => $app->id, 'release_id' => $release->id])->assertStatus(202);
        $this->assertSame(2, AuditLog::query()->withoutGlobalScopes()->whereIn('action', ['project.deploy.retry', 'project.deploy.rollback'])->count());
        $this->assertSame(3, Deployment::allTenants()->where('application_id', $app->id)->count());
    }
}
