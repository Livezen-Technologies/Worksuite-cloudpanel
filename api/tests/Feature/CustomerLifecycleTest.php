<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Domain;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** CUS-4/5: staff send a member a password reset, and close a customer account. */
class CustomerLifecycleTest extends TestCase
{
    public function test_staff_send_a_password_reset_to_a_member_only(): void
    {
        [, , $staff] = $this->org('super_admin');
        [$owner, $customer] = $this->org('owner', 'Acme Lanka');
        [$stranger] = $this->org('owner');

        $this->api($staff)->postJson("/api/v1/customers/{$customer->id}/members/{$owner->id}/password-reset")->assertOk()->assertJsonPath('data.email', $owner->email);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => strtolower($owner->email)]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.password_reset_sent', 'organization_id' => $customer->id]);
        $this->api($staff)->postJson("/api/v1/customers/{$customer->id}/members/{$stranger->id}/password-reset")->assertNotFound();
    }

    public function test_closing_needs_the_name_and_retires_everything(): void
    {
        [, , $staff] = $this->org('super_admin');
        [$owner, $customer, $customerToken] = $this->org('owner', 'Acme Lanka');
        $app = Application::allTenants()->create(['organization_id' => $customer->id, 'name' => 'shop', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx:1', 'status' => 'running']);
        Domain::allTenants()->create(['organization_id' => $customer->id, 'name' => 'shop.example.test', 'application_id' => $app->id]);

        $this->api($staff)->postJson("/api/v1/customers/{$customer->id}/close", ['reason' => 'Asked to leave', 'confirm' => 'Acme'])
            ->assertStatus(422)->assertJsonPath('error.fields.confirm.0', 'Type the customer name exactly: Acme Lanka');

        $this->api($staff)->postJson("/api/v1/customers/{$customer->id}/close", ['reason' => 'Asked to leave', 'confirm' => 'Acme Lanka'])
            ->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.closed.reason', 'Asked to leave');

        $this->assertSoftDeleted('applications', ['id' => $app->id]);
        $this->assertSame(0, Domain::allTenants()->where('organization_id', $customer->id)->count());
        $this->assertSame('suspended', DB::table('memberships')->where('organization_id', $customer->id)->value('status'));
        $this->assertSame(0, \App\Models\AccessToken::where('organization_id', $customer->id)->count(), 'members are signed out');
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.close', 'organization_id' => $customer->id]);

        $this->api($staff)->postJson("/api/v1/customers/{$customer->id}/reactivate")->assertStatus(409);
        $this->api($staff)->postJson("/api/v1/customers/{$customer->id}/close", ['reason' => 'again', 'confirm' => 'Acme Lanka'])->assertStatus(409);
    }

    public function test_customers_cannot_close_others(): void
    {
        [, $other] = $this->org('owner', 'Other Co');
        [, , $token] = $this->org('owner');
        $this->api($token)->postJson("/api/v1/customers/{$other->id}/close", ['reason' => 'nope', 'confirm' => 'Other Co'])->assertForbidden();
    }
}
