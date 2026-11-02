<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\FinanceService;
use Illuminate\Http\Request;

/** FIN-4: money in and money out, in one chronological list. */
class TransactionController extends Controller
{
    public function __construct(private FinanceService $finance) {}

    public function index(Request $request)
    {
        $request->validate([
            'organization_id' => ['sometimes', 'uuid'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);
        $rows = $this->finance->transactions(
            ['organization_id' => $request->query('organization_id')],
            (int) $request->query('limit', 200),
        );

        // One lookup for the names rather than one per row: a 500-row page
        // would otherwise be 500 queries for a column nobody filtered on.
        $names = Organization::whereIn('id', array_unique(array_column($rows, 'organization_id')))
            ->pluck('name', 'id');

        return $this->data([
            'rows' => array_map(fn ($r) => $r + ['customer' => $names[$r['organization_id']] ?? null], $rows),
            'totals' => [
                'in_cents' => array_sum(array_map(fn ($r) => $r['direction'] === 'in' && $r['status'] === 'succeeded' ? $r['amount_cents'] : 0, $rows)),
                'out_cents' => array_sum(array_map(fn ($r) => $r['direction'] === 'out' ? $r['amount_cents'] : 0, $rows)),
            ],
            'currency' => $this->finance->currency(),
        ]);
    }
}
