<?php

namespace Tests\Feature;

use App\Models\CustomerGroup;
use App\Models\CustomerGroupMember;
use App\Models\Invoice;
use App\Models\Organization;
use App\Services\CustomerGroupService;
use App\Services\CustomerService;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** CUST-4..5 phase 3-4: dynamic group rules, the pricing hook, and account users. */
class CustomerGroupRulesTest extends TestCase
{
    use RefreshDatabase;

    private function dynamic(array $rules, int $discount = 0, array $overrides = []): CustomerGroup
    {
        return CustomerGroup::create([
            'name' => 'By rule', 'slug' => 'by-rule', 'kind' => 'dynamic',
            'rules' => $rules, 'discount_percent' => $discount, 'plan_overrides' => $overrides ?: null,
        ]);
    }

    public function test_a_rule_on_an_unknown_field_is_refused(): void
    {
        [, , $token] = $this->org('super_admin');
        $group = $this->dynamic(['all' => [['field' => 'customer_type', 'op' => 'eq', 'value' => 'business']]]);

        $this->api($token)->postJson("/api/v1/customer-groups/{$group->id}/preview-rules", [
            'rules' => ['all' => [['field' => 'settings->secret', 'op' => 'eq', 'value' => 'x']]],
        ])->assertStatus(422);

        $this->api($token)->postJson("/api/v1/customer-groups/{$group->id}/preview-rules", [
            'rules' => ['all' => [['field' => 'status', 'op' => 'regex', 'value' => '.*']]],
        ])->assertStatus(422);
    }

    public function test_a_preview_counts_matches_and_writes_nothing(): void
    {
        [, , $token] = $this->org('super_admin');
        [, $business] = $this->org('owner');
        $business->forceFill(['customer_type' => 'business'])->save();
        $group = $this->dynamic(['all' => [['field' => 'customer_type', 'op' => 'eq', 'value' => 'business']]]);

        $d = $this->api($token)->postJson("/api/v1/customer-groups/{$group->id}/preview-rules")->assertOk()->json('data');

        $this->assertSame(1, $d['total']);
        $this->assertSame($business->name, $d['sample'][0]['name']);
        $this->assertSame(0, CustomerGroupMember::allTenants()->count(), 'a preview is a read');
    }

    public function test_recompute_fills_the_group_and_later_empties_it_again(): void
    {
        [, , $token] = $this->org('super_admin');
        [, $org] = $this->org('owner');
        $org->forceFill(['customer_type' => 'enterprise'])->save();
        $group = $this->dynamic(['all' => [['field' => 'customer_type', 'op' => 'eq', 'value' => 'enterprise']]]);

        $r = $this->api($token)->postJson("/api/v1/customer-groups/{$group->id}/recompute")->assertOk()->json('data');
        $this->assertSame(['matched' => 1, 'added' => 1, 'removed' => 0, 'kept_manual' => 0], $r);
        $this->assertDatabaseHas('customer_group_members', [
            'organization_id' => $org->id, 'customer_group_id' => $group->id, 'source' => 'rule',
        ]);
        $this->assertNotNull($group->fresh()->rules_run_at);

        // The account stops matching, so the rule-driven row goes.
        $org->forceFill(['customer_type' => 'individual'])->save();
        $again = $this->api($token)->postJson("/api/v1/customer-groups/{$group->id}/recompute")->json('data');
        $this->assertSame(['matched' => 0, 'added' => 0, 'removed' => 1, 'kept_manual' => 0], $again);
        $this->assertSame(0, CustomerGroupMember::allTenants()->where('customer_group_id', $group->id)->count());
    }

    public function test_recompute_leaves_a_hand_added_member_alone(): void
    {
        [, , $token] = $this->org('super_admin');
        [, $org] = $this->org('owner');
        $group = $this->dynamic(['all' => [['field' => 'customer_type', 'op' => 'eq', 'value' => 'reseller']]]);
        CustomerGroupMember::create([
            'organization_id' => $org->id, 'customer_group_id' => $group->id, 'source' => 'manual',
        ]);

        $r = $this->api($token)->postJson("/api/v1/customer-groups/{$group->id}/recompute")->json('data');

        $this->assertSame(0, $r['matched']);
        $this->assertSame(1, $r['kept_manual']);
        $this->assertDatabaseHas('customer_group_members', [
            'organization_id' => $org->id, 'customer_group_id' => $group->id, 'source' => 'manual',
        ]);
    }

    public function test_a_hand_filled_group_cannot_be_recomputed(): void
    {
        [, , $token] = $this->org('super_admin');
        $group = CustomerGroup::create(['name' => 'By hand', 'slug' => 'by-hand', 'kind' => 'static']);

        $this->api($token)->postJson("/api/v1/customer-groups/{$group->id}/recompute")->assertStatus(409);
    }

    public function test_nested_any_matches_either_branch(): void
    {
        [, , $token] = $this->org('super_admin');
        [, $a] = $this->org('owner');
        [, $b] = $this->org('owner');
        $a->forceFill(['customer_type' => 'business'])->save();
        $b->forceFill(['customer_type' => 'reseller'])->save();
        $group = $this->dynamic(['any' => [
            ['field' => 'customer_type', 'op' => 'eq', 'value' => 'business'],
            ['field' => 'customer_type', 'op' => 'eq', 'value' => 'reseller'],
        ]]);

        $this->assertSame(2, $this->api($token)
            ->postJson("/api/v1/customer-groups/{$group->id}/preview-rules")->json('data.total'));
    }

    public function test_an_archived_account_is_never_swept_into_a_group(): void
    {
        [, , $token] = $this->org('super_admin');
        [, $org] = $this->org('owner');
        $org->forceFill(['customer_type' => 'business', 'archived_at' => now()])->save();
        $group = $this->dynamic(['all' => [['field' => 'customer_type', 'op' => 'eq', 'value' => 'business']]]);

        $this->assertSame(0, $this->api($token)
            ->postJson("/api/v1/customer-groups/{$group->id}/preview-rules")->json('data.total'));
    }

    public function test_a_developer_may_not_change_group_membership(): void
    {
        [, , $token] = $this->org('developer');
        $group = $this->dynamic(['all' => [['field' => 'status', 'op' => 'eq', 'value' => 'active']]]);

        $this->api($token)->postJson("/api/v1/customer-groups/{$group->id}/recompute")->assertStatus(403);
        $this->api($token)->postJson("/api/v1/customer-groups/{$group->id}/preview-rules")->assertStatus(403);
    }

    public function test_the_largest_discount_reaches_the_invoice_as_its_own_line(): void
    {
        Storage::fake('local');
        config(['billing.tax_rate' => 0.0]);
        [, $org, $token] = $this->org();
        $this->api($token)->getJson('/api/v1/app/billing')->assertOk();   // creates the subscription

        $small = CustomerGroup::create(['name' => 'Small', 'slug' => 'small-disc', 'discount_percent' => 5]);
        $big = CustomerGroup::create(['name' => 'Loyal', 'slug' => 'loyal', 'discount_percent' => 20]);
        foreach ([$small, $big] as $g) {
            CustomerGroupMember::create(['organization_id' => $org->id, 'customer_group_id' => $g->id]);
        }

        app(Tenant::class)->set(null);
        $this->artisan('billing:invoice', ['--month' => '2026-09'])->assertSuccessful();
        $invoice = Invoice::allTenants()->firstOrFail();

        $this->assertSame(['plan', 'discount'], array_column($invoice->line_items, 'kind'));
        $this->assertSame(-100, $invoice->line_items[1]['amount_cents'], '20% of the 5.00 plan');
        $this->assertStringContainsString('Loyal', $invoice->line_items[1]['description']);
        $this->assertSame(100, $invoice->discount_cents);
        $this->assertSame($big->id, $invoice->customer_group_id);
        $this->assertSame(400, $invoice->subtotal_cents, 'tax is charged on what is owed, not the gross');
        $this->assertSame(400, $invoice->amount_cents);
    }

    public function test_a_group_may_re_price_a_plan_and_the_cheaper_override_wins(): void
    {
        Storage::fake('local');
        config(['billing.tax_rate' => 0.0]);
        [, $org, $token] = $this->org();
        $this->api($token)->getJson('/api/v1/app/billing')->assertOk();
        $plan = \App\Models\Subscription::allTenants()->where('organization_id', $org->id)->firstOrFail()->plan;

        $dear = CustomerGroup::create(['name' => 'Dear', 'slug' => 'dear', 'plan_overrides' => [$plan->id => 450]]);
        $cheap = CustomerGroup::create(['name' => 'Cheap', 'slug' => 'cheaper', 'plan_overrides' => [$plan->id => 300]]);
        foreach ([$dear, $cheap] as $g) {
            CustomerGroupMember::create(['organization_id' => $org->id, 'customer_group_id' => $g->id]);
        }

        app(Tenant::class)->set(null);
        $this->artisan('billing:invoice', ['--month' => '2026-09'])->assertSuccessful();
        $invoice = Invoice::allTenants()->firstOrFail();

        $this->assertSame(300, $invoice->line_items[0]['amount_cents']);
        $this->assertStringContainsString('group price', $invoice->line_items[0]['description']);
        $this->assertSame(300, $invoice->amount_cents);
    }

    public function test_pricing_for_an_account_in_no_group_is_untouched(): void
    {
        [, $org] = $this->org('owner');

        $this->assertSame([
            'discount_percent' => 0, 'group_id' => null, 'group_name' => null, 'plan_overrides' => [],
        ], app(CustomerService::class)->pricingFor($org));
    }

    public function test_the_nightly_command_fills_every_dynamic_group(): void
    {
        [, $org] = $this->org('owner');
        $org->forceFill(['customer_type' => 'business'])->save();
        $this->dynamic(['all' => [['field' => 'customer_type', 'op' => 'eq', 'value' => 'business']]]);
        // A dynamic group with no rules yet must not stop the run.
        CustomerGroup::create(['name' => 'Unset', 'slug' => 'unset', 'kind' => 'dynamic']);

        $this->artisan('customers:recompute-groups')->assertSuccessful();

        $this->assertSame(1, CustomerGroupMember::allTenants()->where('source', 'rule')->count());
    }

    public function test_an_inactivity_rule_catches_an_account_that_was_never_active(): void
    {
        [, , $token] = $this->org('super_admin');
        [, $org] = $this->org('owner');
        $this->assertNull($org->last_activity_at);
        $group = $this->dynamic(['all' => [['field' => 'days_since_activity', 'op' => 'gte', 'value' => 90]]]);

        $total = $this->api($token)->postJson("/api/v1/customer-groups/{$group->id}/preview-rules")->json('data.total');

        $this->assertSame(2, $total, 'neither account has ever been active');
    }

    public function test_the_account_users_list_resolves_each_role_and_flags_the_last_owner(): void
    {
        [, , $token] = $this->org('super_admin');
        [$owner, $org] = $this->org('owner');

        $d = $this->api($token)->getJson("/api/v1/customer-accounts/{$org->id}/users")->assertOk()->json('data');

        $this->assertCount(1, $d['users']);
        $this->assertSame($owner->email, $d['users'][0]['email']);
        $this->assertTrue($d['users'][0]['is_last_owner']);
        $this->assertContains('billing.manage', $d['users'][0]['permissions']);
        $this->assertSame(0, $d['users'][0]['other_accounts']);
        $this->assertNotEmpty($d['roles']);
        $this->assertSame([], array_values(array_filter($d['roles'], fn ($r) => $r['name'] === 'super_admin')),
            'a platform role is never offered for a customer\'s own people');
    }

    public function test_the_last_owner_cannot_be_removed_through_the_customers_section(): void
    {
        [, , $token] = $this->org('super_admin');
        [$owner, $org] = $this->org('owner');

        $this->api($token)->deleteJson("/api/v1/customer-accounts/{$org->id}/users/{$owner->id}")->assertStatus(409);
        $this->assertDatabaseHas('memberships', ['organization_id' => $org->id, 'user_id' => $owner->id]);
    }

    public function test_adding_a_person_who_already_has_an_account_reuses_their_identity(): void
    {
        [, , $token] = $this->org('super_admin');
        [$existing] = $this->org('owner');
        [, $other] = $this->org('owner');

        $this->api($token)->postJson("/api/v1/customer-accounts/{$other->id}/users", [
            'email' => $existing->email, 'role' => 'developer',
        ])->assertCreated();

        $this->assertSame(1, \App\Models\User::where('email', $existing->email)->count(), 'no second identity');
        $d = $this->api($token)->getJson("/api/v1/customer-accounts/{$other->id}/users")->json('data');
        $this->assertSame(1, collect($d['users'])->firstWhere('email', $existing->email)['other_accounts']);
    }

    public function test_the_rule_field_catalogue_never_offers_a_column_the_evaluator_cannot_reach(): void
    {
        [, , $token] = $this->org('super_admin');

        $d = $this->api($token)->getJson('/api/v1/customer-group-fields')->assertOk()->json('data');

        $this->assertSame(array_keys(\App\Support\CustomerRules::FIELDS), array_column($d['fields'], 'key'));
        $this->assertContains('contains', $d['operators']);
    }
}
