<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Plan;
use App\Models\Subscription;
use Tests\TestCase;

/** CUS-6: the customer list shows status and apps against the package, and flags customers over it. */
class CustomerListUsageTest extends TestCase
{
    public function test_status_and_app_usage_against_the_package(): void
    {
        [, , $staff] = $this->org('super_admin');
        [, $acme] = $this->org('owner', 'Acme Lanka');
        $plan = Plan::create(['code' => 'tiny-'.uniqid(), 'name' => 'Tiny', 'vcpu' => 0.5, 'ram_mb' => 512, 'storage_gb' => 5, 'price_cents' => 100, 'limits' => ['apps' => 1]]);
        Subscription::withoutGlobalScopes()->create(['organization_id' => $acme->id, 'plan_id' => $plan->id, 'status' => 'active']);
        foreach (['a', 'b'] as $n) {
            Application::allTenants()->create(['organization_id' => $acme->id, 'name' => "app-{$n}", 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx:1']);
        }

        $row = collect($this->api($staff)->getJson('/api/v1/customers')->assertOk()->json('data'))->firstWhere('id', $acme->id);
        $this->assertSame(['active', 2, 1, true, 1], [$row['status'], $row['apps'], $row['app_limit'], $row['over_limit'], $row['users']]);
    }
}
