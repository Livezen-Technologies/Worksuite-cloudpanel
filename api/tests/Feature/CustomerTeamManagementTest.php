<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Customer portal → Account → Team: owners change roles and remove members inside their own organisation. */
class CustomerTeamManagementTest extends TestCase
{
    public function test_owner_changes_roles_and_removes_members_but_never_the_last_owner(): void
    {
        [$owner, $org, $token] = $this->org('owner');
        [$dev, $devToken] = $this->member($org, 'developer');
        Membership::allTenants()->where('user_id', $dev->id)->update(['status' => 'active']);
        $p = app(Tenant::class)->as($org->id, fn () => Project::create(['name' => 'Shop']));
        ProjectMember::create(['project_id' => $p->id, 'user_id' => $dev->id, 'role' => 'developer']);

        $this->api($token)->patchJson("/api/v1/app/account/team/{$dev->id}", ['role' => 'super_admin'])->assertStatus(422);
        $this->api($token)->patchJson("/api/v1/app/account/team/{$owner->id}", ['role' => 'developer'])->assertStatus(409);
        $this->api($token)->deleteJson("/api/v1/app/account/team/{$owner->id}")->assertStatus(409);

        $this->api($token)->patchJson("/api/v1/app/account/team/{$dev->id}", ['role' => 'billing'])->assertOk()->assertJsonPath('data.role', 'billing');
        $this->assertDatabaseHas('audit_logs', ['action' => 'team.member_role_changed', 'resource_id' => $dev->id, 'organization_id' => $org->id]);

        $this->api($token)->deleteJson("/api/v1/app/account/team/{$dev->id}")->assertNoContent();
        $this->assertFalse(DB::table('memberships')->where('organization_id', $org->id)->where('user_id', $dev->id)->exists());
        $this->assertFalse(DB::table('project_members')->where('user_id', $dev->id)->exists(), 'project roles go too');
        $this->assertSame(0, DB::table('access_tokens')->where('organization_id', $org->id)->where('tokenable_id', $dev->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'team.member_removed', 'resource_id' => $dev->id]);
        $this->api($token)->deleteJson("/api/v1/app/account/team/{$dev->id}")->assertNotFound();
    }

    public function test_only_team_managers_and_only_their_own_organisation(): void
    {
        [, $org, $token] = $this->org('owner');
        [$dev, $devToken] = $this->member($org, 'developer');
        [$stranger] = $this->org('owner');

        $this->api($devToken)->patchJson("/api/v1/app/account/team/{$dev->id}", ['role' => 'owner'])->assertForbidden();
        $this->api($token)->deleteJson("/api/v1/app/account/team/{$stranger->id}")->assertNotFound();
        $this->api($token)->patchJson("/api/v1/app/account/team/{$stranger->id}", ['role' => 'billing'])->assertNotFound();
    }
}
