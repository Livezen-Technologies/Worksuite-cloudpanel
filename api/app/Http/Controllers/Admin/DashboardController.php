<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use App\Services\MetricsService;
use App\Services\SearchService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function dashboard(Request $request, DashboardService $dashboard)
    {
        return $this->data($dashboard->build((string) $request->query('range', '1h')));
    }

    public function metrics(Request $request, MetricsService $metrics)
    {
        $request->validate(['server_id' => 'nullable|uuid', 'range' => 'nullable|in:1h,6h,24h,7d']);
        if ($id = $request->query('server_id')) {
            \App\Models\Server::findOrFail($id);
        }

        return $this->data($metrics->series($request->query('server_id'), (string) $request->query('range', '1h')));
    }

    public function search(Request $request, SearchService $search)
    {
        return $this->data($search->search((string) $request->query('q', '')));
    }
}
