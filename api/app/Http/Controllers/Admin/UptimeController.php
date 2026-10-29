<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Domain;
use App\Services\UptimeService;
use Illuminate\Http\Request;

/** MON-00x. Uptime percentages and downtime history. */
class UptimeController extends Controller
{
    public function __construct(private UptimeService $uptime) {}

    /** One row per verified domain in scope, worst uptime first. */
    public function index(Request $request)
    {
        $request->validate(['range' => 'nullable|in:24h,7d,30d', 'domain_id' => 'nullable|uuid']);
        $range = (string) $request->query('range', '30d');

        $domains = Domain::where('dns_status', 'verified')
            ->when($request->query('domain_id'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $rows = $domains->map(fn (Domain $d) => $this->uptime->report($d, $range))
            // Unknown (no checks yet) sorts last: it is not an outage.
            ->sortBy(fn (array $r) => $r['uptime_pct'] ?? 101)
            ->values()->all();

        return $this->data([
            'range' => $range,
            'domains' => $rows,
            'summary' => [
                'measured' => count(array_filter($rows, fn ($r) => $r['uptime_pct'] !== null)),
                'unmeasured' => count(array_filter($rows, fn ($r) => $r['uptime_pct'] === null)),
                'avg_uptime_pct' => ($m = array_filter(array_column($rows, 'uptime_pct'), fn ($v) => $v !== null))
                    ? round(array_sum($m) / count($m), 3) : null,
                'open_incidents' => Alert::where('status', 'open')->count(),
            ],
        ]);
    }

    public function show(Request $request, string $id)
    {
        $request->validate(['range' => 'nullable|in:24h,7d,30d']);

        return $this->data($this->uptime->report(Domain::findOrFail($id), (string) $request->query('range', '30d')));
    }
}
