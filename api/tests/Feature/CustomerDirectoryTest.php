<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\CustomerGroup;
use App\Models\CustomerGroupMember;
use App\Models\Organization;
use App\Services\CustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** CUST-1..5: the customer directory, companies, contacts and groups. */
class CustomerDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_directory_pages_and_shows_the_status_column(): void
    {
        [, , $token] = $this->org('super_admin');
        [, $other] = $this->org('owner');

        $r = $this->api($token)->getJson('/api/v1/customer-accounts?per_page=50');

        $r->assertOk();
        $names = collect($r->json('data'))->pluck('name');
        $this->assertTrue($names->contains($other->name), 'the directory spans tenants');
        $this->assertSame('active', collect($r->json('data'))->firstWhere('name', $other->name)['status']);
        $this->assertArrayHasKey('total', $r->json('meta'));
    }

    public function test_a_customers_plan_is_not_hidden_by_the_readers_own_tenant_scope(): void
    {
        [, , $token] = $this->org('super_admin');
        [, $other] = $this->org('owner');

        // The reader's tenant is their own organisation, so a relation left
        // under the organisation scope would come back empty here.
        $row = collect($this->api($token)->getJson('/api/v1/customer-accounts?per_page=50')->json('data'))
            ->firstWhere('name', $other->name);

        $this->assertNotNull($row['subscription_status'], 'the subscription resolved across tenants');
        $this->assertNotNull($row['email'], 'the owner resolved across tenants');
    }

    public function test_suspending_sets_the_column_and_keeps_the_reason(): void
    {
        [, , $token] = $this->org('super_admin');
        [, $other] = $this->org('owner');

        $this->api($token)->postJson("/api/v1/customer-accounts/{$other->id}/status", [
            'status' => 'suspended', 'reason' => 'Chargeback raised.',
        ])->assertOk()->assertJsonPath('data.status', 'suspended');

        $fresh = Organization::find($other->id);
        $this->assertSame('suspended', $fresh->status);
        $this->assertSame('Chargeback raised.', $fresh->settings['suspension']['reason']);
        $this->assertDatabaseHas('customer_activities', ['organization_id' => $other->id, 'kind' => 'status_changed']);
    }

    public function test_suspending_without_a_reason_is_refused(): void
    {
        [, , $token] = $this->org('super_admin');
        [, $other] = $this->org('owner');

        $this->api($token)->postJson("/api/v1/customer-accounts/{$other->id}/status", ['status' => 'suspended'])
            ->assertStatus(422);
    }

    public function test_a_closed_account_is_not_reopened_from_the_directory(): void
    {
        [, , $token] = $this->org('super_admin');
        [, $other] = $this->org('owner');
        app(CustomerService::class)->setStatus($other, 'closed', 'Asked to close.', null);

        $this->api($token)->postJson("/api/v1/customer-accounts/{$other->id}/status", ['status' => 'active'])
            ->assertStatus(409);
    }

    public function test_the_archive_is_a_separate_view(): void
    {
        [, , $token] = $this->org('super_admin');
        [, $other] = $this->org('owner');

        $this->api($token)->postJson("/api/v1/customer-accounts/{$other->id}/archive")->assertOk();

        $live = collect($this->api($token)->getJson('/api/v1/customer-accounts?per_page=50')->json('data'))->pluck('name');
        $archived = collect($this->api($token)->getJson('/api/v1/customer-accounts?archived=1&per_page=50')->json('data'))->pluck('name');

        $this->assertFalse($live->contains($other->name));
        $this->assertTrue($archived->contains($other->name));
    }

    public function test_groups_seed_once_and_the_built_in_ones_cannot_be_deleted(): void
    {
        [, , $token] = $this->org('super_admin');

        $first = $this->api($token)->getJson('/api/v1/customer-groups')->assertOk()->json('data');
        $this->assertCount(count(CustomerService::DEFAULT_GROUPS), $first);

        $again = $this->api($token)->getJson('/api/v1/customer-groups')->json('data');
        $this->assertCount(count($first), $again, 'the defaults are seeded once, not on every read');

        $system = collect($again)->firstWhere('is_system', true);
        $this->api($token)->deleteJson("/api/v1/customer-groups/{$system['id']}")->assertStatus(409);
    }

    public function test_the_largest_group_discount_wins(): void
    {
        [, $org] = $this->org('owner');
        $cheap = CustomerGroup::create(['name' => 'Cheap', 'slug' => 'cheap', 'discount_percent' => 5]);
        $deep = CustomerGroup::create(['name' => 'Deep', 'slug' => 'deep', 'discount_percent' => 25]);
        foreach ([$cheap, $deep] as $g) {
            CustomerGroupMember::create(['organization_id' => $org->id, 'customer_group_id' => $g->id]);
        }

        $this->assertSame(25, app(CustomerService::class)->groupDiscountPercent($org));
    }

    public function test_setting_groups_replaces_manual_membership_and_leaves_rule_rows(): void
    {
        [$user, , $token] = $this->org('super_admin');
        [, $other] = $this->org('owner');
        $a = CustomerGroup::create(['name' => 'A', 'slug' => 'a']);
        $b = CustomerGroup::create(['name' => 'B', 'slug' => 'b']);
        $byRule = CustomerGroup::create(['name' => 'Rule', 'slug' => 'rule', 'kind' => 'dynamic']);
        CustomerGroupMember::create(['organization_id' => $other->id, 'customer_group_id' => $a->id, 'source' => 'manual']);
        CustomerGroupMember::create(['organization_id' => $other->id, 'customer_group_id' => $byRule->id, 'source' => 'rule']);

        $this->api($token)->postJson("/api/v1/customer-accounts/{$other->id}/groups", ['group_ids' => [$b->id]])->assertOk();

        $have = CustomerGroupMember::allTenants()->where('organization_id', $other->id)->pluck('customer_group_id');
        $this->assertFalse($have->contains($a->id), 'the manual row it replaced is gone');
        $this->assertTrue($have->contains($b->id));
        $this->assertTrue($have->contains($byRule->id), 'a rule-driven row is not swept away by a manual edit');
    }

    public function test_only_one_contact_is_primary(): void
    {
        [, , $token] = $this->org('super_admin');
        [, $other] = $this->org('owner');

        foreach (['First', 'Second'] as $i => $name) {
            $this->api($token)->postJson("/api/v1/customer-accounts/{$other->id}/contacts", [
                'name' => $name, 'email' => strtolower($name).'@example.test',
                'role' => $i === 0 ? 'billing' : 'technical', 'is_primary' => true,
            ])->assertCreated();
        }

        $primary = Contact::allTenants()->where('organization_id', $other->id)->where('is_primary', true)->get();
        $this->assertCount(1, $primary);
        $this->assertSame('Second', $primary->first()->name);
    }

    public function test_the_company_is_the_billing_profile_not_a_second_record(): void
    {
        [, , $token] = $this->org('super_admin');
        [, $other] = $this->org('owner');

        $this->api($token)->patchJson("/api/v1/companies/{$other->id}", [
            'company_name' => 'Thicket Works Ltd', 'registration_number' => 'RC-8891', 'industry' => 'Retail',
        ])->assertOk()->assertJsonPath('data.company_name', 'Thicket Works Ltd');

        $this->assertDatabaseHas('billing_profiles', [
            'organization_id' => $other->id, 'company_name' => 'Thicket Works Ltd', 'registration_number' => 'RC-8891',
        ]);
        $this->assertSame(1, \App\Models\BillingProfile::allTenants()->where('organization_id', $other->id)->count());
    }

    public function test_the_overview_counts_what_the_dashboard_shows(): void
    {
        [, , $token] = $this->org('super_admin');
        [, $other] = $this->org('owner');
        app(CustomerService::class)->setStatus($other, 'suspended', 'Testing.', null);

        $d = $this->api($token)->getJson('/api/v1/customer-accounts/overview')->assertOk()->json('data');

        $this->assertSame(2, $d['total_customers']);
        $this->assertSame(1, $d['suspended_customers']);
        $this->assertSame(1, $d['active_customers']);
        $this->assertCount(12, $d['growth'], 'twelve months of growth, oldest first');
    }

    public function test_retention_is_null_when_nobody_was_there_to_retain(): void
    {
        [, , $token] = $this->org('super_admin');

        // Every organisation in this test was created just now, so nothing
        // existed when the window opened -- 100% would be a lie.
        $d = $this->api($token)->getJson('/api/v1/customer-accounts/overview?range=30d')->json('data');

        $this->assertNull($d['retention_rate']);
    }

    public function test_the_activity_timeline_records_what_staff_did(): void
    {
        [, , $token] = $this->org('super_admin');
        [, $other] = $this->org('owner');

        $this->api($token)->patchJson("/api/v1/customer-accounts/{$other->id}", ['customer_type' => 'enterprise'])->assertOk();

        $rows = $this->api($token)->getJson("/api/v1/customer-accounts/{$other->id}/activities")->assertOk()->json('data');
        $this->assertSame('profile_updated', $rows[0]['kind']);
        $this->assertNotNull(Organization::find($other->id)->last_activity_at);
    }

    public function test_customers_are_closed_to_a_developer(): void
    {
        [, , $token] = $this->org('developer');

        $this->api($token)->getJson('/api/v1/customer-accounts')->assertStatus(403);
        $this->api($token)->getJson('/api/v1/customer-groups')->assertStatus(403);
    }

    public function test_support_may_read_and_write_customers(): void
    {
        [, , $token] = $this->org('support');

        $this->api($token)->getJson('/api/v1/customer-accounts')->assertOk();
        $this->api($token)->getJson('/api/v1/customer-accounts/filters')->assertOk();
    }

    public function test_the_csv_export_honours_the_filter_on_screen(): void
    {
        [, , $token] = $this->org('super_admin');
        [, $other] = $this->org('owner');
        app(CustomerService::class)->setStatus($other, 'suspended', 'Testing.', null);

        $csv = $this->api($token)->get('/api/v1/customer-accounts/export?status=suspended')
            ->assertOk()->streamedContent();

        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(2, $lines, 'the header and the one suspended customer');
        $this->assertStringContainsString($other->name, $lines[1]);
    }
}
