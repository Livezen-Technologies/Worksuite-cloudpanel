<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\RefundRequest;
use App\Services\EmailTemplateService;
use App\Services\FinanceService;
use App\Services\InvoiceService;
use App\Support\Lists;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** FIN-5: refund requests, and the decisions taken on them. */
class RefundController extends Controller
{
    public function __construct(private FinanceService $finance) {}

    public function index(Request $request)
    {
        $result = Lists::paginate(
            RefundRequest::allTenants()->with(['organization', 'invoice']),
            $request,
            ['reason', 'admin_notes', 'gateway_reference'],
            ['created_at', 'decided_at', 'amount_cents', 'status'],
            [
                'status' => 'status',
                'organization_id' => 'organization_id',
                'invoice_id' => 'invoice_id',
                'from' => fn ($q, $v) => $q->where('created_at', '>=', $v),
                'to' => fn ($q, $v) => $q->where('created_at', '<=', $v),
            ],
            fn (RefundRequest $r) => $this->shape($r),
        );

        return response()->json($result);
    }

    public function show(string $id)
    {
        $r = RefundRequest::allTenants()->with(['organization', 'invoice', 'payment'])->findOrFail($id);

        return $this->data($this->shape($r) + [
            'admin_notes' => $r->admin_notes,
            'failure_reason' => $r->failure_reason,
            'credit_note_id' => $r->credit_note_id,
            'payment' => $r->payment ? [
                'id' => $r->payment->id, 'amount_cents' => (int) $r->payment->amount_cents,
                'gateway' => $r->payment->gateway, 'refundable_cents' => $r->payment->refundableCents(),
            ] : null,
        ]);
    }

    /** An administrator raising a refund on the customer's behalf. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'organization_id' => ['required', 'uuid', Rule::exists('organizations', 'id')],
            'invoice_id' => ['nullable', 'uuid', 'required_without:payment_id'],
            'payment_id' => ['nullable', 'uuid', 'required_without:invoice_id'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $refund = $this->finance->requestRefund(
            Organization::findOrFail($data['organization_id']),
            $data,
            $request->user()?->id,
        );

        return $this->data($this->shape($refund), 201);
    }

    /** Approve and pay out. The gateway decides whether it succeeds. */
    public function approve(Request $request, string $id)
    {
        $refund = RefundRequest::allTenants()->findOrFail($id);
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);

        $refund = $this->finance->approveRefund($refund, $data['notes'] ?? null, $request->user()?->id);
        if ($refund->status === 'failed') {
            return response()->json(['error' => [
                'code' => 'refund_failed',
                'message' => 'The gateway refused the refund: '.$refund->failure_reason,
            ]], 502);
        }

        return $this->data($this->shape($refund));
    }

    /** Turn it down, and tell the customer why. */
    public function reject(Request $request, string $id)
    {
        $refund = RefundRequest::allTenants()->findOrFail($id);
        $data = $request->validate(['notes' => ['required', 'string', 'max:1000']]);

        $refund = $this->finance->rejectRefund($refund, $data['notes'], $request->user()?->id);
        app(EmailTemplateService::class)->notifyOrganization($refund->organization_id, 'refund_rejected', [
            'amount' => InvoiceService::money((int) $refund->amount_cents, (string) $refund->currency),
            'reason' => $refund->reason,
            'notes' => $data['notes'],
        ]);

        return $this->data($this->shape($refund));
    }

    private function shape(RefundRequest $r): array
    {
        return [
            'id' => $r->id,
            'organization_id' => $r->organization_id,
            'customer' => $r->organization?->name,
            'invoice_id' => $r->invoice_id,
            'invoice_number' => $r->invoice?->number,
            'payment_id' => $r->payment_id,
            'amount_cents' => (int) $r->amount_cents,
            'currency' => $r->currency,
            'status' => $r->status,
            'reason' => $r->reason,
            'gateway_reference' => $r->gateway_reference,
            'decided_at' => $r->decided_at?->toIso8601String(),
            'refunded_at' => $r->refunded_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
