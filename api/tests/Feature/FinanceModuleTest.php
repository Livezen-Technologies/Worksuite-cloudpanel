<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\RefundRequest;
use App\Models\Subscription;
use App\Services\FinanceService;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * FIN-1..10: the finance module end to end -- invoices, payments, refunds,
 * subscriptions and the figures derived from them.
 */
class FinanceModuleTest extends TestCase
{
    /** An administrator who may manage billing across every tenant. */
    private function staff(): array
    {
        [$user, $org, $token] = $this->org('super_admin');

        return [$user, $org, $token];
    }

    /**
     * The reference data already ships packages, and their codes are unique,
     * so a test package is created or adjusted rather than inserted blindly.
     */
    private function plan(string $code = 'standard', int $cents = 2000): Plan
    {
        return Plan::updateOrCreate(['code' => $code], [
            'name' => ucfirst($code), 'vcpu' => 1, 'ram_mb' => 2048,
            'storage_gb' => 20, 'price_cents' => $cents, 'currency' => 'EUR', 'limits' => ['apps' => 5],
        ]);
    }

    public function test_a_manual_invoice_totals_its_own_lines_and_takes_the_next_number(): void
    {
        [, $org, $token] = $this->staff();

        $res = $this->api($token)->postJson('/api/v1/finance/invoices', [
            'organization_id' => $org->id,
            'items' => [
                ['description' => 'Setup fee', 'quantity' => 1, 'unit_cents' => 5000],
                ['description' => 'Extra storage', 'quantity' => 3, 'unit_cents' => 250],
            ],
            'discount_cents' => 250,
        ])->assertCreated();

        // 5000 + 750 - 250, and the caller never got to state the total.
        $res->assertJsonPath('data.amount_cents', 5500);
        $res->assertJsonPath('data.number', 'INV-00001');
        $res->assertJsonPath('data.status', 'draft');

        $this->api($token)->postJson('/api/v1/finance/invoices', [
            'organization_id' => $org->id,
            'items' => [['description' => 'Second', 'quantity' => 1, 'unit_cents' => 100]],
        ])->assertCreated()->assertJsonPath('data.number', 'INV-00002');
    }

    public function test_recording_a_payment_settles_the_invoice_and_a_part_payment_does_not(): void
    {
        [, $org, $token] = $this->staff();
        $id = $this->api($token)->postJson('/api/v1/finance/invoices', [
            'organization_id' => $org->id, 'status' => 'open',
            'items' => [['description' => 'Hosting', 'quantity' => 1, 'unit_cents' => 10000]],
        ])->json('data.id');

        $this->api($token)->postJson("/api/v1/finance/invoices/{$id}/payments", ['amount_cents' => 4000])
            ->assertCreated()->assertJsonPath('data.invoice.status', 'partially_paid');

        $this->api($token)->postJson("/api/v1/finance/invoices/{$id}/payments", ['amount_cents' => 6000])
            ->assertCreated()->assertJsonPath('data.invoice.status', 'paid');

        $this->assertNotNull(Invoice::allTenants()->find($id)->paid_at);
    }

    public function test_the_same_gateway_reference_is_only_ever_recorded_once(): void
    {
        [, $org, $token] = $this->staff();
        $body = [
            'organization_id' => $org->id, 'amount_cents' => 2500,
            'gateway' => 'stripe', 'gateway_reference' => 'ch_test_dedupe',
        ];

        $first = $this->api($token)->postJson('/api/v1/finance/payments', $body)->assertCreated()->json('data.id');
        $again = $this->api($token)->postJson('/api/v1/finance/payments', $body)->assertCreated()->json('data.id');

        $this->assertSame($first, $again, 'a webhook delivered twice must not take the money twice');
        $this->assertSame(1, Payment::allTenants()->where('gateway_reference', 'ch_test_dedupe')->count());
    }

    public function test_a_refund_cannot_exceed_what_is_left_counting_requests_already_open(): void
    {
        [, $org, $token] = $this->staff();
        $invoiceId = $this->api($token)->postJson('/api/v1/finance/invoices', [
            'organization_id' => $org->id, 'status' => 'open',
            'items' => [['description' => 'Hosting', 'quantity' => 1, 'unit_cents' => 10000]],
        ])->json('data.id');
        $this->api($token)->postJson("/api/v1/finance/invoices/{$invoiceId}/payments", ['amount_cents' => 10000])->assertCreated();

        // More than was paid is refused outright.
        $this->api($token)->postJson('/api/v1/finance/refunds', [
            'organization_id' => $org->id, 'invoice_id' => $invoiceId,
            'amount_cents' => 15000, 'reason' => 'Too much',
        ])->assertStatus(422);

        // 6000 is fine; a second request for 6000 is not, because the first is still open.
        $this->api($token)->postJson('/api/v1/finance/refunds', [
            'organization_id' => $org->id, 'invoice_id' => $invoiceId,
            'amount_cents' => 6000, 'reason' => 'Partial',
        ])->assertCreated();

        $this->api($token)->postJson('/api/v1/finance/refunds', [
            'organization_id' => $org->id, 'invoice_id' => $invoiceId,
            'amount_cents' => 6000, 'reason' => 'Again',
        ])->assertStatus(422);
    }

    public function test_a_rejected_refund_stays_rejected_and_cannot_be_decided_twice(): void
    {
        [, $org, $token] = $this->staff();
        $invoiceId = $this->api($token)->postJson('/api/v1/finance/invoices', [
            'organization_id' => $org->id, 'status' => 'open',
            'items' => [['description' => 'Hosting', 'quantity' => 1, 'unit_cents' => 5000]],
        ])->json('data.id');
        $this->api($token)->postJson("/api/v1/finance/invoices/{$invoiceId}/payments", ['amount_cents' => 5000])->assertCreated();

        $refundId = $this->api($token)->postJson('/api/v1/finance/refunds', [
            'organization_id' => $org->id, 'invoice_id' => $invoiceId,
            'amount_cents' => 2000, 'reason' => 'Changed their mind',
        ])->json('data.id');

        $this->api($token)->postJson("/api/v1/finance/refunds/{$refundId}/reject", ['notes' => 'Outside the window'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->api($token)->postJson("/api/v1/finance/refunds/{$refundId}/approve")->assertStatus(422);
        $this->assertSame('rejected', RefundRequest::allTenants()->find($refundId)->status);
    }

    public function test_mrr_counts_a_yearly_subscription_as_a_twelfth_and_applies_the_discount(): void
    {
        [, $org] = $this->staff();
        $plan = $this->plan('pro', 12000);
        $before = app(FinanceService::class)->kpis(
            \Carbon\CarbonImmutable::now()->subMonth(),
            \Carbon\CarbonImmutable::now(),
        );

        app(Tenant::class)->as($org->id, function () use ($plan) {
            Subscription::create([
                'plan_id' => $plan->id, 'status' => 'active', 'billing_cycle' => 'yearly',
                'discount_percent' => 25, 'current_period_start' => now(), 'current_period_end' => now()->addYear(),
            ]);
        });
        app(Tenant::class)->set(null);

        $after = app(FinanceService::class)->kpis(
            \Carbon\CarbonImmutable::now()->subMonth(),
            \Carbon\CarbonImmutable::now(),
        );

        // 12000 less 25% is 9000; a yearly price contributes a twelfth each month.
        $this->assertSame(750, $after['mrr_cents'] - $before['mrr_cents']);
        $this->assertSame($after['mrr_cents'] * 12, $after['arr_cents']);
    }

    public function test_changing_a_package_prorates_the_days_left_and_logs_what_changed(): void
    {
        [, $org, $token] = $this->staff();
        $small = $this->plan('small', 1000);
        $large = $this->plan('large', 3000);

        Carbon::setTestNow('2026-06-16 00:00:00');
        $sub = app(Tenant::class)->as($org->id, fn () => Subscription::create([
            'plan_id' => $small->id, 'status' => 'active', 'billing_cycle' => 'monthly',
            'current_period_start' => Carbon::parse('2026-06-01'), 'current_period_end' => Carbon::parse('2026-07-01'),
        ]));
        app(Tenant::class)->set(null);

        $quote = $this->api($token)->postJson("/api/v1/finance/subscriptions/{$sub->id}/quote-change", [
            'plan_id' => $large->id,
        ])->assertOk()->json('data');

        // 15 of 30 days left: half of 1000 back, half of 3000 charged.
        $this->assertSame(15, $quote['days_remaining']);
        $this->assertSame(500, $quote['credit_cents']);
        $this->assertSame(1500, $quote['charge_cents']);
        $this->assertSame(1000, $quote['proration_cents']);

        $this->api($token)->postJson("/api/v1/finance/subscriptions/{$sub->id}/change-plan", [
            'plan_id' => $large->id,
        ])->assertOk()->assertJsonPath('data.plan_code', 'large');

        $this->assertDatabaseHas('audit_logs', ['action' => 'subscription.plan_changed', 'organization_id' => $org->id]);
        Carbon::setTestNow();
    }

    public function test_a_change_booked_for_renewal_leaves_the_package_alone_until_then(): void
    {
        [, $org, $token] = $this->staff();
        $small = $this->plan('small', 1000);
        $large = $this->plan('large', 3000);
        $sub = app(Tenant::class)->as($org->id, fn () => Subscription::create([
            'plan_id' => $small->id, 'status' => 'active', 'billing_cycle' => 'monthly',
            'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        ]));
        app(Tenant::class)->set(null);

        $this->api($token)->postJson("/api/v1/finance/subscriptions/{$sub->id}/change-plan", [
            'plan_id' => $large->id, 'when' => 'renewal',
        ])->assertOk()->assertJsonPath('data.plan_code', 'small');

        $fresh = Subscription::allTenants()->find($sub->id);
        $this->assertSame($large->id, $fresh->pending_plan_id);
        $this->assertSame($small->id, $fresh->plan_id);
    }

    public function test_cancelling_records_when_so_churn_can_be_measured(): void
    {
        [, $org, $token] = $this->staff();
        $plan = $this->plan();
        $sub = app(Tenant::class)->as($org->id, fn () => Subscription::create([
            'plan_id' => $plan->id, 'status' => 'active',
            'current_period_start' => now()->subMonths(2), 'current_period_end' => now()->addMonth(),
            'created_at' => now()->subMonths(2),
        ]));
        app(Tenant::class)->set(null);

        $this->api($token)->postJson("/api/v1/finance/subscriptions/{$sub->id}/transition", ['action' => 'cancel'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertNotNull(Subscription::allTenants()->find($sub->id)->cancelled_at);

        $rate = app(FinanceService::class)->churnRate(
            \Carbon\CarbonImmutable::now()->subMonth(),
            \Carbon\CarbonImmutable::now()->addDay(),
        );
        $this->assertSame(100.0, $rate, 'the only subscription that existed was lost');
    }

    public function test_transactions_show_refunds_as_money_going_out(): void
    {
        [, $org, $token] = $this->staff();
        app(Tenant::class)->as($org->id, function () use ($org) {
            Payment::create([
                'organization_id' => $org->id, 'amount_cents' => 5000, 'currency' => 'EUR',
                'gateway' => 'manual', 'status' => 'succeeded', 'paid_at' => now(),
            ]);
            RefundRequest::create([
                'organization_id' => $org->id, 'amount_cents' => 1500, 'currency' => 'EUR',
                'status' => 'refunded', 'reason' => 'Goodwill', 'refunded_at' => now(),
            ]);
        });
        app(Tenant::class)->set(null);

        $body = $this->api($token)->getJson('/api/v1/finance/transactions')->assertOk()->json('data');

        $this->assertSame(5000, $body['totals']['in_cents']);
        $this->assertSame(-1500, $body['totals']['out_cents']);
        $this->assertSame(['payment', 'refund'], collect($body['rows'])->pluck('kind')->sort()->values()->all());
    }

    public function test_finance_is_closed_to_a_role_without_billing(): void
    {
        [, , $token] = $this->org('developer');

        $this->api($token)->getJson('/api/v1/finance/overview')->assertForbidden();
        $this->api($token)->getJson('/api/v1/finance/invoices')->assertForbidden();
        $this->api($token)->postJson('/api/v1/finance/payments', ['organization_id' => 'x', 'amount_cents' => 1])->assertForbidden();
    }

    public function test_settings_round_trip_and_feed_the_invoice_number(): void
    {
        [, $org, $token] = $this->staff();

        $this->api($token)->patchJson('/api/v1/finance/settings', [
            'invoice_prefix' => 'WS-', 'invoice_number_padding' => 3, 'currency' => 'lkr',
            'tax_enabled' => true, 'tax_percent' => 15,
        ])->assertOk()->assertJsonPath('data.settings.currency', 'LKR');

        $this->api($token)->postJson('/api/v1/finance/invoices', [
            'organization_id' => $org->id,
            'items' => [['description' => 'Hosting', 'quantity' => 1, 'unit_cents' => 1000]],
        ])->assertCreated()
            ->assertJsonPath('data.number', 'WS-001')
            ->assertJsonPath('data.tax_cents', 150)
            ->assertJsonPath('data.amount_cents', 1150);
    }
}
