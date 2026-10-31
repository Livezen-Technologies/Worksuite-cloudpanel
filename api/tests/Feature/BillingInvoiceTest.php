<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Invoice;
use App\Models\UsageRecord;
use App\Services\InvoiceService;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** UBIL-004: usage metering -> monthly invoice -> real PDF behind a signed URL. */
class BillingInvoiceTest extends TestCase
{
    public function test_monthly_invoice_rolls_up_usage_with_tax_and_renders_a_pdf(): void
    {
        Storage::fake('local');
        config(['billing.tax_rate' => 0.19]);
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'shop', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'size' => 'standard', 'server_id' => $server->id]));
        $this->api($token)->getJson('/api/v1/app/billing')->assertOk(); // creates the Small subscription

        $month = Carbon::parse('2026-09-01');
        app(Tenant::class)->set(null);
        $invoices = app(InvoiceService::class);
        foreach (range(1, 365) as $h) { // 365 h of a Standard app = half a month
            $invoices->meter($month->copy()->addHours($h));
        }
        $this->assertSame(365.0, (float) UsageRecord::allTenants()->where('application_id', $app->id)->sum('quantity'));

        $this->artisan('billing:invoice', ['--month' => '2026-09'])->assertSuccessful();
        $invoice = Invoice::allTenants()->firstOrFail();
        $this->assertSame('INV-202609-'.strtoupper(substr(str_replace('-', '', $org->id), 0, 8)), $invoice->number);
        $this->assertSame(['plan', 'app'], array_column($invoice->line_items, 'kind'));
        $this->assertSame(500, $invoice->line_items[0]['amount_cents']);
        $this->assertSame(600, $invoice->line_items[1]['amount_cents'], 'half of 12.00');
        $this->assertSame(1100, $invoice->subtotal_cents);
        $this->assertSame(209, $invoice->tax_cents);
        $this->assertSame(1309, $invoice->amount_cents);
        $this->artisan('billing:invoice', ['--month' => '2026-09'])->assertSuccessful();
        $this->assertSame(1, Invoice::allTenants()->count(), 'idempotent per month');

        $api = $this->api($token)->getJson('/api/v1/app/billing/invoices')->assertOk()->json('data.0');
        $this->assertSame('Sep 2026', $api['period']);
        $this->assertSame(209, $api['tax_cents']);
        $this->assertCount(2, $api['line_items']);
        $pdf = $this->withHeaders(['Authorization' => ''])->get($api['pdf_url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        Storage::disk('local')->assertExists($invoice->fresh()->pdf_path);
        $this->get('/api/v1/invoices/'.$invoice->id.'/pdf')->assertForbidden();
        $authed = $this->api($token)->get('/api/v1/app/billing/invoices/'.$invoice->id.'/pdf')->assertOk();
        $this->assertStringStartsWith('%PDF', $authed->getContent());
    }

    public function test_fake_driver_marks_invoice_paid_when_a_card_is_on_file(): void
    {
        Storage::fake('local');
        [, $org, $token] = $this->org();
        $this->api($token)->postJson('/api/v1/app/billing/payment-method', ['payment_method_token' => 'pm_card_visa'])->assertOk()->assertJsonPath('data.payment_provider', 'fake');
        app(Tenant::class)->set(null);
        $this->artisan('billing:invoice', ['--org' => $org->id])->assertSuccessful();
        $this->assertSame('paid', Invoice::allTenants()->first()->status);
    }

    public function test_extra_copies_are_billed_by_the_hour_at_the_apps_size(): void
    {
        Storage::fake('local');
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'shop', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'size' => 'standard', 'server_id' => $server->id, 'replicas' => 3]));
        $this->api($token)->getJson('/api/v1/app/billing')->assertOk();

        $month = Carbon::parse('2026-09-01');
        app(Tenant::class)->set(null);
        $invoices = app(InvoiceService::class);
        foreach (range(1, 73) as $h) { // 2 extra copies x 73 h = 146 copy-hours = a fifth of a Standard month
            $invoices->meter($month->copy()->addHours($h));
        }
        $this->assertSame(146.0, (float) UsageRecord::allTenants()->where('metric', 'replica_hours.standard')->sum('quantity'));

        $this->artisan('billing:invoice', ['--month' => '2026-09'])->assertSuccessful();
        $lines = collect(Invoice::allTenants()->firstOrFail()->line_items)->keyBy('kind');
        $this->assertSame(['Extra copies of shop (Standard)', 146.0, 'copy-hours', 240], [$lines['copies']['description'], (float) $lines['copies']['quantity'], $lines['copies']['unit'], $lines['copies']['amount_cents']]);
        $this->assertSame(120, $lines['app']['amount_cents'], 'the first copy stays on the app line');
    }
}
