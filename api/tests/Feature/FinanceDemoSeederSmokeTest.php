<?php

namespace Tests\Feature;

use Tests\TestCase;

class FinanceDemoSeederSmokeTest extends TestCase
{
    public function test_the_demo_seeder_produces_a_consistent_panel(): void
    {
        $this->artisan('db:seed', ['--class' => \Database\Seeders\FinanceDemoSeeder::class])->assertSuccessful();

        $this->assertSame(6, \App\Models\Plan::count());
        $this->assertSame(150, \App\Models\Subscription::allTenants()->count());
        $this->assertSame(350, \App\Models\Invoice::allTenants()->count());
        $this->assertGreaterThan(100, \App\Models\Payment::allTenants()->count());
        $this->assertSame(25, \App\Models\RefundRequest::allTenants()->count());

        [, , $token] = $this->org('super_admin');
        $k = $this->api($token)->getJson('/api/v1/finance/overview')->assertOk()->json('data.kpis');
        $this->assertGreaterThan(0, $k['total_revenue_cents']);
        $this->assertGreaterThan(0, $k['mrr_cents']);
        $this->assertGreaterThan(0, $k['overdue_invoices']);
    }
}
