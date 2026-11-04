<?php

namespace Tests\Feature;

use App\Models\Plan;
use Tests\TestCase;

/** CUS-1/2: the admin customer profile, notes, and suspend / reactivate. */
class CustomerProfileTest extends TestCase
{
    public function test_profile_notes_and_suspension(): void
    {
        [, , $staff] = $this->org('super_admin');
        [$owner, $org, $customer] = $this->org('owner', 'Acme Ltd');
        $plan = Plan::first();
        $this->api($staff)->postJson("/api/v1/customers/{$org->id}/plan", ['plan_id' => $plan->id])->assertOk();

        $p = $this->api($staff)->getJson("/api/v1/customers/{$org->id}")->assertOk()->json('data');
        $this->assertSame(['Acme Ltd', 'active', $plan->id], [$p['name'], $p['status'], $p['plan']['id']]);
        $this->assertSame($owner->email, $p['members'][0]['email']);
        $this->assertSame(1, $p['counts']['members']);

        $this->api($staff)->patchJson("/api/v1/customers/{$org->id}", ['notes' => 'VIP; pays by bank transfer', 'billing_email' => 'pay@acme.test'])->assertOk();
        $p = $this->api($staff)->getJson("/api/v1/customers/{$org->id}")->json('data');
        $this->assertSame(['VIP; pays by bank transfer', 'pay@acme.test'], [$p['notes'], $p['billing_email']]);

        $this->api($staff)->postJson("/api/v1/customers/{$org->id}/suspend", [])->assertStatus(422);
        $this->api($staff)->postJson("/api/v1/customers/{$org->id}/suspend", ['reason' => 'Unpaid invoice'])->assertOk()
            ->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.suspension.reason', 'Unpaid invoice');
        $this->api($staff)->postJson("/api/v1/customers/{$org->id}/reactivate")->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.suspension', null);
        $this->assertTrue(collect($this->api($staff)->getJson("/api/v1/customers/{$org->id}")->json('data.activity'))->pluck('action')->contains('customer.suspend'));

        $this->api($customer)->getJson("/api/v1/customers/{$org->id}")->assertForbidden();
    }
}
