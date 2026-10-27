<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Plan;
use App\Services\BillingService;
use App\Support\Lists;
use App\Support\Tenant;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(private BillingService $billing) {}

    public function show()
    {
        return $this->data($this->billing->summary());
    }

    public function plans()
    {
        $current = $this->billing->plan()->code;

        return $this->data(Plan::orderBy('sort')->get()->map(fn ($p) => $this->billing->planApi($p) + ['current' => $p->code === $current]));
    }

    public function changePlan(Request $request)
    {
        $data = $request->validate(['plan' => 'required|exists:plans,code']);
        $this->billing->changePlan($data['plan']);

        return $this->data($this->billing->summary());
    }

    public function invoices(Request $request)
    {
        return response()->json(Lists::paginate(Invoice::query(), $request, ['number'], ['created_at', 'period_start'], ['status'], fn ($i) => $this->billing->invoiceApi($i)));
    }

    /** UBIL-003: only a provider token is accepted; card numbers never reach us. */
    public function paymentMethod(Request $request)
    {
        $data = $request->validate([
            'payment_method_token' => 'required|string|max:255|regex:/^(pm|tok)_[A-Za-z0-9_]+$/',
            'billing_email' => 'nullable|email',
            'card_number' => 'prohibited', 'cvc' => 'prohibited',
        ]);
        $org = Tenant::current();
        $this->billing->updatePaymentMethod($org, $data['payment_method_token']);
        if (! empty($data['billing_email'])) {
            $org->update(['billing_email' => $data['billing_email']]);
        }

        return $this->data($this->billing->summary());
    }
}
