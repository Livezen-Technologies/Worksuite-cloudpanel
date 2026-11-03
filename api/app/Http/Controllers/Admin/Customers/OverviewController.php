<?php

namespace App\Http\Controllers\Admin\Customers;

use App\Http\Controllers\Admin\Finance\OverviewController as FinanceOverview;
use App\Http\Controllers\Controller;
use App\Services\CustomerService;
use Illuminate\Http\Request;

/** CUST-2: the customer dashboard -- headline figures, growth and splits. */
class OverviewController extends Controller
{
    public function __construct(private CustomerService $customers) {}

    public function show(Request $request)
    {
        // The same range resolution as Finance, so "this month" means the same
        // thing on both dashboards and is decided by the server's clock.
        [$from, $to] = FinanceOverview::window($request);

        return $this->data($this->customers->overview($from, $to));
    }
}
