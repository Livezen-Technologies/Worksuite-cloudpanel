<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Drivers\StripePaymentGateway;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use App\Support\Tenant;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/** UBIL-003 with PAYMENT_DRIVER=stripe: mocked Stripe HTTP + signed webhook fixtures. */
class StripeTest extends TestCase
{
    private object $http;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.drivers.payments' => 'stripe', 'billing.stripe.secret' => 'sk_test_x', 'billing.stripe.webhook_secret' => 'whsec_test',
            'billing.stripe.prices' => ['small' => 'price_small', 'standard' => 'price_standard', 'pro' => 'price_pro']]);
        $this->app->forgetInstance(PaymentGateway::class);
        $this->app->singleton(PaymentGateway::class, fn () => new StripePaymentGateway(config('billing.stripe')));
        $this->http = new class implements ClientInterface
        {
            public array $requests = [];

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->requests[] = [$method, parse_url($absUrl, PHP_URL_PATH), $params];
                $path = parse_url($absUrl, PHP_URL_PATH);
                $body = match (true) {
                    $path === '/v1/customers' => ['id' => 'cus_123', 'object' => 'customer'],
                    str_starts_with($path, '/v1/customers/') => ['id' => 'cus_123', 'object' => 'customer'],
                    str_ends_with($path, '/attach') => ['id' => 'pm_123', 'object' => 'payment_method', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 4, 'exp_year' => 2030]],
                    str_starts_with($path, '/v1/payment_methods/') => ['id' => 'pm_456', 'object' => 'payment_method', 'card' => ['brand' => 'mastercard', 'last4' => '4444', 'exp_month' => 1, 'exp_year' => 2031]],
                    $path === '/v1/checkout/sessions' => ['id' => 'cs_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_1'],
                    $path === '/v1/billing_portal/sessions' => ['id' => 'bps_1', 'object' => 'billing_portal.session', 'url' => 'https://billing.stripe.com/p/session/bps_1'],
                    $path === '/v1/subscriptions' => ['id' => 'sub_1Fixture', 'object' => 'subscription', 'status' => 'incomplete'],
                    str_starts_with($path, '/v1/setup_intents/') => ['id' => 'seti_1Fixture', 'object' => 'setup_intent', 'payment_method' => 'pm_456'],
                    $path === '/v1/invoiceitems' => ['id' => 'ii_'.count($this->requests), 'object' => 'invoiceitem'],
                    default => ['error' => ['message' => 'unexpected '.$path]],
                };

                return [json_encode($body), isset($body['error']) ? 404 : 200, []];
            }
        };
        ApiRequestor::setHttpClient($this->http);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    private function paths(): array
    {
        return array_map(fn ($r) => strtoupper($r[0]).' '.$r[1], $this->http->requests);
    }

    private function signed(string $payload, string $secret = 'whsec_test', ?int $t = null): array
    {
        $t ??= time();

        return ['HTTP_STRIPE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$payload}", $secret), 'CONTENT_TYPE' => 'application/json'];
    }

    private function fixture(string $name, array $replace): string
    {
        return strtr(file_get_contents(base_path("tests/Fixtures/stripe/{$name}.json")), $replace);
    }

    public function test_customer_portal_checkout_card_and_subscription_go_through_stripe(): void
    {
        [, $org, $token] = $this->org();
        $summary = $this->api($token)->getJson('/api/v1/app/billing')->assertOk()->json('data');
        $this->assertSame('stripe', $summary['payment_provider']);
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_1', $summary['payment_portal_url'], 'no card yet: Checkout (setup mode)');
        $this->assertContains('POST /v1/customers', $this->paths());
        $this->assertSame('cus_123', Organization::find($org->id)->payment_customer_id);

        $this->api($token)->postJson('/api/v1/app/billing/payment-method', ['payment_method_token' => 'pm_123'])->assertOk()
            ->assertJsonPath('data.payment_method.last4', '4242');
        $this->api($token)->postJson('/api/v1/app/billing/payment-method', ['payment_method_token' => 'pm_1', 'card_number' => '4242424242424242'])->assertStatus(422);
        $this->assertSame('https://billing.stripe.com/p/session/bps_1', $this->api($token)->postJson('/api/v1/app/billing/portal')->assertOk()->json('data.url'));

        $this->api($token)->postJson('/api/v1/app/billing/plan', ['plan' => 'standard'])->assertOk();
        $sub = collect($this->http->requests)->first(fn ($r) => $r[1] === '/v1/subscriptions');
        $this->assertSame('price_standard', $sub[2]['items'][0]['price']);
        $this->assertSame('sub_1Fixture', Subscription::allTenants()->where('organization_id', $org->id)->latest()->value('provider_subscription_id'));
        foreach ($this->http->requests as $r) {
            $this->assertArrayNotHasKey('number', $r[2] ?? [], 'no card numbers are ever sent by us');
        }
    }

    public function test_webhooks_verify_signatures_and_update_invoice_and_subscription_status(): void
    {
        [, $org, $token] = $this->org();
        Organization::find($org->id)->forceFill(['payment_customer_id' => 'cus_123'])->save();
        $this->api($token)->getJson('/api/v1/app/billing')->assertOk();
        $invoice = app(Tenant::class)->as($org->id, function () {
            Subscription::latest()->first()->update(['provider_subscription_id' => 'sub_1Fixture']);

            return Invoice::create(['number' => 'INV-T-1', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'amount_cents' => 1785, 'currency' => 'EUR', 'status' => 'open', 'line_items' => []]);
        });
        app(Tenant::class)->set(null);

        $payload = $this->fixture('invoice.paid', ['__CUSTOMER__' => 'cus_123', '__INVOICE__' => $invoice->id]);
        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], $this->signed($payload, 'whsec_wrong'), $payload)->assertStatus(400);
        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], $this->signed($payload, 'whsec_test', time() - 3600), $payload)->assertStatus(400);
        $this->assertSame('open', $invoice->fresh()->status);

        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], $this->signed($payload), $payload)->assertOk()->assertJsonPath('duplicate', false);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('in_1PaidFixture', $invoice->fresh()->provider_invoice_id);
        $invoice->refresh()->update(['status' => 'open']);
        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], $this->signed($payload), $payload)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame('open', $invoice->fresh()->status, 'replayed events are ignored');

        $sub = $this->fixture('customer.subscription.updated', ['__CUSTOMER__' => 'cus_123']);
        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], $this->signed($sub), $sub)->assertOk();
        $local = Subscription::allTenants()->where('provider_subscription_id', 'sub_1Fixture')->first();
        $this->assertSame('past_due', $local->status);
        $this->assertSame('2026-11-01', $local->current_period_end->toDateString());

        $checkout = $this->fixture('checkout.session.completed', ['__CUSTOMER__' => 'cus_123']);
        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], $this->signed($checkout), $checkout)->assertOk();
        $this->assertSame('4444', Organization::find($org->id)->payment_method['last4']);
    }

    public function test_usage_lines_become_stripe_invoice_items(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        app(Tenant::class)->as($org->id, fn () => \App\Models\Application::create(['name' => 'shop', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'size' => 'small', 'server_id' => $server->id]));
        $this->api($token)->getJson('/api/v1/app/billing')->assertOk();
        app(Tenant::class)->set(null);
        app(\App\Services\InvoiceService::class)->meter(now()->startOfMonth()->addHour());
        $this->artisan('billing:invoice', ['--org' => $org->id, '--month' => now()->format('Y-m')])->assertSuccessful();
        $items = collect($this->http->requests)->filter(fn ($r) => $r[1] === '/v1/invoiceitems')->values();
        $this->assertCount(2, $items, 'app line + tax, plan is charged by the subscription');
        $this->assertSame(Invoice::allTenants()->first()->id, $items[0][2]['metadata']['dmp_invoice_id']);
        $this->assertSame('open', Invoice::allTenants()->first()->status);
    }
}
