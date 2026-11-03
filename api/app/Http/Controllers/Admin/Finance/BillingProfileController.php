<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Models\BillingProfile;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\RefundRequest;
use App\Models\Subscription;
use App\Services\AuditLogger;
use App\Services\FinanceService;
use App\Support\Lists;
use Illuminate\Http\Request;

/** FIN-8: who each customer is, for billing purposes. */
class BillingProfileController extends Controller
{
    public function __construct(private FinanceService $finance) {}

    public function index(Request $request)
    {
        $result = Lists::paginate(
            BillingProfile::allTenants()->with('organization'),
            $request,
            ['company_name', 'contact_name', 'email', 'tax_id', 'vat_number'],
            ['created_at', 'company_name', 'balance_cents'],
            ['organization_id' => 'organization_id', 'country' => 'country'],
            fn (BillingProfile $p) => $this->shape($p),
        );

        return response()->json($result);
    }

    /** The customer's whole financial picture, which is what this tab is for. */
    public function show(string $organizationId)
    {
        $org = Organization::findOrFail($organizationId);
        $profile = $this->finance->profile($org);
        $subscription = Subscription::allTenants()->with('plan')->where('organization_id', $org->id)
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")->latest()->first();

        return $this->data([
            'profile' => $this->shape($profile),
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'plan' => $subscription->plan?->name,
                'status' => $subscription->status,
                'billing_cycle' => $subscription->billing_cycle ?? 'monthly',
                'renews_at' => $subscription->current_period_end?->toIso8601String(),
                'monthly_value_cents' => $this->finance->monthlyValueCents($subscription),
            ] : null,
            'invoices' => Invoice::allTenants()->where('organization_id', $org->id)->latest()->limit(50)->get()
                ->map(fn (Invoice $i) => [
                    'id' => $i->id, 'number' => $i->number, 'kind' => $i->kind ?? 'invoice',
                    'amount_cents' => (int) $i->amount_cents, 'currency' => $i->currency,
                    'status' => $i->status, 'due_at' => $i->due_at?->toIso8601String(),
                    'created_at' => $i->created_at?->toIso8601String(),
                ]),
            'payments' => Payment::allTenants()->where('organization_id', $org->id)->latest()->limit(50)->get()
                ->map(fn (Payment $p) => [
                    'id' => $p->id, 'amount_cents' => (int) $p->amount_cents, 'currency' => $p->currency,
                    'gateway' => $p->gateway, 'method' => $p->method, 'status' => $p->status,
                    'paid_at' => $p->paid_at?->toIso8601String(),
                ]),
            'refunds' => RefundRequest::allTenants()->where('organization_id', $org->id)->latest()->limit(50)->get()
                ->map(fn (RefundRequest $r) => [
                    'id' => $r->id, 'amount_cents' => (int) $r->amount_cents, 'currency' => $r->currency,
                    'status' => $r->status, 'reason' => $r->reason,
                    'created_at' => $r->created_at?->toIso8601String(),
                ]),
            'totals' => $this->totals($org),
        ]);
    }

    public function update(Request $request, string $organizationId)
    {
        $org = Organization::findOrFail($organizationId);
        $profile = $this->finance->profile($org);
        $data = $request->validate([
            'company_name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:190'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:190'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'region' => ['sometimes', 'nullable', 'string', 'max:120'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:30'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:60'],
            'vat_number' => ['sometimes', 'nullable', 'string', 'max:60'],
            'tax_exempt' => ['sometimes', 'boolean'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'invoice_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        if (isset($data['country'])) {
            $data['country'] = strtoupper($data['country']);
        }
        $profile->update($data);
        app(AuditLogger::class)->log('billing_profile.updated', 'finance', $profile->id, array_keys($data), $org->id, $request->user()?->id);

        return $this->data($this->shape($profile->fresh()));
    }

    /**
     * Credit the account, or take credit off it.
     *
     * Kept as an adjustment with a reason rather than a settable balance:
     * "why is this customer 40 up" has to be answerable later, and a field
     * that is simply overwritten cannot answer it.
     */
    public function adjustBalance(Request $request, string $organizationId)
    {
        $org = Organization::findOrFail($organizationId);
        $profile = $this->finance->profile($org);
        $data = $request->validate([
            'amount_cents' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $profile->update(['balance_cents' => (int) $profile->balance_cents + (int) $data['amount_cents']]);
        app(AuditLogger::class)->log('billing_profile.balance_adjusted', 'finance', $profile->id, [
            'amount_cents' => $data['amount_cents'], 'reason' => $data['reason'],
            'balance_cents' => $profile->balance_cents,
        ], $org->id, $request->user()?->id);

        return $this->data($this->shape($profile->fresh()));
    }

    private function totals(Organization $org): array
    {
        $paid = (int) Payment::allTenants()->where('organization_id', $org->id)->where('status', 'succeeded')->sum('amount_cents');
        $refunded = (int) RefundRequest::allTenants()->where('organization_id', $org->id)->where('status', 'refunded')->sum('amount_cents');
        $open = Invoice::allTenants()->where('organization_id', $org->id)->whereIn('status', ['open', 'unpaid', 'partially_paid']);

        return [
            'lifetime_paid_cents' => $paid,
            'refunded_cents' => $refunded,
            'net_cents' => $paid - $refunded,
            'outstanding_cents' => (int) $open->sum('amount_cents'),
            'outstanding_invoices' => $open->count(),
        ];
    }

    private function shape(BillingProfile $p): array
    {
        return [
            'id' => $p->id,
            'organization_id' => $p->organization_id,
            'customer' => $p->organization?->name,
            'company_name' => $p->company_name,
            'contact_name' => $p->contact_name,
            'email' => $p->email,
            'phone' => $p->phone,
            'address_line1' => $p->address_line1,
            'address_line2' => $p->address_line2,
            'city' => $p->city,
            'region' => $p->region,
            'postal_code' => $p->postal_code,
            'country' => $p->country,
            'tax_id' => $p->tax_id,
            'vat_number' => $p->vat_number,
            'tax_exempt' => (bool) $p->tax_exempt,
            'currency' => $p->currency,
            'balance_cents' => (int) $p->balance_cents,
            'invoice_notes' => $p->invoice_notes,
        ];
    }
}
