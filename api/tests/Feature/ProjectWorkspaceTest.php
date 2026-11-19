<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BackupJob;
use App\Models\Project;
use Tests\TestCase;

/** PWS: the project workspace, its role model and the deletion guard. */
class ProjectWorkspaceTest extends TestCase
{
    public function test_roles_gate_the_workspace_and_member_changes_are_audited(): void
    {
        [, $org, $owner] = $this->org('owner', 'Acme');
        $project = $this->api($owner)->postJson('/api/v1/app/projects', ['name' => 'Shop'])->assertCreated()->json('data');
        [$dev, $devToken] = $this->member($org, 'developer');
        [, $viewerToken] = $this->member($org, 'member');

        $this->api($devToken)->getJson("/api/v1/app/projects/{$project['id']}/workspace")->assertForbidden();
        $this->api($owner)->getJson("/api/v1/app/projects/{$project['id']}/workspace")->assertOk()->assertJsonPath('data.role', 'owner');

        $this->api($owner)->putJson("/api/v1/app/projects/{$project['id']}/members/{$dev->id}", ['role' => 'developer'])->assertOk()->assertJsonPath('data.0.role', 'developer');
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'project.member.set')->count());
        $this->api($devToken)->getJson("/api/v1/app/projects/{$project['id']}/workspace")->assertOk()
            ->assertJsonPath('data.role', 'developer')->assertJsonPath('data.abilities', ['view', 'deploy']);
        $this->api($devToken)->putJson("/api/v1/app/projects/{$project['id']}/members/{$dev->id}", ['role' => 'owner'])->assertForbidden();
        $this->api($viewerToken)->getJson("/api/v1/app/projects/{$project['id']}/workspace")->assertForbidden();

        [$outsider] = $this->org('owner', 'Other');
        $this->api($owner)->putJson("/api/v1/app/projects/{$project['id']}/members/{$outsider->id}", ['role' => 'viewer'])->assertStatus(422);

        $this->api($owner)->deleteJson("/api/v1/app/projects/{$project['id']}/members/{$dev->id}")->assertOk()->assertJsonCount(0, 'data');
        $this->api($devToken)->getJson("/api/v1/app/projects/{$project['id']}/workspace")->assertForbidden();
    }

    public function test_staff_see_the_workspace_and_cannot_delete_a_project_with_backups(): void
    {
        [, $staffOrg, $staff] = $this->org('super_admin');
        $project = $this->api($staff)->postJson('/api/v1/projects', ['name' => 'Ops'])->assertCreated()->json('data');
        $this->api($staff)->getJson("/api/v1/projects/{$project['id']}/workspace")->assertOk()
            ->assertJsonPath('data.role', 'platform_admin')->assertJsonPath('data.delete_blockers', []);

        BackupJob::allTenants()->forceCreate(['organization_id' => $staffOrg->id, 'target_type' => 'project', 'project_id' => $project['id'], 'status' => 'succeeded', 'trigger' => 'manual']);
        $this->api($staff)->getJson("/api/v1/projects/{$project['id']}/workspace")->assertJsonPath('data.delete_blockers', ['1 backup']);
        $this->api($staff)->deleteJson("/api/v1/projects/{$project['id']}")->assertStatus(409);
        $this->assertNotNull(Project::allTenants()->find($project['id']));
    }
}
