<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Subscription;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Customers choose card (Stripe) or PayPal when both are configured; never billed by two at once. */
class PaymentProviderChoiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.stripe.secret' => 'sk_test_x', 'billing.paypal' => [
            'client_id' => 'cid', 'secret' => 'sec', 'webhook_id' => 'WH-1', 'mode' => 'sandbox', 'return_url' => 'https://panel.test/app/billing',
            'plans' => ['small' => 'P-SMALL', 'standard' => 'P-STANDARD', 'pro' => 'P-PRO'],
        ]]);
        Http::fake(function (Request $r) {
            $path = parse_url($r->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/v1/oauth2/token' => Http::response(['access_token' => 'A21']),
                str_ends_with($path, '/cancel') => Http::response(null, 204),
                $path === '/v1/billing/subscriptions' => Http::response(['id' => 'I-PP1', 'status' => 'APPROVAL_PENDING', 'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/approve/1']]], 201),
                default => Http::response([], 404),
            };
        });
    }

    public function test_the_customer_picks_paypal_and_cannot_switch_away_with_a_live_paypal_subscription(): void
    {
        [, $org, $token] = $this->org();
        $summary = $this->api($token)->getJson('/api/v1/app/billing')->assertOk()->json('data');
        $this->assertSame(['fake', 'stripe', 'paypal'], array_column($summary['payment_providers'], 'id'), 'the default plus every configured provider');
        $this->assertSame('fake', $summary['payment_provider']);

        $this->api($token)->postJson('/api/v1/app/billing/provider', ['provider' => 'bitcoin'])->assertStatus(422);
        $this->api($token)->postJson('/api/v1/app/billing/provider', ['provider' => 'paypal'])->assertOk()->assertJsonPath('data.payment_provider', 'paypal');
        $this->assertSame('paypal', Organization::find($org->id)->payment_provider);
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.provider_changed', 'organization_id' => $org->id]);

        // A plan change now goes to PayPal, not the default.
        $this->api($token)->postJson('/api/v1/app/billing/plan', ['plan' => 'standard'])->assertOk();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/billing/subscriptions') && $r['plan_id'] === 'P-STANDARD');
        $this->assertSame('I-PP1', Subscription::allTenants()->where('organization_id', $org->id)->value('provider_subscription_id'));

        // With that PayPal subscription live, switching to card is refused rather than billing twice.
        $this->api($token)->postJson('/api/v1/app/billing/provider', ['provider' => 'stripe'])->assertStatus(409)
            ->assertJsonPath('error.message', 'You have an active PayPal subscription. Cancel it there first, or contact support to switch.');
        // Cancelling in the panel cancels it at PayPal, after which switching is allowed.
        $this->api($token)->postJson('/api/v1/app/billing/cancel')->assertOk()->assertJsonPath('data.subscription_status', 'canceled');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/billing/subscriptions/I-PP1/cancel'));
        $this->api($token)->postJson('/api/v1/app/billing/provider', ['provider' => 'stripe'])->assertOk()->assertJsonPath('data.payment_provider', 'stripe');
        $this->assertNull(Organization::find($org->id)->payment_customer_id, 'the PayPal customer reference is not reused by Stripe');
    }

    public function test_a_provider_that_is_no_longer_configured_falls_back_to_the_default(): void
    {
        [, $org, $token] = $this->org();
        Organization::find($org->id)->forceFill(['payment_provider' => 'paypal'])->save();
        config(['billing.paypal.client_id' => null, 'billing.paypal.secret' => null]);
        $this->assertSame('fake', $this->api($token)->getJson('/api/v1/app/billing')->json('data.payment_provider'));
    }
}
