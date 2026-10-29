<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Application;
use App\Models\Container;
use App\Models\Domain;
use App\Services\UptimeService;
use App\Support\Lists;
use Illuminate\Http\Request;

/**
 * MON-00x. Incidents as the hosting customer sees them: only resources in
 * their own organisation, named rather than referred to by id, and without the
 * infrastructure detail that is meaningless to them.
 *
 * The organisation scope comes from BelongsToOrganization, so this cannot
 * return another tenant's incidents.
 */
class IncidentController extends Controller
{
    public function __construct(private UptimeService $uptime) {}

    public function index(Request $request)
    {
        // Resolve resource names once, rather than per row.
        $domains = Domain::pluck('name', 'id');
        $apps = Application::pluck('name', 'id');
        $containers = Container::pluck('name', 'id');

        return response()->json(Lists::paginate(
            Alert::query()->orderByRaw("case status when 'open' then 0 when 'acknowledged' then 1 else 2 end")->orderByDesc('created_at'),
            $request,
            ['title', 'message'],
            ['created_at', 'severity', 'status'],
            ['status', 'severity'],
            fn (Alert $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'message' => $a->message,
                'severity' => $a->severity,
                'status' => $a->status,
                'resource' => match ($a->resource_type) {
                    'domain' => $domains[$a->resource_id] ?? null,
                    'application' => $apps[$a->resource_id] ?? null,
                    'container' => $containers[$a->resource_id] ?? null,
                    default => null,
                },
                'resource_type' => $a->resource_type,
                'started_at' => $a->created_at?->toIso8601ZuluString(),
                'resolved_at' => $a->resolved_at?->toIso8601ZuluString(),
                'minutes' => $a->created_at
                    ? (int) $a->created_at->diffInMinutes($a->resolved_at ?? now())
                    : null,
            ],
        ));
    }

    /** Uptime for the customer's own sites. */
    public function uptime(Request $request)
    {
        $request->validate(['range' => 'nullable|in:24h,7d,30d']);
        $range = (string) $request->query('range', '30d');

        return $this->data([
            'range' => $range,
            'domains' => Domain::where('dns_status', 'verified')->get()
                ->map(fn (Domain $d) => $this->uptime->report($d, $range))
                ->sortBy(fn (array $r) => $r['uptime_pct'] ?? 101)->values()->all(),
        ]);
    }
}
