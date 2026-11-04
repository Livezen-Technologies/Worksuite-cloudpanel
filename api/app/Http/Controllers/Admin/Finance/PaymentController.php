<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Payment;
use App\Services\FinanceService;
use App\Support\Lists;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** FIN-4: every payment, from every gateway and from the bank. */
class PaymentController extends Controller
{
    public function __construct(private FinanceService $finance) {}

    public function index(Request $request)
    {
        $result = Lists::paginate(
            Payment::allTenants()->with(['organization', 'invoice']),
            $request,
            ['gateway_reference', 'notes'],
            ['created_at', 'paid_at', 'amount_cents', 'status'],
            [
                'status' => 'status',
                'gateway' => 'gateway',
                'method' => 'method',
                'organization_id' => 'organization_id',
                'invoice_id' => 'invoice_id',
                'from' => fn ($q, $v) => $q->where('created_at', '>=', $v),
                'to' => fn ($q, $v) => $q->where('created_at', '<=', $v),
            ],
            fn (Payment $p) => $this->shape($p),
        );

        return response()->json($result);
    }

    public function show(string $id)
    {
        $payment = Payment::allTenants()->with(['organization', 'invoice'])->findOrFail($id);

        return $this->data($this->shape($payment) + [
            'notes' => $payment->notes,
            'failure_reason' => $payment->failure_reason,
            'refundable_cents' => $payment->refundableCents(),
            'meta' => $payment->meta,
        ]);
    }

    /** Record a payment taken outside a gateway, for a customer with or without an invoice. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'organization_id' => ['required', 'uuid', Rule::exists('organizations', 'id')],
            'invoice_id' => ['nullable', 'uuid'],
            'subscription_id' => ['nullable', 'uuid'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'gateway' => ['sometimes', 'string', 'max:30'],
            'method' => ['sometimes', Rule::in(['bank_transfer', 'cash', 'cheque', 'card', 'paypal', 'credit'])],
            'status' => ['sometimes', Rule::in(['succeeded', 'pending', 'failed'])],
            'gateway_reference' => ['nullable', 'string', 'max:190'],
            'paid_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $payment = $this->finance->recordPayment(
            Organization::findOrFail($data['organization_id']),
            $data,
            $request->user()?->id,
        );

        return $this->data($this->shape($payment->fresh()), 201);
    }

    /**
     * Reconcile: confirm a pending payment really arrived, or mark that it did not.
     *
     * Settling the invoice afterwards is what makes this more than a label --
     * a payment moved to succeeded has to be able to close the invoice it was
     * against, and one moved to failed has to re-open it.
     */
    public function reconcile(Request $request, string $id)
    {
        $payment = Payment::allTenants()->findOrFail($id);
        $data = $request->validate([
            'status' => ['required', Rule::in(['succeeded', 'failed', 'disputed'])],
            'notes' => ['nullable', 'string', 'max:1000'],
            'failure_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $payment->update([
            'status' => $data['status'],
            'notes' => $data['notes'] ?? $payment->notes,
            'failure_reason' => $data['status'] === 'succeeded' ? null : ($data['failure_reason'] ?? $payment->failure_reason),
            'paid_at' => $data['status'] === 'succeeded' ? ($payment->paid_at ?? now()) : null,
        ]);
        if ($payment->invoice) {
            $this->finance->settleInvoice($payment->invoice);
        }

        return $this->data($this->shape($payment->fresh()));
    }

    /** A receipt is the payment plus whom it was for and what it settled. */
    public function receipt(string $id)
    {
        $payment = Payment::allTenants()->with(['organization', 'invoice'])->findOrFail($id);
        $profile = $payment->organization ? $this->finance->profile($payment->organization) : null;

        return $this->data([
            'payment' => $this->shape($payment),
            'issued_to' => $profile?->addressLines() ?? [$payment->organization?->name],
            'tax_id' => $profile?->tax_id,
            'invoice_number' => $payment->invoice?->number,
        ]);
    }

    private function shape(Payment $p): array
    {
        return [
            'id' => $p->id,
            'organization_id' => $p->organization_id,
            'customer' => $p->organization?->name,
            'invoice_id' => $p->invoice_id,
            'invoice_number' => $p->invoice?->number,
            'subscription_id' => $p->subscription_id,
            'amount_cents' => (int) $p->amount_cents,
            'refunded_cents' => (int) $p->refunded_cents,
            'currency' => $p->currency,
            'gateway' => $p->gateway,
            'method' => $p->method,
            'status' => $p->status,
            'gateway_reference' => $p->gateway_reference,
            'paid_at' => $p->paid_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }
}
