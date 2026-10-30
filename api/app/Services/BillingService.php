<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Exceptions\ApiException;
use App\Models\Application;
use App\Models\BackupJob;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageRecord;
use App\Support\Tenant;

/** UBIL-001..004 and quota enforcement (UHOST-003). */
class BillingService
{
    public function __construct(private PaymentGateway $payments) {}

    public function subscription(): Subscription
    {
        $sub = Subscription::with('plan')->latest()->first();
        if (! $sub) {
            $sub = Subscription::create(['plan_id' => Plan::where('code', 'small')->value('id'), 'status' => 'active', 'current_period_start' => now()->startOfMonth(), 'current_period_end' => now()->endOfMonth()]);
            $sub->load('plan');
        }

        return $sub;
    }

    public function plan(): Plan
    {
        return $this->subscription()->plan;
    }

    /** A package limit (cron_jobs, mailboxes, …) with a default when the package does not set it. */
    public function limit(string $key, int $default): int
    {
        try {
            return (int) ($this->plan()->limits[$key] ?? $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    public function usage(): array
    {
        $apps = Application::where('kind', 'customer')->get();
        $sizes = Plan::all()->keyBy('code');
        $bandwidth = (float) UsageRecord::where('metric', 'bandwidth_gb')->where('period', '>=', now()->startOfMonth()->toDateString())->sum('quantity');

        return [
            'apps' => $apps->count(),
            'ram_mb' => (int) $apps->sum(fn ($a) => $sizes[$a->size]->ram_mb ?? 0),
            'storage_gb' => (int) $apps->sum(fn ($a) => $sizes[$a->size]->storage_gb ?? 0),
            'bandwidth_gb' => round($bandwidth, 2),
        ];
    }

    /** Throws 402 quota_exceeded when adding (or resizing to) a size would exceed the plan. */
    public function assertQuota(string $size, ?Application $replacing = null): void
    {
        $plan = $this->plan();
        $limits = $plan->limits;
        $usage = $this->usage();
        $new = Plan::where('code', $size)->firstOrFail();
        $old = $replacing ? Plan::where('code', $replacing->size)->first() : null;
        $after = [
            'apps' => $usage['apps'] + ($replacing ? 0 : 1),
            'ram_mb' => $usage['ram_mb'] - ($old->ram_mb ?? 0) + $new->ram_mb,
            'storage_gb' => $usage['storage_gb'] - ($old->storage_gb ?? 0) + $new->storage_gb,
        ];
        foreach (['apps' => 'apps', 'ram_mb' => 'memory', 'storage_gb' => 'storage'] as $key => $label) {
            if ($after[$key] > $limits[$key]) {
                $next = Plan::where('sort', '>', $plan->sort)->orderBy('sort')->first();
                throw ApiException::quota(
                    "This would exceed your {$plan->name} plan's {$label} limit ({$limits[$key]}). Upgrade your plan to continue.",
                    ['limit' => $key, 'current' => $usage[$key], 'requested' => $after[$key], 'max' => $limits[$key], 'upgrade_to' => $next?->code, 'upgrade_url' => '/app/billing'],
                );
            }
        }
    }

    public function assertBackupAllowance(): void
    {
        $limit = (int) ($this->plan()->limits['backups_per_day'] ?? 1);
        $today = BackupJob::where('trigger', 'manual')->whereNotNull('application_id')->where('created_at', '>=', now()->startOfDay())->count();
        if ($today >= $limit) {
            throw ApiException::quota("Your plan allows {$limit} on-demand backups per day. Upgrade for more.", ['limit' => 'backups_per_day', 'max' => $limit]);
        }
    }

    public function summary(): array
    {
        $org = Tenant::current();
        $plan = $this->plan();
        $usage = $this->usage();
        $days = now()->daysInMonth;
        $monthToDate = (int) round($plan->price_cents * now()->day / $days)
            + (int) Application::where('kind', 'customer')->get()->sum(fn ($a) => (Plan::where('code', $a->size)->value('price_cents') ?? 0) * now()->day / $days);

        return [
            'plan' => $this->planApi($plan),
            'limits' => $plan->limits,
            'usage' => $usage,
            'usage_pct' => collect(['apps', 'ram_mb', 'storage_gb', 'bandwidth_gb'])->mapWithKeys(fn ($k) => [$k => $plan->limits[$k] ? round($usage[$k] / $plan->limits[$k] * 100, 1) : 0]),
            'month_to_date' => ['amount_cents' => $monthToDate, 'currency' => $plan->currency],
            'payment_method' => $org->payment_method,
            'billing_email' => $org->billing_email,
            'payment_provider' => $this->payments->name(),
            'payment_portal_url' => rescue(fn () => $this->payments->portalUrl($org, config('billing.stripe.return_url')), null, report: false),
        ];
    }

    public function planApi(Plan $plan): array
    {
        return [
            'code' => $plan->code, 'name' => $plan->name, 'vcpu' => $plan->vcpu, 'ram_mb' => $plan->ram_mb, 'storage_gb' => $plan->storage_gb,
            'price_cents' => $plan->price_cents, 'currency' => $plan->currency, 'limits' => $plan->limits,
        ];
    }

    public function changePlan(string $code): Subscription
    {
        $plan = Plan::where('code', $code)->firstOrFail();
        $usage = $this->usage();
        foreach (['apps', 'ram_mb', 'storage_gb'] as $key) {
            if ($usage[$key] > $plan->limits[$key]) {
                throw ApiException::conflict("Your current usage ({$key}: {$usage[$key]}) is above the {$plan->name} plan limit. Remove apps first.");
            }
        }
        $org = Tenant::current();
        $providerId = $this->payments->subscribe($org, $plan);
        $sub = $this->subscription();
        $sub->update(['plan_id' => $plan->id, 'provider_subscription_id' => $providerId, 'status' => 'active']);

        return $sub->fresh('plan');
    }

    public function updatePaymentMethod(Organization $org, string $token): array
    {
        $summary = $this->payments->attachPaymentMethod($org, $token);
        $org->update(['payment_method' => $summary]);

        return $summary;
    }

    public function portalUrl(Organization $org): string
    {
        return $this->payments->portalUrl($org, config('billing.stripe.return_url'));
    }

    public function invoiceApi(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id, 'number' => $invoice->number,
            'period_start' => $invoice->period_start->toDateString(), 'period_end' => $invoice->period_end->toDateString(),
            'period' => $invoice->period_start->format('M Y'),
            'amount_cents' => $invoice->amount_cents, 'currency' => $invoice->currency, 'status' => $invoice->status,
            'subtotal_cents' => $invoice->subtotal_cents, 'tax_cents' => $invoice->tax_cents, 'tax_rate' => (float) $invoice->tax_rate,
            'line_items' => $invoice->line_items ?? [],
            'paid_at' => $invoice->paid_at?->toIso8601ZuluString(),
            // Signed, expiring link to the real PDF (UBIL-004); no bearer token needed to open it.
            'pdf_url' => app(InvoiceService::class)->signedPdfUrl($invoice),
            'created_at' => $invoice->created_at?->toIso8601ZuluString(),
        ];
    }
}
