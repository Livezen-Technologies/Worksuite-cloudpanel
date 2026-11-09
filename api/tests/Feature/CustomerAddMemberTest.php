<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** CUS-7: staff invite people into a customer, with customer-level roles only. */
class CustomerAddMemberTest extends TestCase
{
    public function test_staff_invite_a_member_into_the_customer(): void
    {
        [, $staffOrg, $staff] = $this->org('super_admin');
        [, $customer] = $this->org('owner', 'Acme Lanka');

        $res = $this->api($staff)->postJson("/api/v1/customers/{$customer->id}/members", ['email' => 'Dev@Acme.test', 'role' => 'developer'])
            ->assertCreated()->json('data');
        $this->assertStringContainsString('/invite/', $res['invite_url']);
        $userId = DB::table('users')->where('email', 'dev@acme.test')->value('id');
        $this->assertSame('developer', DB::table('memberships')->where('organization_id', $customer->id)->where('user_id', $userId)->value('role'));
        $this->assertFalse(DB::table('memberships')->where('organization_id', $staffOrg->id)->where('user_id', $userId)->exists(), 'lands in the customer, not the staff organisation');
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.member_invited', 'organization_id' => $customer->id]);

        // Platform roles are never handed to a customer; the same person twice is refused.
        $this->api($staff)->postJson("/api/v1/customers/{$customer->id}/members", ['email' => 'x@acme.test', 'role' => 'super_admin'])->assertStatus(422);
        $this->api($staff)->postJson("/api/v1/customers/{$customer->id}/members", ['email' => 'y@acme.test', 'role' => 'infra_admin'])->assertStatus(422);
        $this->api($staff)->postJson("/api/v1/customers/{$customer->id}/members", ['email' => 'dev@acme.test', 'role' => 'billing'])->assertStatus(409);
    }

    public function test_customers_cannot_add_members_to_others(): void
    {
        [, $other] = $this->org('owner');
        [, , $token] = $this->org('owner');
        $this->api($token)->postJson("/api/v1/customers/{$other->id}/members", ['email' => 'z@x.test', 'role' => 'developer'])->assertForbidden();
    }

    public function test_staff_change_roles_and_remove_members_but_never_the_last_owner(): void
    {
        [, , $staff] = $this->org('super_admin');
        [$owner, $customer, $ownerToken] = $this->org('owner', 'Acme Lanka');
        $dev = $this->api($staff)->postJson("/api/v1/customers/{$customer->id}/members", ['email' => 'dev@acme.test', 'role' => 'developer'])->assertCreated();
        $devId = DB::table('users')->where('email', 'dev@acme.test')->value('id');
        DB::table('memberships')->where('user_id', $devId)->update(['status' => 'active']);

        $this->api($staff)->deleteJson("/api/v1/customers/{$customer->id}/members/{$owner->id}")->assertStatus(409);
        $this->api($staff)->patchJson("/api/v1/customers/{$customer->id}/members/{$owner->id}", ['role' => 'developer'])->assertStatus(409);
        $this->api($staff)->patchJson("/api/v1/customers/{$customer->id}/members/{$devId}", ['role' => 'super_admin'])->assertStatus(422);

        $this->api($staff)->patchJson("/api/v1/customers/{$customer->id}/members/{$devId}", ['role' => 'owner'])->assertOk()->assertJsonPath('data.role', 'owner');
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.member_role_changed', 'resource_id' => $devId]);
        // With a second owner, the first can step down and then be removed.
        $this->api($staff)->patchJson("/api/v1/customers/{$customer->id}/members/{$owner->id}", ['role' => 'billing'])->assertOk();
        $this->api($staff)->deleteJson("/api/v1/customers/{$customer->id}/members/{$owner->id}")->assertNoContent();
        $this->assertFalse(DB::table('memberships')->where('organization_id', $customer->id)->where('user_id', $owner->id)->exists());
        $this->assertSame(0, DB::table('access_tokens')->where('organization_id', $customer->id)->where('tokenable_id', $owner->id)->count(), 'their sessions end');
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.member_removed', 'resource_id' => $owner->id]);
        $this->api($staff)->deleteJson("/api/v1/customers/{$customer->id}/members/{$owner->id}")->assertNotFound();
        $this->api($ownerToken)->deleteJson("/api/v1/customers/{$customer->id}/members/{$devId}")->assertStatus(401);
    }
}
