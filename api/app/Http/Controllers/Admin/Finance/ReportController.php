<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Payment;
use App\Services\FinanceService;
use App\Services\InvoiceService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** FIN-10: revenue analytics, and the same figures as a file. */
class ReportController extends Controller
{
    public function __construct(private FinanceService $finance) {}

    public function show(Request $request)
    {
        [$from, $to] = OverviewController::window($request);
        $months = max(1, min(36, (int) $request->query('months', 12)));

        return $this->data([
            'kpis' => $this->finance->kpis($from, $to),
            'series' => $this->finance->monthlySeries($months),
            'breakdowns' => $this->finance->breakdowns($from, $to),
            'top_customers' => $this->topCustomers($request),
        ]);
    }

    /**
     * Who pays the most over the window.
     *
     * Grouped in SQL and the names fetched once, so the list stays one query
     * plus one however many customers there are.
     */
    private function topCustomers(Request $request, int $limit = 10): array
    {
        [$from, $to] = OverviewController::window($request);
        $rows = Payment::allTenants()->where('status', 'succeeded')->whereBetween('paid_at', [$from, $to])
            ->selectRaw('organization_id, COUNT(*) AS n, SUM(amount_cents) AS cents')
            ->groupBy('organization_id')->orderByRaw('SUM(amount_cents) DESC')->limit($limit)->get();
        $names = Organization::whereIn('id', $rows->pluck('organization_id'))->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'organization_id' => $r->organization_id,
            'customer' => $names[$r->organization_id] ?? null,
            'payments' => (int) $r->n,
            'amount_cents' => (int) $r->cents,
        ])->all();
    }

    /**
     * The payments behind the report, as CSV.
     *
     * Streamed rather than built in memory: an export covering a year of a
     * busy panel is a file, not a string, and building it whole would hold
     * every row at once for no benefit.
     */
    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = OverviewController::window($request);
        $names = Organization::pluck('name', 'id');
        $filename = 'payments-'.$from->toDateString().'-to-'.$to->toDateString().'.csv';

        return response()->streamDownload(function () use ($from, $to, $names) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Paid at', 'Customer', 'Invoice', 'Gateway', 'Method', 'Status', 'Currency', 'Amount', 'Refunded', 'Reference']);
            Payment::allTenants()->with('invoice')->whereBetween('created_at', [$from, $to])
                ->orderBy('created_at')->chunk(500, function ($chunk) use ($out, $names) {
                    foreach ($chunk as $p) {
                        fputcsv($out, [
                            $p->paid_at?->toDateTimeString() ?? '',
                            $names[$p->organization_id] ?? $p->organization_id,
                            $p->invoice?->number ?? '',
                            $p->gateway, $p->method, $p->status, $p->currency,
                            number_format($p->amount_cents / 100, 2, '.', ''),
                            number_format($p->refunded_cents / 100, 2, '.', ''),
                            $p->gateway_reference ?? '',
                        ]);
                    }
                });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** The money figures a bookkeeper asks for, already totalled. */
    public function summary(Request $request)
    {
        [$from, $to] = OverviewController::window($request);
        $k = $this->finance->kpis($from, $to);
        $currency = $k['currency'];

        return $this->data([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'currency' => $currency,
            'lines' => [
                ['label' => 'Collected', 'cents' => $k['total_revenue_cents'], 'display' => InvoiceService::money($k['total_revenue_cents'], $currency)],
                ['label' => 'Refunded', 'cents' => -$k['refunded_cents'], 'display' => InvoiceService::money(-$k['refunded_cents'], $currency)],
                ['label' => 'Net', 'cents' => $k['total_revenue_cents'] - $k['refunded_cents'], 'display' => InvoiceService::money($k['total_revenue_cents'] - $k['refunded_cents'], $currency)],
                ['label' => 'Outstanding', 'cents' => $k['outstanding_cents'], 'display' => InvoiceService::money($k['outstanding_cents'], $currency)],
                ['label' => 'Overdue', 'cents' => $k['overdue_cents'], 'display' => InvoiceService::money($k['overdue_cents'], $currency)],
            ],
        ]);
    }
}
