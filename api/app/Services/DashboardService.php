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
                // Average health across the fleet, for the "Avg health" tile.
                'health_avg' => $servers->isEmpty() ? null : (int) round($servers->avg(fn (Server $s) => $s->health_score)),
                'disk_pct' => round((float) $online->avg('disk_pct'), 1),
                'deployments_24h' => Deployment::where('created_at', '>=', now()->subDay())->count(),
                'deployments_failed_24h' => Deployment::where('created_at', '>=', now()->subDay())->where('status', 'failed')->count(),
                'jobs_pending' => \App\Models\Job::whereIn('status', ['queued', 'running'])->count(),
            ],
            // Fleet health: the donut counts by state, and the health-score
            // distribution underneath it.
            'fleet' => $this->fleet($servers),
            // Servers worth looking at, lowest health first. Healthy servers are
            // left out entirely rather than padding the list.
            'attention' => $this->attention($servers),
            'series' => ['range' => $series['range'], 'cpu' => $series['series']['cpu'], 'ram' => $series['series']['ram']],
            'alerts' => Alert::whereIn('status', ['open', 'acknowledged'])->orderByRaw("case severity when 'emergency' then 0 when 'critical' then 1 when 'warning' then 2 else 3 end")->latest()->limit(10)
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

    /** The five buckets the design shows, plus the state counts for the donut. */
    private function fleet($servers): array
    {
        $buckets = ['0-39' => 0, '40-59' => 0, '60-79' => 0, '80-89' => 0, '90-100' => 0];
        foreach ($servers as $server) {
            $score = $server->health_score;
            $key = match (true) {
                $score < 40 => '0-39',
                $score < 60 => '40-59',
                $score < 80 => '60-79',
                $score < 90 => '80-89',
                default => '90-100',
            };
            $buckets[$key]++;
        }
        $total = $servers->count();
        $states = $servers->countBy('status');

        return [
            'total' => $total,
            // Explicit zeroes: the donut needs every state present, not only
            // the ones that happen to occur.
            'states' => collect(['online', 'warning', 'maintenance', 'offline', 'pending'])
                ->mapWithKeys(fn (string $state) => [$state => (int) ($states[$state] ?? 0)])->all(),
            'online_pct' => $total ? (int) round($servers->filter->isOnline()->count() / $total * 100) : 0,
            'distribution' => $buckets,
        ];
    }

    /** @return array<int, array> servers below full health, worst first. */
    private function attention($servers): array
    {
        return $servers->filter(fn (Server $s) => $s->health_score < 100 || $s->status === 'maintenance')
            ->sortBy(fn (Server $s) => $s->health_score)
            ->take(6)
            ->map(fn (Server $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'score' => $s->health_score,
                'state' => $s->status,
                'ip' => $s->ip,
                'provider' => $s->provider,
                'region' => $s->region,
                'reasons' => $s->healthReasons(),
            ])->values()->all();
    }
}
