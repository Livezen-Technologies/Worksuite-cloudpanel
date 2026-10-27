<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\BackupJob;
use App\Models\Certificate;
use App\Models\Container;
use App\Models\Deployment;
use App\Models\Image;
use App\Models\Server;

/** DASH-001..006. */
class DashboardService
{
    public function __construct(private MetricsService $metrics) {}

    public function build(string $range): array
    {
        $servers = Server::withCount('containers')->get();
        $online = $servers->filter->isOnline();
        $series = $this->metrics->series(null, $range);
        $images = Image::all();

        return [
            'kpis' => [
                'servers_online' => $online->count(),
                'servers_total' => $servers->count(),
                'containers_running' => Container::where('state', 'running')->count(),
                'containers_total' => Container::count(),
                'cpu_pct' => round((float) $online->avg('cpu_pct'), 1),
                'ram_pct' => round((float) $online->avg('ram_pct'), 1),
                'alerts_open' => Alert::where('status', 'open')->count(),
            ],
            'series' => ['range' => $series['range'], 'cpu' => $series['series']['cpu'], 'ram' => $series['series']['ram']],
            'alerts' => Alert::whereIn('status', ['open', 'acknowledged'])->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end")->latest()->limit(10)
                ->get(['id', 'severity', 'title', 'status', 'resource_type', 'resource_id', 'created_at']),
            'deployments' => Deployment::with(['application:id,name,source_type', 'user:id,name,email'])->latest()->limit(10)->get()->map(fn ($d) => [
                'id' => $d->id, 'application_id' => $d->application_id, 'application' => $d->application?->name, 'status' => $d->status,
                'source' => $d->application?->source_type, 'trigger' => $d->source, 'author' => $d->user?->name ?? 'system',
                'job_id' => $d->job_id, 'created_at' => $d->created_at?->toIso8601ZuluString(), 'finished_at' => $d->finished_at?->toIso8601ZuluString(),
            ]),
            'top' => Container::where('state', 'running')->orderByDesc('cpu_pct')->limit(5)->get(['id', 'name', 'server_id', 'cpu_pct', 'mem_mb']),
            'status' => [
                'servers' => $servers->countBy('status'),
                'backups_24h' => [
                    'succeeded' => BackupJob::where('created_at', '>=', now()->subDay())->where('status', 'succeeded')->count(),
                    'failed' => BackupJob::where('created_at', '>=', now()->subDay())->where('status', 'failed')->count(),
                    'running' => BackupJob::where('created_at', '>=', now()->subDay())->where('status', 'running')->count(),
                ],
                'certificates_expiring' => Certificate::where('status', 'active')->where('expires_at', '<', now()->addDays(14))->count(),
                'image_scans' => [
                    'scanned' => $images->where('scan_status', 'scanned')->count(),
                    'critical' => $images->sum(fn ($i) => $i->scan_result['critical'] ?? 0),
                    'high' => $images->sum(fn ($i) => $i->scan_result['high'] ?? 0),
                    'not_scanned' => $images->where('scan_status', 'not_scanned')->count(),
                ],
            ],
            'generated_at' => now()->toIso8601ZuluString(),
        ];
    }
}
