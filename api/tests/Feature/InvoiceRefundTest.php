<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Support\Tenant;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** BIL-REF: staff refund a paid invoice; a credit note is issued, the customer is told. */
class InvoiceRefundTest extends TestCase
{
    public function test_partial_then_full_refund_issue_credit_notes_and_guard_the_amount(): void
    {
        Storage::fake('local');
        [, , $staff] = $this->org('super_admin');
        [, $org, $customer] = $this->org('owner', 'Acme');
        $mail = $this->spy(\App\Services\EmailTemplateService::class);
        $inv = app(Tenant::class)->as($org->id, fn () => Invoice::create(['number' => 'INV-202610-ACME', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
            'amount_cents' => 1190, 'subtotal_cents' => 1000, 'tax_cents' => 190, 'tax_rate' => 0.19, 'currency' => 'EUR', 'status' => 'paid', 'line_items' => []]));
        app(Tenant::class)->set(null);
        $url = "/api/v1/customers/{$org->id}/invoices/{$inv->id}/refund";

        $this->api($staff)->postJson($url, ['amount_cents' => 2000, 'reason' => 'Outage credit'])->assertStatus(422);
        $this->api($staff)->postJson($url, ['amount_cents' => 1190])->assertStatus(422);   // a reason is required
        $cn = $this->api($staff)->postJson($url, ['amount_cents' => 595, 'reason' => 'Outage on 12 October'])->assertCreated()->json('data');
        $this->assertSame(['CN-INV-202610-ACME', 'credit_note', -595, $inv->id], [$cn['number'], $cn['kind'], $cn['amount_cents'], $cn['credit_for_id']]);
        $this->assertSame(-95, Invoice::allTenants()->where('number', 'CN-INV-202610-ACME')->value('tax_cents'), 'tax refunded in proportion');
        $this->assertSame(['paid', 595], [$inv->fresh()->status, $inv->fresh()->refunded_cents]);
        Storage::disk('local')->assertExists('invoices/'.$org->id.'/CN-INV-202610-ACME.pdf');

        // The rest; then nothing is left to refund.
        $this->api($staff)->postJson($url, ['reason' => 'Customer left'])->assertCreated()->assertJsonPath('data.number', 'CN-INV-202610-ACME-2')->assertJsonPath('data.amount_cents', -595);
        $this->assertSame('refunded', $inv->fresh()->status);
        $this->api($staff)->postJson($url, ['reason' => 'again'])->assertStatus(409);

        $mail->shouldHaveReceived('notifyOrganization')->withArgs(fn ($o, $k, $v) => $o === $org->id && $k === 'refund_issued' && $v['amount'] === '€5.95')->twice();
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.refund', 'resource_id' => $inv->id]);
        // The customer sees the credit notes with their invoices; customers cannot refund.
        $kinds = collect($this->api($customer)->getJson('/api/v1/app/billing/invoices')->assertOk()->json('data'))->pluck('kind')->all();
        $this->assertEqualsCanonicalizing(['invoice', 'credit_note', 'credit_note'], $kinds);
        $this->api($customer)->postJson($url, ['reason' => 'me'])->assertForbidden();
    }
}
