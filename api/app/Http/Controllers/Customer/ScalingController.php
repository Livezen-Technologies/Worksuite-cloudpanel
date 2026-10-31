<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Admin\ScalingController as AdminScaling;
use App\Models\Application;
use App\Services\BillingService;

/** USCL-1..3 / SCL-10: copies of the customer's own apps, within the plan. */
class ScalingController extends AdminScaling
{
    protected function app(string $id): Application
    {
        return Application::where('kind', 'customer')->findOrFail($id);
    }

    protected function limits(): array
    {
        $billing = app(BillingService::class);

        return ['max_replicas' => max(1, $billing->limit('max_replicas', 3)), 'autoscale' => (bool) $billing->limit('autoscale', 1), 'schedule' => false, 'placement' => false,
            // USCL-3: traffic splitting (canary releases) on packages with `traffic_splitting`; routing is read-only.
            'canary' => (bool) $billing->limit('traffic_splitting', 0), 'routing' => false];
    }
}
