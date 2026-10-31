<?php

namespace Tests\Feature;

use App\Models\Membership;
use Tests\TestCase;

/** USR-002: staff define custom roles; no one grants more than they hold; built-ins stay fixed. */
class CustomRolesTest extends TestCase
{
    public function test_staff_create_edit_assign_and_delete_a_custom_role(): void
    {
        [$admin, $org, $token] = $this->org('super_admin');
        $body = ['label' => 'Release Manager', 'scope' => 'both', 'description' => 'Deploys, nothing else', 'permissions' => [
            ['name' => 'deploy', 'constraint' => 'own_org', 'requires_approval' => true], ['name' => 'logs.view', 'constraint' => 'read'],
        ]];
        $role = $this->api($token)->postJson('/api/v1/roles', $body)->assertCreated()->json('data');
        $this->assertSame('custom_release_manager', $role['name']);
        $this->assertTrue($role['custom']);
        $this->assertTrue(collect($role['permissions'])->firstWhere('name', 'deploy')['requires_approval']);
        $this->api($token)->postJson('/api/v1/roles', $body)->assertCreated()->assertJsonPath('data.name', 'custom_release_manager_x');

        // Assign it, then it grants exactly what it lists.
        [$dev] = $this->member($org, 'developer');
        $mid = $dev->id;
        $this->api($token)->patchJson("/api/v1/users/{$mid}", ['role' => 'custom_release_manager'])->assertOk();
        $this->assertTrue($dev->fresh()->hasPermission('deploy'));
        $this->assertFalse($dev->fresh()->hasPermission('servers.manage'));

        $this->api($token)->patchJson('/api/v1/roles/custom_release_manager', ['label' => 'Releaser', 'scope' => 'both', 'permissions' => [['name' => 'logs.view', 'constraint' => 'all']]])
            ->assertOk()->assertJsonPath('data.label', 'Releaser');
        $this->assertFalse($dev->fresh()->hasPermission('deploy'));

        $this->api($token)->deleteJson('/api/v1/roles/custom_release_manager')->assertStatus(409);
        $this->api($token)->patchJson('/api/v1/roles/owner', $body)->assertStatus(409);
        $this->api($token)->deleteJson('/api/v1/roles/owner')->assertStatus(409);
        $this->api($token)->deleteJson('/api/v1/roles/custom_release_manager_x')->assertOk();
        $list = collect($this->api($token)->getJson('/api/v1/roles')->json('data'));
        $this->assertSame(1, $list->firstWhere('name', 'custom_release_manager')['members']);
        $this->assertNull($list->firstWhere('name', 'custom_release_manager_x'));
    }

    public function test_customers_cannot_define_roles_and_no_one_grants_more_than_they_hold(): void
    {
        [, , $owner] = $this->org();
        $this->api($owner)->postJson('/api/v1/roles', ['label' => 'Sneaky', 'scope' => 'both', 'permissions' => []])->assertForbidden();

        [, , $infra] = $this->org('infra_admin');
        $r = $this->api($infra)->postJson('/api/v1/roles', ['label' => 'Too much', 'scope' => 'platform', 'permissions' => [['name' => 'billing.manage', 'constraint' => 'all']]]);
        if ($r->status() !== 403) {
            $r->assertStatus(422)->assertJsonValidationErrors('permissions');
        }
    }
}
