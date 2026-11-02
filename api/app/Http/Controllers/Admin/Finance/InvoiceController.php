<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\FinanceService;
use App\Services\InvoiceService;
use App\Support\Lists;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** FIN-3: every customer's invoices, from one screen. */
class InvoiceController extends Controller
{
    public function __construct(private FinanceService $finance, private InvoiceService $invoices) {}

    public function index(Request $request)
    {
        $query = Invoice::allTenants()->with('organization');

        $result = Lists::paginate(
            $query,
            $request,
            ['number', 'provider_invoice_id'],
            ['created_at', 'number', 'amount_cents', 'due_at', 'status'],
            [
                'status' => 'status',
                'kind' => 'kind',
                'organization_id' => 'organization_id',
                // "Overdue" is not a stored status for an invoice nobody has touched
                // since it came due, so it is asked of the dates instead.
                'overdue' => fn ($q, $v) => filter_var($v, FILTER_VALIDATE_BOOLEAN)
                    ? $q->whereIn('status', ['open', 'unpaid', 'partially_paid'])->whereNotNull('due_at')->where('due_at', '<', now())
                    : $q,
                'from' => fn ($q, $v) => $q->where('created_at', '>=', $v),
                'to' => fn ($q, $v) => $q->where('created_at', '<=', $v),
            ],
            fn (Invoice $i) => $this->shape($i),
        );

        return response()->json($result);
    }

    public function show(string $id)
    {
        $invoice = Invoice::allTenants()->with('organization')->findOrFail($id);
        $payments = Payment::allTenants()->where('invoice_id', $invoice->id)->latest()->get();

        return $this->data($this->shape($invoice) + [
            'line_items' => $invoice->line_items ?? [],
            'refundable_cents' => $this->finance->refundableCents($invoice),
            'payments' => $payments->map(fn (Payment $p) => [
                'id' => $p->id, 'amount_cents' => (int) $p->amount_cents, 'currency' => $p->currency,
                'gateway' => $p->gateway, 'method' => $p->method, 'status' => $p->status,
                'paid_at' => $p->paid_at?->toIso8601String(),
            ]),
            'billing_profile' => $invoice->organization
                ? $this->finance->profile($invoice->organization)->only([
                    'company_name', 'contact_name', 'email', 'address_line1', 'address_line2',
                    'city', 'region', 'postal_code', 'country', 'tax_id', 'vat_number',
                ])
                : null,
        ]);
    }

    /**
     * A manual invoice: anything not produced by the monthly meter.
     *
     * The total is computed from the lines rather than taken from the caller,
     * so an invoice can never say one thing and add up to another.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'organization_id' => ['required', 'uuid', Rule::exists('organizations', 'id')],
            'subscription_id' => ['nullable', 'uuid'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'due_at' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in(['draft', 'open'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_cents' => ['required', 'integer'],
            'discount_cents' => ['sometimes', 'integer', 'min:0'],
        ]);

        $settings = $this->finance->settings();
        $lines = array_map(fn ($i) => [
            'description' => $i['description'],
            'quantity' => (float) $i['quantity'],
            'unit_cents' => (int) $i['unit_cents'],
            'amount_cents' => (int) round($i['quantity'] * $i['unit_cents']),
        ], $data['items']);

        $subtotal = array_sum(array_column($lines, 'amount_cents')) - (int) ($data['discount_cents'] ?? 0);
        $subtotal = max(0, $subtotal);
        $rate = ! empty($settings['tax_enabled']) ? (float) $settings['tax_percent'] : 0.0;
        $tax = (int) round($subtotal * $rate / 100);

        $org = Organization::findOrFail($data['organization_id']);
        $invoice = Invoice::create([
            'organization_id' => $org->id,
            'number' => $this->nextNumber(),
            'kind' => 'invoice',
            'period_start' => $data['period_start'] ?? now()->startOfMonth()->toDateString(),
            'period_end' => $data['period_end'] ?? now()->endOfMonth()->toDateString(),
            'line_items' => $lines,
            'subtotal_cents' => $subtotal,
            'tax_cents' => $tax,
            'tax_rate' => $rate,
            'amount_cents' => $subtotal + $tax,
            'currency' => strtoupper($data['currency'] ?? $settings['currency']),
            'status' => $data['status'] ?? 'draft',
            'due_at' => $data['due_at'] ?? now()->addDays((int) $settings['invoice_due_days']),
        ]);

        return $this->data($this->shape($invoice->fresh()), 201);
    }

    public function update(Request $request, string $id)
    {
        $invoice = Invoice::allTenants()->findOrFail($id);
        if (! in_array($invoice->status, ['draft', 'open', 'unpaid', 'overdue'], true)) {
            return response()->json(['error' => [
                'code' => 'invoice_locked',
                'message' => "A {$invoice->status} invoice cannot be edited. Issue a credit note instead.",
            ]], 409);
        }
        $data = $request->validate([
            'due_at' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', Rule::in(['draft', 'open', 'void'])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $invoice->update($data);

        return $this->data($this->shape($invoice->fresh()));
    }

    /** Mark it collected outside a gateway, which is a payment like any other. */
    public function recordPayment(Request $request, string $id)
    {
        $invoice = Invoice::allTenants()->findOrFail($id);
        $data = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1'],
            'method' => ['sometimes', Rule::in(['bank_transfer', 'cash', 'cheque', 'card', 'paypal', 'credit'])],
            'gateway' => ['sometimes', 'string', 'max:30'],
            'gateway_reference' => ['nullable', 'string', 'max:190'],
            'paid_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $payment = $this->finance->recordPayment(
            $invoice->organization,
            $data + ['invoice_id' => $invoice->id, 'status' => 'succeeded'],
            $request->user()?->id,
        );

        return $this->data(['payment_id' => $payment->id, 'invoice' => $this->shape($invoice->fresh())], 201);
    }

    /** Send (or re-send) the invoice to the customer's billing address. */
    public function send(Request $request, string $id)
    {
        $invoice = Invoice::allTenants()->findOrFail($id);
        if ($invoice->status === 'draft') {
            $invoice->update(['status' => 'open']);
        }
        $this->invoices->notify($invoice, 'issued');

        return $this->data(['sent' => true, 'invoice' => $this->shape($invoice->fresh())]);
    }

    /** A reminder is the same letter with a different subject, and is logged. */
    public function remind(Request $request, string $id)
    {
        $invoice = Invoice::allTenants()->findOrFail($id);
        $this->invoices->notify($invoice, 'reminder');

        return $this->data(['sent' => true]);
    }

    public function pdf(string $id)
    {
        $invoice = Invoice::allTenants()->findOrFail($id);

        return response($this->invoices->pdf($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$invoice->number.'.pdf"',
        ]);
    }

    /**
     * The next number in the configured sequence.
     *
     * Derived from the highest number already issued with this prefix rather
     * than from a counter in the settings, so an invoice created while
     * another is being created cannot take the same number after a rollback.
     */
    private function nextNumber(): string
    {
        $s = $this->finance->settings();
        $prefix = (string) $s['invoice_prefix'];
        $last = Invoice::allTenants()->where('number', 'like', $prefix.'%')
            ->orderByRaw('LENGTH(number) DESC')->orderBy('number', 'desc')->value('number');
        $n = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $n, (int) $s['invoice_number_padding'], '0', STR_PAD_LEFT);
    }

    private function shape(Invoice $i): array
    {
        $overdue = in_array($i->status, ['open', 'unpaid', 'partially_paid'], true)
            && $i->due_at !== null && $i->due_at->isPast();

        return [
            'id' => $i->id,
            'number' => $i->number,
            'kind' => $i->kind ?? 'invoice',
            'organization_id' => $i->organization_id,
            'customer' => $i->organization?->name,
            'subtotal_cents' => (int) ($i->subtotal_cents ?? 0),
            'tax_cents' => (int) ($i->tax_cents ?? 0),
            'amount_cents' => (int) $i->amount_cents,
            'refunded_cents' => (int) ($i->refunded_cents ?? 0),
            'currency' => $i->currency,
            'status' => $overdue ? 'overdue' : $i->status,
            'period_start' => $i->period_start?->toDateString(),
            'period_end' => $i->period_end?->toDateString(),
            'due_at' => $i->due_at?->toIso8601String(),
            'paid_at' => $i->paid_at?->toIso8601String(),
            'collection_attempts' => (int) ($i->collection_attempts ?? 0),
            'created_at' => $i->created_at?->toIso8601String(),
        ];
    }
}
