<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\BillingProfile;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\RefundRequest;
use App\Models\Subscription;
use App\Support\Payments as Providers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The money rules, in one place (FIN-2..6).
 *
 * Every figure the finance screens show is derived here rather than in a
 * controller, because the same arithmetic is asked for by the overview, the
 * reports and the customer's own billing page, and three copies of it would
 * drift. Amounts are integer cents throughout; nothing is ever held as a
 * float, so a total is exact however it was reached.
 *
 * Reads deliberately cross tenants (`allTenants()`): these are the platform
 * administrator's views of every customer. The customer-facing equivalents
 * live in BillingService, which is scoped.
 */
class FinanceService
{
    public function __construct(private Providers $providers) {}

    /** The finance section of the platform settings, defaults filled in. */
    public function settings(): array
    {
        $org = Organization::query()->orderBy('created_at')->first();

        return array_replace(
            config('platform.default_settings.finance'),
            is_array($org?->settings['finance'] ?? null) ? $org->settings['finance'] : [],
        );
    }

    public function currency(): string
    {
        return strtoupper((string) ($this->settings()['currency'] ?? 'EUR'));
    }

    /* ---------------------------------------------------------------- KPIs */

    /**
     * The overview figures, over a window.
     *
     * MRR is the committed monthly value of everything active right now, not
     * what happened to be invoiced this month: an annual plan contributes a
     * twelfth. Taking it from invoices instead would make MRR jump every time
     * an annual customer renewed, which is the one thing MRR exists not to do.
     */
    public function kpis(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $paid = Payment::allTenants()->where('status', 'succeeded')->whereBetween('paid_at', [$from, $to]);
        $revenueCents = (int) (clone $paid)->sum('amount_cents');

        $active = Subscription::allTenants()->where('status', 'active')->with('plan')->get();
        $mrrCents = $active->sum(fn (Subscription $s) => $this->monthlyValueCents($s));

        $invoices = Invoice::allTenants()->where('kind', 'invoice');
        $outstanding = (clone $invoices)->whereIn('status', ['open', 'unpaid', 'partially_paid']);
        $overdue = (clone $outstanding)->whereNotNull('due_at')->where('due_at', '<', now());

        $customers = Subscription::allTenants()->whereIn('status', ['active', 'trialing'])->distinct()->count('organization_id');
        $refunds = RefundRequest::allTenants();

        $payments = Payment::allTenants()->whereBetween('created_at', [$from, $to]);
        $succeeded = (clone $payments)->where('status', 'succeeded')->count();
        $failed = (clone $payments)->where('status', 'failed')->count();

        return [
            'currency' => $this->currency(),
            'window' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'total_revenue_cents' => $revenueCents,
            'mrr_cents' => $mrrCents,
            'arr_cents' => $mrrCents * 12,
            'active_subscriptions' => $active->count(),
            'trial_subscriptions' => Subscription::allTenants()->where('status', 'trialing')->count(),
            'expired_subscriptions' => Subscription::allTenants()->whereIn('status', ['expired', 'cancelled'])->count(),
            'outstanding_cents' => (int) (clone $outstanding)->sum('amount_cents'),
            'outstanding_invoices' => (clone $outstanding)->count(),
            'overdue_cents' => (int) (clone $overdue)->sum('amount_cents'),
            'overdue_invoices' => (clone $overdue)->count(),
            'successful_payments' => $succeeded,
            'failed_payments' => $failed,
            'payment_success_rate' => $succeeded + $failed > 0 ? round($succeeded / ($succeeded + $failed) * 100, 1) : null,
            'pending_refunds' => (clone $refunds)->where('status', 'pending')->count(),
            'refunded_cents' => (int) (clone $refunds)->where('status', 'refunded')->sum('amount_cents'),
            'customers' => $customers,
            'arpc_cents' => $customers > 0 ? intdiv($mrrCents, $customers) : 0,
            'churn_rate' => $this->churnRate($from, $to),
        ];
    }

    /** What a subscription is worth per month, whatever its billing cycle. */
    public function monthlyValueCents(Subscription $subscription): int
    {
        $price = (int) ($subscription->plan?->price_cents ?? 0);
        $discount = (int) ($subscription->discount_percent ?? 0);
        $net = (int) round($price * (100 - min(100, max(0, $discount))) / 100);

        return ($subscription->billing_cycle ?? 'monthly') === 'yearly' ? intdiv($net, 12) : $net;
    }

    /**
     * Subscriptions lost over the window as a share of those there to lose.
     *
     * Null rather than zero when nothing was active at the start: a rate with
     * no denominator is unknown, and showing 0% would read as "we lost none".
     */
    public function churnRate(CarbonImmutable $from, CarbonImmutable $to): ?float
    {
        $atStart = Subscription::allTenants()->where('created_at', '<', $from)
            ->where(fn ($q) => $q->whereNull('cancelled_at')->orWhere('cancelled_at', '>=', $from))->count();
        if ($atStart === 0) {
            return null;
        }
        $lost = Subscription::allTenants()->whereBetween('cancelled_at', [$from, $to])->count();

        return round($lost / $atStart * 100, 2);
    }

    /** Revenue, refunds and payment counts by calendar month, oldest first. */
    public function monthlySeries(int $months = 12): array
    {
        $start = CarbonImmutable::now()->startOfMonth()->subMonths($months - 1);
        $rows = [];
        for ($i = 0; $i < $months; $i++) {
            $m = $start->addMonths($i);
            $end = $m->endOfMonth();
            $rows[] = [
                'month' => $m->format('Y-m'),
                'revenue_cents' => (int) Payment::allTenants()->where('status', 'succeeded')
                    ->whereBetween('paid_at', [$m, $end])->sum('amount_cents'),
                'refund_cents' => (int) RefundRequest::allTenants()->where('status', 'refunded')
                    ->whereBetween('refunded_at', [$m, $end])->sum('amount_cents'),
                'payments' => Payment::allTenants()->whereBetween('created_at', [$m, $end])->count(),
                'new_subscriptions' => Subscription::allTenants()->whereBetween('created_at', [$m, $end])->count(),
            ];
        }

        return $rows;
    }

    /** Revenue split by gateway, and by plan, over a window. */
    public function breakdowns(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byGateway = Payment::allTenants()->where('status', 'succeeded')->whereBetween('paid_at', [$from, $to])
            ->selectRaw('gateway, COUNT(*) AS n, SUM(amount_cents) AS cents')
            ->groupBy('gateway')->get()
            ->map(fn ($r) => ['gateway' => $r->gateway, 'payments' => (int) $r->n, 'amount_cents' => (int) $r->cents])
            ->sortByDesc('amount_cents')->values()->all();

        $byPlan = Subscription::allTenants()->where('status', 'active')
            ->selectRaw('plan_id, COUNT(*) AS n')->groupBy('plan_id')->get()
            ->map(function ($r) {
                $plan = Plan::find($r->plan_id);

                return ['plan' => $plan?->name ?? 'Unknown', 'code' => $plan?->code, 'subscriptions' => (int) $r->n];
            })->sortByDesc('subscriptions')->values()->all();

        return ['by_gateway' => $byGateway, 'by_plan' => $byPlan];
    }

    /* ------------------------------------------------------------ payments */

    /**
     * Record money that arrived.
     *
     * A gateway reference is the duplicate guard: the same charge delivered
     * twice (a retried webhook, a double click) returns the payment already
     * recorded instead of taking the money twice in the ledger.
     */
    public function recordPayment(Organization $org, array $input, ?string $userId = null): Payment
    {
        $invoice = isset($input['invoice_id'])
            ? Invoice::allTenants()->where('organization_id', $org->id)->findOrFail($input['invoice_id'])
            : null;

        $reference = $input['gateway_reference'] ?? null;
        $gateway = $input['gateway'] ?? 'manual';
        if ($reference) {
            $existing = Payment::allTenants()->where('gateway', $gateway)->where('gateway_reference', $reference)->first();
            if ($existing) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($org, $input, $invoice, $gateway, $reference, $userId) {
            $payment = Payment::create([
                'organization_id' => $org->id,
                'invoice_id' => $invoice?->id,
                'subscription_id' => $input['subscription_id'] ?? null,
                'amount_cents' => (int) $input['amount_cents'],
                'currency' => strtoupper($input['currency'] ?? $invoice?->currency ?? $this->currency()),
                'gateway' => $gateway,
                'method' => $input['method'] ?? 'bank_transfer',
                'status' => $input['status'] ?? 'succeeded',
                'gateway_reference' => $reference,
                'notes' => $input['notes'] ?? null,
                'recorded_by' => $userId,
                'paid_at' => ($input['status'] ?? 'succeeded') === 'succeeded'
                    ? ($input['paid_at'] ?? now())
                    : null,
            ]);

            if ($invoice && $payment->status === 'succeeded') {
                $this->settleInvoice($invoice);
            }
            $this->audit($org, 'payment.recorded', $payment->id, [
                'amount_cents' => $payment->amount_cents, 'gateway' => $payment->gateway,
            ], $userId);

            return $payment;
        });
    }

    /**
     * Bring an invoice's status in line with what has been paid against it.
     *
     * Called after anything that moves money, so `paid`/`partially_paid` is
     * always a consequence of the payment rows rather than something a caller
     * has to remember to set.
     */
    public function settleInvoice(Invoice $invoice): Invoice
    {
        $paid = (int) Payment::allTenants()->where('invoice_id', $invoice->id)
            ->where('status', 'succeeded')->sum('amount_cents');
        $net = (int) $invoice->amount_cents - (int) ($invoice->refunded_cents ?? 0);

        if ($paid <= 0) {
            $status = $invoice->due_at && $invoice->due_at->isPast() ? 'overdue' : 'open';
        } elseif ($paid >= $net) {
            $status = 'paid';
        } else {
            $status = 'partially_paid';
        }

        $invoice->update([
            'status' => in_array($invoice->status, ['void', 'draft'], true) ? $invoice->status : $status,
            'paid_at' => $status === 'paid' ? ($invoice->paid_at ?? now()) : null,
        ]);

        return $invoice->fresh();
    }

    /* ------------------------------------------------------------- refunds */

    /** What may still be given back on an invoice, after refunds already made. */
    public function refundableCents(Invoice $invoice): int
    {
        $paid = (int) Payment::allTenants()->where('invoice_id', $invoice->id)
            ->where('status', 'succeeded')->sum('amount_cents');
        $open = (int) RefundRequest::allTenants()->where('invoice_id', $invoice->id)
            ->whereIn('status', RefundRequest::OPEN)->sum('amount_cents');

        return max(0, $paid - (int) ($invoice->refunded_cents ?? 0) - $open);
    }

    /**
     * Ask for money back. Refuses more than is left, counting requests that
     * are open but not yet paid out, so two pending requests cannot together
     * exceed the payment they both point at.
     */
    public function requestRefund(Organization $org, array $input, ?string $userId = null): RefundRequest
    {
        $invoice = isset($input['invoice_id'])
            ? Invoice::allTenants()->where('organization_id', $org->id)->findOrFail($input['invoice_id'])
            : null;
        $payment = isset($input['payment_id'])
            ? Payment::allTenants()->where('organization_id', $org->id)->findOrFail($input['payment_id'])
            : null;

        $amount = (int) $input['amount_cents'];
        if ($amount <= 0) {
            throw ApiException::validation(['amount_cents' => ['A refund has to be for more than nothing.']]);
        }
        $ceiling = $invoice ? $this->refundableCents($invoice) : ($payment?->refundableCents() ?? 0);
        if ($amount > $ceiling) {
            throw ApiException::validation(['amount_cents' => [
                $ceiling === 0
                    ? 'There is nothing left to refund here.'
                    : 'That is more than the '.number_format($ceiling / 100, 2).' still refundable.',
            ]]);
        }

        $request = RefundRequest::create([
            'organization_id' => $org->id,
            'invoice_id' => $invoice?->id,
            'payment_id' => $payment?->id,
            'amount_cents' => $amount,
            'currency' => strtoupper($input['currency'] ?? $invoice?->currency ?? $payment?->currency ?? $this->currency()),
            'reason' => $input['reason'],
            'status' => 'pending',
            'requested_by' => $userId,
        ]);
        $this->audit($org, 'refund.requested', $request->id, ['amount_cents' => $amount], $userId);

        return $request;
    }

    /** Turn down a request, with the reason kept for the customer and the audit log. */
    public function rejectRefund(RefundRequest $request, string $notes, ?string $userId = null): RefundRequest
    {
        $this->assertOpen($request);
        $request->update([
            'status' => 'rejected', 'admin_notes' => $notes,
            'decided_by' => $userId, 'decided_at' => now(),
        ]);
        $this->audit($request->organization, 'refund.rejected', $request->id, ['notes' => $notes], $userId);

        return $request->fresh();
    }

    /**
     * Approve and pay out, through the gateway that took the money.
     *
     * The credit note is written only once the gateway has agreed, so a
     * failed refund leaves the books as they were and the request in `failed`
     * with the gateway's own message, rather than a credit note for money
     * that never moved.
     */
    public function approveRefund(RefundRequest $request, ?string $notes, ?string $userId = null): RefundRequest
    {
        $this->assertOpen($request);
        $invoice = $request->invoice_id ? Invoice::allTenants()->find($request->invoice_id) : null;
        $request->update(['status' => 'processing', 'admin_notes' => $notes, 'decided_by' => $userId, 'decided_at' => now()]);

        try {
            $creditNote = null;
            $reference = null;
            if ($invoice) {
                $creditNote = app(InvoiceService::class)->refund($invoice, $request->amount_cents, $request->reason, $userId);
                $reference = $creditNote->provider_invoice_id;
            } elseif ($request->payment_id) {
                $payment = Payment::allTenants()->find($request->payment_id);
                $org = $request->organization;
                if ($payment && $org) {
                    $reference = $this->providers->for($org)->refund($org, new Invoice([
                        'provider_invoice_id' => $payment->gateway_reference,
                        'currency' => $payment->currency,
                    ]), $request->amount_cents, $request->reason);
                }
            }

            if ($request->payment_id) {
                $payment = Payment::allTenants()->find($request->payment_id);
                if ($payment) {
                    $refunded = (int) $payment->refunded_cents + $request->amount_cents;
                    $payment->update([
                        'refunded_cents' => $refunded,
                        'status' => $refunded >= (int) $payment->amount_cents ? 'refunded' : 'partially_refunded',
                    ]);
                }
            }

            $request->update([
                'status' => 'refunded', 'refunded_at' => now(),
                'credit_note_id' => $creditNote?->id, 'gateway_reference' => $reference,
            ]);
            $this->audit($request->organization, 'refund.processed', $request->id, [
                'amount_cents' => $request->amount_cents, 'reference' => $reference,
            ], $userId);
        } catch (\Throwable $e) {
            $request->update(['status' => 'failed', 'failure_reason' => $e->getMessage()]);
            $this->audit($request->organization, 'refund.failed', $request->id, ['error' => $e->getMessage()], $userId);
        }

        return $request->fresh();
    }

    private function assertOpen(RefundRequest $request): void
    {
        if (! in_array($request->status, ['pending', 'approved'], true)) {
            throw ApiException::validation(['status' => [
                "This request is already {$request->status}, so it cannot be decided again.",
            ]]);
        }
    }

    /* -------------------------------------------------------- transactions */

    /**
     * Every movement of money as one list: payments in, refunds out.
     *
     * Built in PHP rather than as a SQL union because the two sides keep
     * different columns, and a union would have to flatten them to the lowest
     * common shape -- losing the gateway on one side and the decision on the
     * other.
     */
    public function transactions(array $filters = [], int $limit = 200): array
    {
        $rows = [];

        $payments = Payment::allTenants()->latest('created_at')->limit($limit);
        if (! empty($filters['organization_id'])) {
            $payments->where('organization_id', $filters['organization_id']);
        }
        foreach ($payments->get() as $p) {
            $rows[] = [
                'id' => $p->id, 'kind' => 'payment', 'direction' => 'in',
                'organization_id' => $p->organization_id,
                'amount_cents' => (int) $p->amount_cents, 'currency' => $p->currency,
                'status' => $p->status, 'gateway' => $p->gateway, 'method' => $p->method,
                'reference' => $p->gateway_reference, 'invoice_id' => $p->invoice_id,
                'at' => ($p->paid_at ?? $p->created_at)?->toIso8601String(),
            ];
        }

        $refunds = RefundRequest::allTenants()->where('status', 'refunded')->latest('refunded_at')->limit($limit);
        if (! empty($filters['organization_id'])) {
            $refunds->where('organization_id', $filters['organization_id']);
        }
        foreach ($refunds->get() as $r) {
            $rows[] = [
                'id' => $r->id, 'kind' => 'refund', 'direction' => 'out',
                'organization_id' => $r->organization_id,
                'amount_cents' => -(int) $r->amount_cents, 'currency' => $r->currency,
                'status' => $r->status, 'gateway' => null, 'method' => null,
                'reference' => $r->gateway_reference, 'invoice_id' => $r->invoice_id,
                'at' => ($r->refunded_at ?? $r->created_at)?->toIso8601String(),
            ];
        }

        usort($rows, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));

        return array_slice($rows, 0, $limit);
    }

    /* ------------------------------------------------------------ profiles */

    public function profile(Organization $org): BillingProfile
    {
        return BillingProfile::allTenants()->firstOrCreate(
            ['organization_id' => $org->id],
            ['company_name' => $org->name, 'currency' => $this->currency()],
        );
    }

    /**
     * Through the AuditLogger, never AuditLog::create: the log is a per-organisation
     * hash chain, and a row written straight to the table has no hash, so it breaks
     * verification for every row after it.
     */
    private function audit(?Organization $org, string $action, string $subject, array $meta, ?string $userId): void
    {
        if (! $org) {
            return;
        }
        app(AuditLogger::class)->log($action, 'finance', $subject, $meta, $org->id, $userId);
    }
}
