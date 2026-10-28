<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Application;
use App\Models\Server;

/**
 * Placement (UHOST-004): picks a healthy server by region, free capacity and
 * scheduling weight. Candidates are the organisation's own servers and the
 * shared customer pool. Maintenance and offline servers are never chosen.
 */
class Scheduler
{
    public function place(Application $app, int $ramMb): Server
    {
        $candidates = Server::allTenants()
            ->where(fn ($q) => $q->where('organization_id', $app->organization_id)->orWhere('shared_pool', true))
            ->where('maintenance', false)
            ->whereNotNull('last_heartbeat_at')
            ->where('last_heartbeat_at', '>=', now()->subSeconds(config('platform.offline_after_seconds')))
            ->get()
            ->filter(fn (Server $s) => $s->status === 'online' && $s->hasRole('app')); // builder-only nodes never run workloads

        if ($app->region) {
            $inRegion = $candidates->where('region', $app->region);
            if ($inRegion->isEmpty()) {
                throw ApiException::conflict("No healthy capacity is available in region {$app->region} right now. Try another region or retry shortly.", ['reason' => 'no_capacity']);
            }
            $candidates = $inRegion;
        }

        $scored = $candidates->map(function (Server $s) {
            $reserved = (int) Application::allTenants()->where('server_id', $s->id)->get()->sum(fn ($a) => $this->ramFor($a));
            $free = ($s->ram_mb ?? 0) * (1 - ($s->ram_pct ?? 0) / 100) - $reserved;

            return ['server' => $s, 'free' => $free, 'score' => $free * max(1, $s->scheduling_weight) / 100 * (1 - ($s->cpu_pct ?? 0) / 100)];
        })->filter(fn ($c) => $c['free'] >= $ramMb);

        if ($scored->isEmpty()) {
            throw ApiException::conflict('No server has enough free capacity for this app right now. Retry shortly or choose a smaller size.', ['reason' => 'no_capacity']);
        }

        return $scored->sortByDesc('score')->first()['server'];
    }

    public function ramFor(Application $app): int
    {
        return (int) (\App\Models\Plan::where('code', $app->size)->value('ram_mb') ?? 512);
    }
}
