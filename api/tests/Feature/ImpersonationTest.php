<?php

namespace Tests\Feature;

use Tests\TestCase;

/** CUS-3: staff sign in as a customer with a reason; audited, time-limited, and sign-in settings stay locked. */
class ImpersonationTest extends TestCase
{
    public function test_staff_sign_in_as_a_customer_owner_with_guardrails(): void
    {
        [$staff, , $staffToken] = $this->org('super_admin');
        [$owner, $customer] = $this->org('owner', 'Acme Lanka');

        $this->api($staffToken)->postJson("/api/v1/customers/{$customer->id}/impersonate", [])->assertStatus(422);
        $res = $this->api($staffToken)->postJson("/api/v1/customers/{$customer->id}/impersonate", ['reason' => 'Ticket 4411: deploy fails'])
            ->assertOk()->assertJsonPath('data.user.email', $owner->email)->json('data');

        $as = $res['token'];
        $me = $this->api($as)->getJson('/api/v1/me')->assertOk()->json('data');
        $this->assertSame($staff->email, $me['impersonated_by']['email']);
        $this->assertSame($customer->id, $me['organization']['id']);
        $this->assertNotNull($me['impersonated_by']['expires_at']);

        // Sign-in settings are off limits; reading is fine.
        $this->api($as)->postJson('/api/v1/me/password', ['current_password' => 'password', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])->assertForbidden();
        $this->api($as)->postJson('/api/v1/me/mfa/enable')->assertForbidden();
        $this->api($as)->getJson('/api/v1/me/sessions')->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.impersonate', 'organization_id' => $customer->id, 'actor' => $staff->email]);
        $this->api($as)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.impersonate_end', 'organization_id' => $customer->id]);
        $this->assertSame(0, $owner->tokens()->where('name', 'like', 'impersonation:%')->count());
    }

    public function test_customers_cannot_impersonate(): void
    {
        [, $other] = $this->org('owner');
        [, , $token] = $this->org('owner');
        $this->api($token)->postJson("/api/v1/customers/{$other->id}/impersonate", ['reason' => 'curious about it'])->assertForbidden();
    }
}
