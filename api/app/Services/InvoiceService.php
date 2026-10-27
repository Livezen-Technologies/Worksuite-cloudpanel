<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Models\Application;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageRecord;
use App\Support\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/** UBIL-004: usage metering, monthly invoices rolled up from usage_records, and PDF rendering. */
class InvoiceService
{
    public function __construct(private PaymentGateway $payments) {}

    /** Hourly: one app_hours.<size> record per running customer app (UBIL-002 / invoicing input). */
    public function meter(?Carbon $at = null): int
    {
        $at ??= now();
        $count = 0;
        foreach (Application::allTenants()->where('kind', 'customer')->whereNotNull('server_id')->get() as $app) {
            UsageRecord::allTenants()->create([
                'organization_id' => $app->organization_id, 'application_id' => $app->id,
                'metric' => 'app_hours.'.$app->size, 'quantity' => 1, 'period' => $at->toDateString(),
            ]);
            $count++;
        }

        return $count;
    }

    /** Builds (once) the invoice of $month for the current organisation. */
    public function generate(Carbon $month): ?Invoice
    {
        $org = Tenant::current();
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $number = config('billing.invoice_prefix').'-'.$start->format('Ym').'-'.strtoupper(substr(str_replace('-', '', $org->id), 0, 8));
        if ($existing = Invoice::where('number', $number)->first()) {
            return $existing;
        }
        $currency = config('billing.currency');
        $lines = [];
        $sub = Subscription::with('plan')->latest()->first();
        if ($sub?->plan) {
            $lines[] = ['description' => "{$sub->plan->name} plan, ".$start->format('M Y'), 'quantity' => 1, 'unit' => 'month',
                'unit_cents' => $sub->plan->price_cents, 'amount_cents' => $sub->plan->price_cents, 'kind' => 'plan'];
            $currency = $sub->plan->currency ?: $currency;
        }
        $hoursPerMonth = (int) config('billing.hours_per_month');
        $plans = Plan::all()->keyBy('code');
        $usage = UsageRecord::where('metric', 'like', 'app_hours.%')->whereBetween('period', [$start->toDateString(), $end->toDateString()])
            ->select('application_id', 'metric', DB::raw('SUM(quantity) as hours'))->groupBy('application_id', 'metric')->get();
        $names = Application::withTrashed()->whereIn('id', $usage->pluck('application_id'))->pluck('name', 'id');
        foreach ($usage as $row) {
            $size = substr($row->metric, strlen('app_hours.'));
            $plan = $plans[$size] ?? null;
            if (! $plan) {
                continue;
            }
            $hours = (float) $row->hours;
            $amount = (int) min($plan->price_cents, round($plan->price_cents * $hours / $hoursPerMonth));
            $lines[] = ['description' => 'App '.($names[$row->application_id] ?? $row->application_id)." ({$plan->name}, {$plan->vcpu} vCPU / {$plan->ram_mb} MB)",
                'quantity' => round($hours, 2), 'unit' => 'hours', 'unit_cents' => round($plan->price_cents / $hoursPerMonth, 4),
                'amount_cents' => $amount, 'kind' => 'app', 'application_id' => $row->application_id, 'size' => $size];
        }
        if (! $lines) {
            return null;
        }
        $subtotal = (int) array_sum(array_column($lines, 'amount_cents'));
        $rate = (float) config('billing.tax_rate');
        $tax = (int) round($subtotal * $rate);
        $invoice = Invoice::create([
            'number' => $number, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(),
            'line_items' => $lines, 'subtotal_cents' => $subtotal, 'tax_rate' => $rate, 'tax_cents' => $tax,
            'amount_cents' => $subtotal + $tax, 'currency' => $currency, 'status' => 'open', 'due_at' => now()->addDays(14),
        ]);
        // The payment provider collects it (fake: paid at once; Stripe: invoice items, paid via webhook).
        $charge = $this->payments->collect($org, $invoice);
        $invoice->update(array_filter(['status' => $charge['status'] ?? 'open', 'provider_invoice_id' => $charge['provider_invoice_id'] ?? null,
            'paid_at' => ($charge['status'] ?? null) === 'paid' ? now() : null]));
        $this->render($invoice);

        return $invoice->fresh();
    }

    /** Monthly run over every organisation that had a subscription or usage. */
    public function generateAll(Carbon $month): int
    {
        $count = 0;
        foreach (Organization::all() as $org) {
            app(Tenant::class)->as($org->id, function () use ($month, &$count) {
                if ($this->generate($month)) {
                    $count++;
                }
            });
        }

        return $count;
    }

    public function render(Invoice $invoice): string
    {
        $org = Organization::find($invoice->organization_id);
        $pdf = Pdf::loadView('invoices.pdf', [
            'invoice' => $invoice, 'org' => $org, 'seller' => config('billing.seller'),
            'taxLabel' => config('billing.tax_label'), 'money' => fn (int|float $cents) => self::money($cents, $invoice->currency),
        ])->setPaper('a4')->output();
        $path = 'invoices/'.$invoice->organization_id.'/'.$invoice->number.'.pdf';
        Storage::disk(config('billing.disk'))->put($path, $pdf);
        $invoice->forceFill(['pdf_path' => $path])->saveQuietly();

        return $pdf;
    }

    public function pdf(Invoice $invoice): string
    {
        $disk = Storage::disk(config('billing.disk'));

        return $invoice->pdf_path && $disk->exists($invoice->pdf_path) ? $disk->get($invoice->pdf_path) : $this->render($invoice);
    }

    public function signedPdfUrl(Invoice $invoice): string
    {
        return URL::temporarySignedRoute('invoice.pdf', now()->addMinutes(config('billing.pdf_ttl_minutes')), ['id' => $invoice->id]);
    }

    public static function money(int|float $cents, string $currency): string
    {
        $symbol = ['EUR' => '€', 'USD' => '$', 'GBP' => '£'][strtoupper($currency)] ?? strtoupper($currency).' ';

        return $symbol.number_format($cents / 100, 2, '.', ',');
    }
}
