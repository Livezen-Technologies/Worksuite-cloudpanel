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
            // SCL-10: copies beyond the first are billed by the hour at the app's size.
            if (($extra = (int) $app->replicas - 1) > 0) {
                UsageRecord::allTenants()->create([
                    'organization_id' => $app->organization_id, 'application_id' => $app->id,
                    'metric' => 'replica_hours.'.$app->size, 'quantity' => $extra, 'period' => $at->toDateString(),
                ]);
            }
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
        $copies = UsageRecord::where('metric', 'like', 'replica_hours.%')->whereBetween('period', [$start->toDateString(), $end->toDateString()])
            ->select('application_id', 'metric', DB::raw('SUM(quantity) as hours'))->groupBy('application_id', 'metric')->get();
        $names = $names->union(Application::withTrashed()->whereIn('id', $copies->pluck('application_id'))->pluck('name', 'id'));
        foreach ($copies as $row) {
            $size = substr($row->metric, strlen('replica_hours.'));
            if (! ($plan = $plans[$size] ?? null)) {
                continue;
            }
            $hours = (float) $row->hours;
            $lines[] = ['description' => 'Extra copies of '.($names[$row->application_id] ?? $row->application_id)." ({$plan->name})",
                'quantity' => round($hours, 2), 'unit' => 'copy-hours', 'unit_cents' => round($plan->price_cents / $hoursPerMonth, 4),
                'amount_cents' => (int) round($plan->price_cents * $hours / $hoursPerMonth), 'kind' => 'copies', 'application_id' => $row->application_id, 'size' => $size];
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
        $charge = $this->attempt($org, $invoice);
        $this->render($invoice);
        $invoice = $invoice->fresh();
        $emails = app(EmailTemplateService::class);
        $amount = number_format($invoice->amount_cents / 100, 2).' '.strtoupper((string) $invoice->currency);
        $panel = rtrim((string) (config('platform.panel_url') ?: config('app.url')), '/');
        $emails->notifyOrganization($org->id, 'invoice', [
            'number' => $invoice->number, 'amount' => $amount, 'period' => $start->format('F Y'),
            'invoice_url' => $panel.'/app/billing',
        ]);
        if (in_array($invoice->status, ['failed', 'uncollectible', 'past_due'], true)) {
            $this->notifyFailed($org, $invoice, $charge);
        }

        return $invoice;
    }

    /** Automatic collection attempts before an invoice is left to the customer, and the gap between them. */
    public const MAX_ATTEMPTS = 3;

    public const RETRY_DAYS = 3;

    /**
     * One collection attempt. A failure is retried RETRY_DAYS later, up to MAX_ATTEMPTS, for gateways
     * the panel charges itself; Stripe retries its own invoices and reports back by webhook.
     */
    private function attempt(Organization $org, Invoice $invoice): array
    {
        $gateway = app(\App\Support\Payments::class)->for($org);
        $invoice->forceFill(['collection_attempts' => $invoice->collection_attempts + 1])->saveQuietly();
        $charge = $gateway->collect($org, $invoice);
        $status = $charge['status'] ?? 'open';
        $retry = $status === 'failed' && $gateway->name() !== 'stripe' && $invoice->collection_attempts < self::MAX_ATTEMPTS;
        $invoice->update(array_filter(['status' => $status, 'provider_invoice_id' => $charge['provider_invoice_id'] ?? null,
            'paid_at' => $status === 'paid' ? now() : null]) + ['next_attempt_at' => $retry ? now()->addDays(self::RETRY_DAYS) : null]);
        if ($status === 'failed' && ! $retry) {
            // No more automatic attempts: the account is past due until the customer pays.
            \App\Models\Subscription::allTenants()->where('organization_id', $org->id)->whereIn('status', ['active'])->update(['status' => 'past_due']);
        }

        return $charge;
    }

    private function notifyFailed(Organization $org, Invoice $invoice, array $charge): void
    {
        $next = $invoice->next_attempt_at;
        app(EmailTemplateService::class)->notifyOrganization($org->id, 'payment_failed', [
            'amount' => self::money($invoice->amount_cents, $invoice->currency), 'reason' => (string) ($charge['error'] ?? 'The payment was declined.'),
            'retry_date' => $next ? $next->toFormattedDateString() : 'no further automatic attempt',
            'next_step' => $next ? 'We will try again on '.$next->toFormattedDateString().'.' : 'That was the last automatic attempt. Pay from your billing page to keep your sites running.',
        ]);
    }

    /** Daily: retry failed invoices whose next attempt is due. Returns [retried, paid]. */
    public function retryDue(): array
    {
        $retried = $paid = 0;
        foreach (Invoice::allTenants()->where('status', 'failed')->whereNotNull('next_attempt_at')->where('next_attempt_at', '<=', now())->get() as $invoice) {
            $org = Organization::find($invoice->organization_id);
            if (! $org) {
                continue;
            }
            app(\App\Support\Tenant::class)->as($org->id, function () use ($org, $invoice, &$retried, &$paid) {
                $charge = $this->attempt($org, $invoice);
                $retried++;
                $invoice->refresh();
                if ($invoice->status === 'paid') {
                    $paid++;
                    \App\Models\Subscription::where('status', 'past_due')->update(['status' => 'active']);
                } elseif ($invoice->status === 'failed') {
                    $this->notifyFailed($org, $invoice, $charge);
                }
            });
        }

        return [$retried, $paid];
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

    /**
     * Refund part or all of a paid invoice through the provider that collected it, and issue a
     * numbered credit note (its own PDF) for the amount. The customer is emailed; it is audited.
     */
    public function refund(Invoice $invoice, ?int $amountCents, string $reason, ?string $userId = null): Invoice
    {
        if (($invoice->kind ?? 'invoice') !== 'invoice' || $invoice->status !== 'paid') {
            throw \App\Exceptions\ApiException::conflict('Only a paid invoice can be refunded.');
        }
        $left = $invoice->amount_cents - (int) $invoice->refunded_cents;
        $amount = $amountCents ?? $left;
        if ($amount < 1 || $amount > $left) {
            throw \App\Exceptions\ApiException::validation(['amount_cents' => ['Refund between 0.01 and '.self::money($left, $invoice->currency).'.']]);
        }
        $org = Organization::findOrFail($invoice->organization_id);
        $payments = app(\App\Support\Payments::class);
        $gateway = str_starts_with((string) $invoice->provider_invoice_id, 'in_') && in_array('stripe', $payments->available(), true)
            ? $payments->make('stripe') : $payments->for($org);
        $refundId = $gateway->refund($org, $invoice, $amount, $reason);   // throws before anything is recorded

        $rate = (float) $invoice->tax_rate;
        $tax = $rate > 0 ? (int) round($amount * $rate / (1 + $rate)) : 0;
        $n = Invoice::where('credit_for_id', $invoice->id)->count() + 1;
        $credit = Invoice::create([
            'number' => 'CN-'.$invoice->number.($n > 1 ? '-'.$n : ''), 'kind' => 'credit_note', 'credit_for_id' => $invoice->id,
            'period_start' => $invoice->period_start, 'period_end' => $invoice->period_end, 'currency' => $invoice->currency,
            'line_items' => [['kind' => 'refund', 'description' => "Refund of {$invoice->number}: {$reason}", 'quantity' => 1, 'unit' => '', 'unit_cents' => -($amount - $tax), 'amount_cents' => -($amount - $tax)]],
            'subtotal_cents' => -($amount - $tax), 'tax_rate' => $rate, 'tax_cents' => -$tax, 'amount_cents' => -$amount,
            'status' => 'refunded', 'paid_at' => now(), 'provider_invoice_id' => $refundId,
        ]);
        $this->render($credit);
        $invoice->update(['refunded_cents' => $invoice->refunded_cents + $amount, 'status' => $invoice->refunded_cents + $amount >= $invoice->amount_cents ? 'refunded' : 'paid']);
        app(\App\Services\AuditLogger::class)->log('billing.refund', 'invoices', $invoice->id, ['credit_note' => $credit->number, 'amount_cents' => $amount, 'reason' => $reason], $org->id, $userId);
        app(EmailTemplateService::class)->notifyOrganization($org->id, 'refund_issued', [
            'number' => $invoice->number, 'credit_note' => $credit->number, 'amount' => self::money($amount, $invoice->currency), 'reason' => $reason,
        ]);

        return $credit->fresh();
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
