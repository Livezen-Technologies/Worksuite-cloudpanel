<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Application;
use App\Models\Container;
use App\Models\Plan;
use App\Models\Server;

/**
 * Placement (UHOST-004): capacity-aware. A node is eligible only when it is online, not in
 * maintenance or deploy-locked, open to automatic placement, under its CPU/RAM/disk usage
 * thresholds and its container limit, and has enough *reserved* capacity left: the CPU and RAM
 * of every app already placed on it are subtracted from what the node may allocate, so a burst
 * of deploys cannot all land on the node that happens to look idle. Among eligible nodes the one
 * with the most headroom (weighted by scheduling_weight) wins. When none fits, placement is
 * refused with the reason for each node, never forced.
 */
class Scheduler
{
    public function place(Application $app, int $ramMb, ?float $vcpu = null): Server
    {
        $vcpu ??= $this->vcpuFor($app);
        $candidates = Server::allTenants()
            ->where(fn ($q) => $q->where('organization_id', $app->organization_id)->orWhere('shared_pool', true))
            ->get()
            ->filter(fn (Server $s) => $s->hasRole('app')); // builder-only nodes never run workloads

        if ($app->region) {
            $candidates = $candidates->where('region', $app->region);
            if ($candidates->isEmpty()) {
                throw ApiException::conflict("No healthy capacity is available in region {$app->region} right now. Try another region or retry shortly.", ['reason' => 'no_capacity']);
            }
        }

        $rejected = [];
        $scored = [];
        foreach ($candidates as $s) {
            $c = $this->capacity($s);
            $why = $this->ineligible($s, $c, $ramMb, $vcpu);
            if ($why) {
                $rejected[] = "{$s->name}: {$why}";
                continue;
            }
            $scored[] = ['server' => $s, 'score' => ($c['free_ram_mb'] - $ramMb) / max(1, $c['allocatable_ram_mb'])
                + ($c['free_cpu'] - $vcpu) / max(0.1, $c['allocatable_cpu']) + max(1, $s->scheduling_weight) / 1000];
        }

        if (! $scored) {
            throw ApiException::conflict('No server has enough free capacity for this app right now ('.($rejected ? implode('; ', array_slice($rejected, 0, 6)) : 'no servers').'). Retry shortly, choose a smaller size or add a node.', ['reason' => 'no_capacity', 'nodes' => $rejected]);
        }

        return collect($scored)->sortByDesc('score')->first()['server'];
    }

    /** Why a node cannot take this workload, or null when it can. */
    public function ineligible(Server $s, array $c, int $ramMb, float $vcpu, bool $pinned = false): ?string
    {
        return match (true) {
            $s->maintenance => 'in maintenance',
            (bool) $s->deploy_lock => 'deployments are locked',
            ! $pinned && ! ($s->auto_deploy ?? true) => 'excluded from automatic placement',
            in_array($s->status, ['offline', 'pending'], true) => $s->status,
            (bool) $s->docker_error => 'Docker is not healthy',
            $c['max_containers'] !== null && $c['containers'] >= $c['max_containers'] => "container limit reached ({$c['containers']}/{$c['max_containers']})",
            ($s->cpu_pct ?? 0) > $c['max_cpu_pct'] => 'CPU above '.$c['max_cpu_pct'].'%',
            ($s->ram_pct ?? 0) > $c['max_ram_pct'] => 'RAM above '.$c['max_ram_pct'].'%',
            ($s->disk_pct ?? 0) > $c['max_disk_pct'] => 'disk above '.$c['max_disk_pct'].'%',
            $c['free_disk_gb'] !== null && $c['free_disk_gb'] <= 0 => 'no free disk beyond its reserve',
            $c['free_ram_mb'] < $ramMb => 'not enough free RAM ('.max(0, (int) $c['free_ram_mb'])." MB free, {$ramMb} MB needed)",
            $c['free_cpu'] < $vcpu => 'not enough free CPU ('.max(0, round($c['free_cpu'], 1))." cores free, {$vcpu} needed)",
            default => null,
        };
    }

    /**
     * What a node may allocate and what is already reserved on it. RAM in use is the larger of what
     * the agent measures and what placed apps reserve, so neither is counted twice.
     *
     * @return array{allocatable_cpu: float, reserved_by_apps_cpu: float, free_cpu: float, allocatable_ram_mb: int, reserved_by_apps_ram_mb: int, free_ram_mb: float, free_disk_gb: ?float, containers: int, max_containers: ?int, max_cpu_pct: int, max_ram_pct: int, max_disk_pct: int}
     */
    public function capacity(Server $s): array
    {
        $apps = Application::allTenants()->where('server_id', $s->id)->get();
        $appRam = (int) $apps->sum(fn ($a) => $this->ramFor($a) * max(1, (int) ($a->replicas ?? 1)));
        $appCpu = (float) $apps->sum(fn ($a) => $this->vcpuFor($a) * max(1, (int) ($a->replicas ?? 1)));
        $allocRam = max(0, (int) ($s->ram_mb ?? 0) - (int) ($s->reserved_ram_mb ?? 0));
        $allocCpu = max(0.0, (float) ($s->cpu_cores ?? 0) * (float) ($s->cpu_overcommit ?: 1) - (float) ($s->reserved_cpu ?? 0));
        $measuredRam = (int) round(($s->ram_mb ?? 0) * ($s->ram_pct ?? 0) / 100);
        $freeDisk = $s->disk_gb ? $s->disk_gb * (1 - ($s->disk_pct ?? 0) / 100) - (int) ($s->reserved_disk_gb ?? 0) : null;

        return [
            'allocatable_cpu' => round($allocCpu, 2), 'reserved_by_apps_cpu' => round($appCpu, 2), 'free_cpu' => round($allocCpu - $appCpu, 2),
            'allocatable_ram_mb' => $allocRam, 'reserved_by_apps_ram_mb' => $appRam, 'free_ram_mb' => $allocRam - max($appRam, $measuredRam),
            'free_disk_gb' => $freeDisk === null ? null : round($freeDisk, 1),
            'containers' => Container::allTenants()->where('server_id', $s->id)->count(), 'max_containers' => $s->max_containers,
            'max_cpu_pct' => (int) ($s->max_cpu_pct ?? config('platform.placement.max_cpu_pct', 90)),
            'max_ram_pct' => (int) ($s->max_ram_pct ?? config('platform.placement.max_ram_pct', 90)),
            'max_disk_pct' => (int) ($s->max_disk_pct ?? config('platform.placement.max_disk_pct', 85)),
        ];
    }

    public function ramFor(Application $app): int
    {
        return (int) (Plan::where('code', $app->size)->value('ram_mb') ?? 512);
    }

    public function vcpuFor(Application $app): float
    {
        return (float) (Plan::where('code', $app->size)->value('vcpu') ?? 0.5);
    }
}
