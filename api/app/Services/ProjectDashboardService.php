<?php

namespace App\Services;

use App\Models\Application;
use App\Models\BackupJob;
use App\Models\Container;
use App\Models\Project;
use App\Models\Server;

/**
 * Project → Overview: one call for the dashboard. Counts (applications, containers, nodes, backups),
 * an overall health, and the rows behind the Applications, Containers and Resources tabs.
 */
class ProjectDashboardService
{
    public function __construct(private Scheduler $scheduler) {}

    public function build(Project $project, bool $customer = false): array
    {
        $apps = Application::allTenants()->where('project_id', $project->id)
            ->when($customer, fn ($q) => $q->where('kind', 'customer'))->orderBy('name')->get();
        $containers = $apps->isEmpty() ? collect() : Container::allTenants()->whereIn('application_id', $apps->pluck('id'))->orderBy('name')->get();
        $servers = Server::allTenants()->whereIn('id', $apps->pluck('server_id')->filter()->unique())->get()->keyBy('id');
        $backups = BackupJob::allTenants()->where('target_type', 'project')->where('project_id', $project->id)->latest()->get(['id', 'status', 'trigger', 'created_at']);

        $rows = $apps->map(function (Application $a) use ($containers, $servers) {
            $mine = $containers->where('application_id', $a->id);
            $running = $mine->where('state', 'running')->count();

            return [
                'id' => $a->id, 'name' => $a->name, 'status' => $a->status, 'environment' => $a->environment, 'image' => $a->image,
                'server' => $a->server_id ? ['id' => $a->server_id, 'name' => $servers[$a->server_id]->name ?? null, 'status' => $servers[$a->server_id]->status ?? null] : null,
                'containers' => $mine->count(), 'running' => $running, 'unhealthy' => $mine->where('health', 'unhealthy')->count(),
                'cpu_limit' => $this->scheduler->vcpuFor($a), 'memory_limit_mb' => $this->scheduler->ramFor($a), 'replicas' => max(1, (int) ($a->replicas ?? 1)),
                'cpu_pct' => round((float) $mine->sum('cpu_pct'), 1), 'mem_mb' => (int) round((float) $mine->sum('mem_mb')),
                'last_deployed_at' => $a->last_deployed_at,
            ];
        })->values();

        $problems = $rows->filter(fn ($r) => in_array($r['status'], ['failed', 'crashed', 'error'], true) || $r['unhealthy'] > 0
            || ($r['containers'] > 0 && $r['running'] < $r['containers']) || in_array($r['server']['status'] ?? null, ['offline'], true))->count();
        $lastBackup = $backups->first();

        return [
            'project' => ['id' => $project->id, 'name' => $project->name, 'description' => $project->description],
            'health' => $apps->isEmpty() ? 'empty' : ($problems === 0 ? 'healthy' : ($problems < $apps->count() ? 'degraded' : 'down')),
            'counts' => [
                'applications' => $apps->count(), 'containers' => $containers->count(), 'running' => $containers->where('state', 'running')->count(),
                'nodes' => $servers->count(), 'backups' => $backups->count(),
            ],
            'resources' => [
                'cpu_reserved' => round((float) $rows->sum(fn ($r) => $r['cpu_limit'] * $r['replicas']), 2),
                'memory_reserved_mb' => (int) $rows->sum(fn ($r) => $r['memory_limit_mb'] * $r['replicas']),
                'cpu_pct' => round((float) $rows->sum('cpu_pct'), 1), 'mem_mb' => (int) $rows->sum('mem_mb'),
            ],
            'last_backup' => $lastBackup ? ['id' => $lastBackup->id, 'status' => $lastBackup->status, 'type' => $lastBackup->trigger, 'created_at' => $lastBackup->created_at] : null,
            'applications' => $rows,
            'containers' => $containers->map(fn (Container $c) => [
                'id' => $c->id, 'name' => $c->name, 'image' => $c->image, 'state' => $c->state, 'health' => $c->health,
                'cpu_pct' => $c->cpu_pct, 'mem_mb' => $c->mem_mb, 'application' => $apps->firstWhere('id', $c->application_id)?->name,
                'server' => $servers[$c->server_id]->name ?? null,
            ])->values(),
            'nodes' => $servers->map(fn (Server $s) => ['id' => $s->id, 'name' => $s->name, 'status' => $s->status, 'cpu_pct' => $s->cpu_pct, 'ram_pct' => $s->ram_pct,
                'applications' => $apps->where('server_id', $s->id)->count()])->values(),
        ];
    }
}
