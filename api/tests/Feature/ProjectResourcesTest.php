<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Database;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Secret;
use App\Models\Volume;
use App\Support\Tenant;
use Tests\TestCase;

/** PWS-C: project databases and volumes, masked credentials and isolation between customers. */
class ProjectResourcesTest extends TestCase
{
    public function test_databases_are_provisioned_masked_revealed_to_owners_and_isolated(): void
    {
        [, $org, $owner] = $this->org('owner', 'Acme');
        $server = $this->server($org);
        [$project, $loose] = app(Tenant::class)->as($org->id, function () use ($server) {
            $p = Project::create(['name' => 'Shop']);
            Application::create(['name' => 'shop', 'source_type' => 'image', 'image' => 'nginx', 'port' => 80, 'server_id' => $server->id, 'project_id' => $p->id, 'status' => 'running']);
            $s = Secret::create(['name' => 'old-password', 'value' => 'hunter2']);
            $loose = Database::create(['server_id' => $server->id, 'name' => 'old', 'engine' => 'postgres', 'version' => '16', 'secret_id' => $s->id, 'status' => 'running']);

            return [$p, $loose];
        });

        $res = $this->api($owner)->postJson("/api/v1/app/projects/{$project->id}/databases", ['name' => 'shopdb', 'engine' => 'postgres', 'server_id' => $server->id])->assertStatus(202);
        $this->assertSame($project->id, Database::allTenants()->find($res->json('data.database.id'))->project_id);

        $this->api($owner)->postJson("/api/v1/app/projects/{$project->id}/databases/{$loose->id}/attach")->assertOk()->assertJsonCount(2, 'data.databases');
        $list = $this->api($owner)->getJson("/api/v1/app/projects/{$project->id}/databases")->assertOk();
        $this->assertStringNotContainsString('hunter2', $list->getContent());
        $this->api($owner)->getJson("/api/v1/app/projects/{$project->id}/databases/{$loose->id}/credentials")->assertOk()->assertJsonPath('data.password', 'hunter2');
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'project.database.credentials')->count());

        [$viewer, $viewerToken] = $this->member($org, 'developer');
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $viewer->id, 'role' => 'viewer']);
        $this->api($viewerToken)->getJson("/api/v1/app/projects/{$project->id}/databases")->assertOk()->assertJsonPath('data.attachable', []);
        $this->api($viewerToken)->getJson("/api/v1/app/projects/{$project->id}/databases/{$loose->id}/credentials")->assertForbidden();

        [, , $other] = $this->org('owner', 'Other');
        $this->api($other)->getJson("/api/v1/app/projects/{$project->id}/databases")->assertNotFound();

        // A node the project does not run on is refused.
        $elsewhere = $this->server($org);
        $this->api($owner)->postJson("/api/v1/app/projects/{$project->id}/databases", ['name' => 'x', 'engine' => 'redis', 'server_id' => $elsewhere->id])->assertStatus(422);
    }

    public function test_volumes_are_created_attached_and_detached(): void
    {
        [, $org, $owner] = $this->org('owner', 'Acme');
        $server = $this->server($org);
        [$project, $loose] = app(Tenant::class)->as($org->id, function () use ($server) {
            $p = Project::create(['name' => 'Shop']);
            Application::create(['name' => 'shop', 'source_type' => 'image', 'image' => 'nginx', 'port' => 80, 'server_id' => $server->id, 'project_id' => $p->id, 'status' => 'running']);

            return [$p, Volume::create(['server_id' => $server->id, 'name' => 'uploads'])];
        });
        $this->api($owner)->postJson("/api/v1/app/projects/{$project->id}/volumes", ['name' => 'shop-data', 'server_id' => $server->id, 'quota_gb' => 5])->assertStatus(202);
        $this->api($owner)->postJson("/api/v1/app/projects/{$project->id}/volumes/{$loose->id}/attach")->assertOk()->assertJsonCount(2, 'data.volumes');
        $this->api($owner)->deleteJson("/api/v1/app/projects/{$project->id}/volumes/{$loose->id}")->assertOk()->assertJsonCount(1, 'data.volumes');
        $this->assertNull(Volume::allTenants()->find($loose->id)->project_id);
    }
}
