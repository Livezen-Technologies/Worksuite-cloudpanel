<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\AuditLogger;
use App\Services\FinanceService;
use App\Support\Lists;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** FIN-6: every customer's subscription, and the package it is on. */
class SubscriptionController extends Controller
{
    public function __construct(private FinanceService $finance) {}

    public function index(Request $request)
    {
        $result = Lists::paginate(
            Subscription::allTenants()->with(['organization', 'plan']),
            $request,
            ['provider_subscription_id', 'notes'],
            ['created_at', 'current_period_end', 'status'],
            [
                'status' => 'status',
                'plan_id' => 'plan_id',
                'organization_id' => 'organization_id',
                'billing_cycle' => 'billing_cycle',
                // "Expiring" is a question about the next 30 days, not a status.
                'expiring' => fn ($q, $v) => filter_var($v, FILTER_VALIDATE_BOOLEAN)
                    ? $q->where('status', 'active')->whereBetween('current_period_end', [now(), now()->addDays(30)])
                    : $q,
            ],
            fn (Subscription $s) => $this->shape($s),
        );

        return response()->json($result);
    }

    public function show(string $id)
    {
        $s = Subscription::allTenants()->with(['organization', 'plan', 'pendingPlan'])->findOrFail($id);
        $invoices = Invoice::allTenants()->where('organization_id', $s->organization_id)->latest()->limit(20)->get();
        $payments = Payment::allTenants()->where('organization_id', $s->organization_id)->latest()->limit(20)->get();

        return $this->data($this->shape($s) + [
            'notes' => $s->notes,
            'pending_plan' => $s->pendingPlan ? ['id' => $s->pendingPlan->id, 'name' => $s->pendingPlan->name] : null,
            'pending_plan_at' => $s->pending_plan_at?->toIso8601String(),
            'invoices' => $invoices->map(fn (Invoice $i) => [
                'id' => $i->id, 'number' => $i->number, 'amount_cents' => (int) $i->amount_cents,
                'currency' => $i->currency, 'status' => $i->status, 'created_at' => $i->created_at?->toIso8601String(),
            ]),
            'payments' => $payments->map(fn (Payment $p) => [
                'id' => $p->id, 'amount_cents' => (int) $p->amount_cents, 'currency' => $p->currency,
                'gateway' => $p->gateway, 'status' => $p->status, 'paid_at' => $p->paid_at?->toIso8601String(),
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'organization_id' => ['required', 'uuid', Rule::exists('organizations', 'id')],
            'plan_id' => ['required', 'uuid', Rule::exists('plans', 'id')],
            'billing_cycle' => ['sometimes', Rule::in(['monthly', 'yearly'])],
            'status' => ['sometimes', Rule::in(['active', 'trialing', 'suspended'])],
            'discount_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'auto_renew' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $cycle = $data['billing_cycle'] ?? 'monthly';
        $start = CarbonImmutable::now();
        $trialDays = (int) ($data['trial_days'] ?? 0);

        $subscription = Subscription::create([
            'organization_id' => $data['organization_id'],
            'plan_id' => $data['plan_id'],
            'status' => $trialDays > 0 ? 'trialing' : ($data['status'] ?? 'active'),
            'billing_cycle' => $cycle,
            'discount_percent' => (int) ($data['discount_percent'] ?? 0),
            'trial_ends_at' => $trialDays > 0 ? $start->addDays($trialDays) : null,
            'auto_renew' => $data['auto_renew'] ?? true,
            'notes' => $data['notes'] ?? null,
            'current_period_start' => $start,
            'current_period_end' => $cycle === 'yearly' ? $start->addYear() : $start->addMonth(),
        ]);
        $this->audit($subscription, 'subscription.created', ['plan_id' => $subscription->plan_id]);

        return $this->data($this->shape($subscription->fresh()), 201);
    }

    public function update(Request $request, string $id)
    {
        $s = Subscription::allTenants()->findOrFail($id);
        $data = $request->validate([
            'discount_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'auto_renew' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'billing_cycle' => ['sometimes', Rule::in(['monthly', 'yearly'])],
        ]);
        $s->update($data);
        $this->audit($s, 'subscription.updated', $data);

        return $this->data($this->shape($s->fresh()));
    }

    /**
     * Move a subscription through its lifecycle.
     *
     * `cancel` records when, not just that: churn is measured from
     * `cancelled_at`, and a status alone cannot say when the customer left.
     */
    public function transition(Request $request, string $id)
    {
        $s = Subscription::allTenants()->findOrFail($id);
        $data = $request->validate([
            'action' => ['required', Rule::in(['activate', 'suspend', 'resume', 'cancel', 'renew'])],
            'reason' => ['nullable', 'string', 'max:500'],
            // A cancellation at period end keeps the service the customer paid for.
            'at_period_end' => ['sometimes', 'boolean'],
        ]);

        $now = CarbonImmutable::now();
        match ($data['action']) {
            'activate', 'resume' => $s->update(['status' => 'active', 'cancelled_at' => null]),
            'suspend' => $s->update(['status' => 'suspended']),
            'cancel' => $s->update([
                'status' => ($data['at_period_end'] ?? false) ? 'active' : 'cancelled',
                'auto_renew' => false,
                'cancelled_at' => $now,
                'expires_at' => ($data['at_period_end'] ?? false) ? $s->current_period_end : $now,
            ]),
            'renew' => $s->update([
                'status' => 'active',
                'current_period_start' => $s->current_period_end ?? $now,
                'current_period_end' => ($s->billing_cycle === 'yearly')
                    ? CarbonImmutable::parse($s->current_period_end ?? $now)->addYear()
                    : CarbonImmutable::parse($s->current_period_end ?? $now)->addMonth(),
            ]),
        };
        $this->audit($s, 'subscription.'.$data['action'], ['reason' => $data['reason'] ?? null]);

        return $this->data($this->shape($s->fresh()));
    }

    /** Push the end of the current period out, without charging for it. */
    public function extend(Request $request, string $id)
    {
        $s = Subscription::allTenants()->findOrFail($id);
        $data = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:730'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $end = CarbonImmutable::parse($s->current_period_end ?? now())->addDays((int) $data['days']);
        $s->update(['current_period_end' => $end, 'expires_at' => null]);
        $this->audit($s, 'subscription.extended', ['days' => $data['days'], 'reason' => $data['reason'] ?? null]);

        return $this->data($this->shape($s->fresh()));
    }

    /**
     * FIN-6 package override: what a change would cost before anyone commits
     * to it. Proration is the unused part of what was paid for, set against
     * the new package over the same remaining days -- positive is owed,
     * negative is a credit.
     */
    public function quoteChange(Request $request, string $id)
    {
        $s = Subscription::allTenants()->with('plan')->findOrFail($id);
        $data = $request->validate([
            'plan_id' => ['required', 'uuid', Rule::exists('plans', 'id')],
            'billing_cycle' => ['sometimes', Rule::in(['monthly', 'yearly'])],
            'when' => ['sometimes', Rule::in(['now', 'renewal'])],
        ]);

        return $this->data($this->proration($s, Plan::findOrFail($data['plan_id']), $data['billing_cycle'] ?? $s->billing_cycle, $data['when'] ?? 'now'));
    }

    /** Apply the change -- now, or booked for the next renewal. */
    public function changePlan(Request $request, string $id)
    {
        $s = Subscription::allTenants()->with('plan')->findOrFail($id);
        $data = $request->validate([
            'plan_id' => ['required', 'uuid', Rule::exists('plans', 'id')],
            'billing_cycle' => ['sometimes', Rule::in(['monthly', 'yearly'])],
            'when' => ['sometimes', Rule::in(['now', 'renewal'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $plan = Plan::findOrFail($data['plan_id']);
        $cycle = $data['billing_cycle'] ?? $s->billing_cycle;
        $when = $data['when'] ?? 'now';
        $quote = $this->proration($s, $plan, $cycle, $when);
        $from = $s->plan_id;

        if ($when === 'renewal') {
            $s->update(['pending_plan_id' => $plan->id, 'pending_plan_at' => $s->current_period_end]);
        } else {
            $s->update([
                'plan_id' => $plan->id, 'billing_cycle' => $cycle,
                'pending_plan_id' => null, 'pending_plan_at' => null,
            ]);
        }
        $this->audit($s, 'subscription.plan_changed', [
            'from_plan_id' => $from, 'to_plan_id' => $plan->id, 'when' => $when,
            'billing_cycle' => $cycle, 'proration_cents' => $quote['proration_cents'],
            'reason' => $data['reason'] ?? null,
        ]);

        return $this->data($this->shape($s->fresh()) + ['quote' => $quote]);
    }

    /** @return array{proration_cents:int, credit_cents:int, charge_cents:int, days_remaining:int, effective_at:?string, currency:string} */
    private function proration(Subscription $s, Plan $plan, string $cycle, string $when): array
    {
        $currency = strtoupper((string) ($s->plan?->currency ?? $this->finance->currency()));
        if ($when === 'renewal') {
            return [
                'proration_cents' => 0, 'credit_cents' => 0, 'charge_cents' => 0,
                'days_remaining' => 0, 'effective_at' => $s->current_period_end?->toIso8601String(),
                'currency' => $currency,
            ];
        }

        $start = CarbonImmutable::parse($s->current_period_start ?? now());
        $end = CarbonImmutable::parse($s->current_period_end ?? now()->addMonth());
        $total = max(1, $start->diffInDays($end));
        $left = max(0, (int) CarbonImmutable::now()->diffInDays($end, false));

        $oldCycle = $s->billing_cycle ?? 'monthly';
        $oldPeriod = (int) ($s->plan?->price_cents ?? 0) * ($oldCycle === 'yearly' ? 12 : 1);
        $newPeriod = (int) $plan->price_cents * ($cycle === 'yearly' ? 12 : 1);
        $discount = (100 - min(100, max(0, (int) ($s->discount_percent ?? 0)))) / 100;

        $credit = (int) round($oldPeriod * $discount * $left / $total);
        $charge = (int) round($newPeriod * $discount * $left / $total);

        return [
            'proration_cents' => $charge - $credit,
            'credit_cents' => $credit,
            'charge_cents' => $charge,
            'days_remaining' => $left,
            'effective_at' => CarbonImmutable::now()->toIso8601String(),
            'currency' => $currency,
        ];
    }

    private function audit(Subscription $s, string $action, array $meta): void
    {
        app(AuditLogger::class)->log($action, 'subscription', $s->id, $meta, $s->organization_id, request()->user()?->id);
    }

    private function shape(Subscription $s): array
    {
        return [
            'id' => $s->id,
            'organization_id' => $s->organization_id,
            'customer' => $s->organization?->name,
            'plan_id' => $s->plan_id,
            'plan' => $s->plan?->name,
            'plan_code' => $s->plan?->code,
            'status' => $s->status,
            'billing_cycle' => $s->billing_cycle ?? 'monthly',
            'discount_percent' => (int) ($s->discount_percent ?? 0),
            'monthly_value_cents' => $this->finance->monthlyValueCents($s),
            'currency' => strtoupper((string) ($s->plan?->currency ?? $this->finance->currency())),
            'auto_renew' => (bool) ($s->auto_renew ?? true),
            'on_trial' => $s->onTrial(),
            'trial_ends_at' => $s->trial_ends_at?->toIso8601String(),
            'current_period_start' => $s->current_period_start?->toIso8601String(),
            'current_period_end' => $s->current_period_end?->toIso8601String(),
            'cancelled_at' => $s->cancelled_at?->toIso8601String(),
            'expires_at' => $s->expires_at?->toIso8601String(),
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }
}
