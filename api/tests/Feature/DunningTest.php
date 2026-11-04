<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Drivers\FakePaymentGateway;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** A failed payment is retried 3 days later, up to 3 attempts; then the account is past due. */
class DunningTest extends TestCase
{
    private function gateway(array $results): object
    {
        $g = new class extends FakePaymentGateway {
            public array $results = [];

            public function collect(Organization $organization, Invoice $invoice): array
            {
                return array_shift($this->results) ?? ['status' => 'failed', 'error' => 'Card declined'];
            }
        };
        $g->results = $results;
        $this->app->instance(PaymentGateway::class, $g);

        return $g;
    }

    private function invoice(string $orgId): Invoice
    {
        return app(Tenant::class)->as($orgId, fn () => Invoice::create(['number' => 'INV-D-'.substr($orgId, 0, 6), 'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
            'amount_cents' => 1190, 'currency' => 'EUR', 'status' => 'open', 'line_items' => []]));
    }

    private function collect(Invoice $invoice): void
    {
        $m = new \ReflectionMethod(\App\Services\InvoiceService::class, 'attempt');
        $m->invoke(app(\App\Services\InvoiceService::class), Organization::find($invoice->organization_id), $invoice);
    }

    public function test_a_failed_payment_is_retried_and_a_later_success_clears_it(): void
    {
        Storage::fake('local');
        Carbon::setTestNow('2026-11-01 02:15:00');
        [, $org, $token] = $this->org();
        $this->api($token)->getJson('/api/v1/app/billing')->assertOk();   // creates the subscription
        $this->gateway([['status' => 'failed', 'error' => 'Insufficient funds'], ['status' => 'failed', 'error' => 'Insufficient funds'], ['status' => 'paid']]);
        $mail = $this->spy(\App\Services\EmailTemplateService::class);
        $inv = $this->invoice($org->id);
        app(Tenant::class)->as($org->id, fn () => $this->collect($inv));
        $this->assertSame(['failed', 1, '2026-11-04'], [$inv->fresh()->status, $inv->fresh()->collection_attempts, $inv->fresh()->next_attempt_at->toDateString()]);

        Carbon::setTestNow('2026-11-02 09:10:00');
        $this->artisan('billing:dunning')->expectsOutput('Failed invoices retried: 0, now paid: 0');   // not due yet
        Carbon::setTestNow('2026-11-04 09:10:00');
        $this->artisan('billing:dunning')->expectsOutput('Failed invoices retried: 1, now paid: 0');
        $mail->shouldHaveReceived('notifyOrganization')->withArgs(fn ($o, $k, $v) => $k === 'payment_failed' && $v['next_step'] === 'We will try again on Nov 7, 2026.')->once();
        Carbon::setTestNow('2026-11-07 09:10:00');
        $this->artisan('billing:dunning')->expectsOutput('Failed invoices retried: 1, now paid: 1');
        $this->assertSame(['paid', 3, null], [$inv->fresh()->status, $inv->fresh()->collection_attempts, $inv->fresh()->next_attempt_at]);
        Carbon::setTestNow();
    }

    public function test_after_the_last_attempt_the_account_is_past_due_and_the_email_says_so(): void
    {
        Storage::fake('local');
        Carbon::setTestNow('2026-11-01 02:15:00');
        [, $org, $token] = $this->org();
        $this->api($token)->getJson('/api/v1/app/billing')->assertOk();
        $this->gateway([]);   // always declines
        $mail = $this->spy(\App\Services\EmailTemplateService::class);
        $inv = $this->invoice($org->id);
        app(Tenant::class)->as($org->id, fn () => $this->collect($inv));
        foreach (['2026-11-04', '2026-11-07'] as $day) {
            Carbon::setTestNow($day.' 09:10:00');
            $this->artisan('billing:dunning')->assertSuccessful();
        }
        $this->assertSame([3, null], [$inv->fresh()->collection_attempts, $inv->fresh()->next_attempt_at]);
        $this->assertSame('past_due', Subscription::allTenants()->where('organization_id', $org->id)->value('status'));
        $mail->shouldHaveReceived('notifyOrganization')->withArgs(fn ($o, $k, $v) => $k === 'payment_failed' && str_starts_with($v['next_step'], 'That was the last automatic attempt'))->once();
        Carbon::setTestNow('2026-11-20 09:10:00');
        $this->artisan('billing:dunning')->expectsOutput('Failed invoices retried: 0, now paid: 0');
        Carbon::setTestNow();
    }
}
