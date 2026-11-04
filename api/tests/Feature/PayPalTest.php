<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Drivers\PayPalPaymentGateway;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use App\Support\Tenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** UBIL-003 with PAYMENT_DRIVER=paypal: faked PayPal REST API + verified webhooks. */
class PayPalTest extends TestCase
{
    private bool $signatureOk = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.drivers.payments' => 'paypal', 'billing.paypal' => [
            'client_id' => 'cid', 'secret' => 'sec', 'webhook_id' => 'WH-1', 'mode' => 'sandbox', 'return_url' => 'https://panel.test/app/billing',
            'plans' => ['small' => 'P-SMALL', 'standard' => 'P-STANDARD', 'pro' => 'P-PRO'],
        ]]);
        $this->app->forgetInstance(PaymentGateway::class);
        $this->app->singleton(PaymentGateway::class, fn () => new PayPalPaymentGateway(config('billing.paypal')));
        Http::fake(function (Request $r) {
            $path = parse_url($r->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/v1/oauth2/token' => Http::response(['access_token' => 'A21']),
                $path === '/v1/billing/subscriptions' && $r->method() === 'POST' => Http::response(['id' => 'I-NEW1', 'status' => 'APPROVAL_PENDING',
                    'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/webapps/billing/subscriptions?ba_token=BA-1']]], 201),
                str_ends_with($path, '/revise') => Http::response(['plan_id' => 'P-PRO', 'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/revise/BA-2']]]),
                str_ends_with($path, '/capture') => Http::response(['id' => 'TX-9'], 202),
                str_starts_with($path, '/v1/billing/subscriptions/') => Http::response(['id' => 'I-NEW1', 'status' => 'ACTIVE', 'plan_id' => 'P-STANDARD']),
                $path === '/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => $this->signatureOk ? 'SUCCESS' : 'FAILURE']),
                default => Http::response(['message' => 'unexpected '.$path], 404),
            };
        });
    }

    private function event(string $type, array $resource, ?string $id = null): array
    {
        return ['id' => $id ?? 'WH-EV-'.uniqid(), 'event_type' => $type, 'resource' => $resource];
    }

    private function webhook(array $event)
    {
        return $this->withHeaders(['PAYPAL-TRANSMISSION-ID' => 't1', 'PAYPAL-TRANSMISSION-SIG' => 's', 'PAYPAL-CERT-URL' => 'https://api.paypal.com/cert', 'PAYPAL-AUTH-ALGO' => 'SHA256withRSA', 'PAYPAL-TRANSMISSION-TIME' => now()->toIso8601String()])
            ->postJson('/api/v1/webhooks/paypal', $event);
    }

    public function test_plan_change_creates_a_paypal_subscription_and_portal_returns_the_approval_link(): void
    {
        [, $org, $token] = $this->org();
        $summary = $this->api($token)->getJson('/api/v1/app/billing')->assertOk()->json('data');
        $this->assertSame('paypal', $summary['payment_provider']);
        $this->assertNull($summary['payment_portal_url'], 'viewing billing never creates a subscription');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/v1/billing/subscriptions'));

        $this->api($token)->postJson('/api/v1/app/billing/plan', ['plan' => 'standard'])->assertOk();
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api-m.sandbox.paypal.com/v1/billing/subscriptions' && $r['plan_id'] === 'P-STANDARD' && $r['custom_id'] === $org->id);
        $this->assertSame('I-NEW1', Subscription::allTenants()->where('organization_id', $org->id)->latest()->value('provider_subscription_id'));
        $this->assertStringContainsString('ba_token=BA-1', $this->api($token)->postJson('/api/v1/app/billing/portal')->assertOk()->json('data.url'));

        $this->api($token)->postJson('/api/v1/app/billing/plan', ['plan' => 'pro'])->assertOk();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/I-NEW1/revise') && $r['plan_id'] === 'P-PRO');
        $this->api($token)->postJson('/api/v1/app/billing/payment-method', ['payment_method_token' => 'pm_123'])->assertStatus(409);
    }

    public function test_webhooks_are_verified_applied_once_and_update_status(): void
    {
        [, $org] = $this->org();
        $invoice = app(Tenant::class)->as($org->id, function () {
            app(\App\Services\BillingService::class)->subscription()->update(['provider_subscription_id' => 'I-NEW1', 'status' => 'past_due']);

            return Invoice::create(['number' => 'INV-P-1', 'period_start' => now()->subMonth()->startOfMonth(), 'period_end' => now()->subMonth()->endOfMonth(), 'amount_cents' => 900, 'currency' => 'EUR', 'status' => 'open', 'line_items' => []]);
        });
        app(Tenant::class)->set(null);

        $activated = $this->event('BILLING.SUBSCRIPTION.ACTIVATED', ['id' => 'I-NEW1', 'custom_id' => $org->id, 'subscriber' => ['email_address' => 'payer@example.com'], 'billing_info' => ['next_billing_time' => '2026-12-01T10:00:00Z']], 'WH-A');
        $this->signatureOk = false;
        $this->webhook($activated)->assertStatus(400);
        $this->assertSame('past_due', Subscription::allTenants()->where('provider_subscription_id', 'I-NEW1')->value('status'));

        $this->signatureOk = true;
        $this->webhook($activated)->assertOk()->assertJsonPath('duplicate', false);
        $sub = Subscription::allTenants()->where('provider_subscription_id', 'I-NEW1')->first();
        $this->assertSame('active', $sub->status);
        $this->assertSame('2026-12-01', $sub->current_period_end->toDateString());
        $this->assertSame('payer@example.com', Organization::find($org->id)->payment_method['email']);
        $this->webhook($activated)->assertOk()->assertJsonPath('duplicate', true);

        $this->webhook($this->event('PAYMENT.SALE.COMPLETED', ['id' => 'SALE-1', 'billing_agreement_id' => 'I-NEW1']))->assertOk();
        $this->assertSame('paid', $invoice->fresh()->status);

        $this->webhook($this->event('BILLING.SUBSCRIPTION.CANCELLED', ['id' => 'I-NEW1', 'custom_id' => $org->id]))->assertOk();
        $this->assertSame('canceled', $sub->fresh()->status);
        $this->assertNull(Organization::find($org->id)->payment_method);
    }

    public function test_usage_is_captured_on_the_subscription(): void
    {
        [, $org] = $this->org();
        $gateway = app(PaymentGateway::class);
        $invoice = app(Tenant::class)->as($org->id, function () {
            app(\App\Services\BillingService::class)->subscription()->update(['provider_subscription_id' => 'I-NEW1']);

            return Invoice::create(['number' => 'INV-P-2', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'amount_cents' => 1190, 'currency' => 'EUR', 'status' => 'open', 'tax_rate' => 0.19,
                'line_items' => [['kind' => 'usage', 'description' => 'Bandwidth', 'amount_cents' => 1000]]]);
        });

        $result = $gateway->collect(Organization::find($org->id), $invoice);
        $this->assertSame(['status' => 'paid', 'provider_invoice_id' => 'TX-9'], $result);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/I-NEW1/capture') && $r['amount']['value'] === '11.90' && $r->header('PayPal-Request-Id')[0] === 'dmp-'.$invoice->id.'-1');
    }

    public function test_credentials_fall_back_to_service_settings(): void
    {
        [, $org] = $this->org();
        config(['billing.paypal.client_id' => null, 'billing.paypal.secret' => null, 'billing.paypal.webhook_id' => null]);
        app(Tenant::class)->as($org->id, fn () => \App\Models\Integration::create(['name' => 'PayPal', 'provider' => 'paypal', 'config' => ['client_id' => 'ui-cid', 'webhook_id' => 'WH-UI', 'mode' => 'live'], 'credentials' => ['client_secret' => 'ui-sec']]));
        $cfg = PayPalPaymentGateway::config();
        $this->assertSame(['ui-cid', 'ui-sec', 'WH-UI', 'live'], [$cfg['client_id'], $cfg['secret'], $cfg['webhook_id'], $cfg['mode']]);
    }
}
