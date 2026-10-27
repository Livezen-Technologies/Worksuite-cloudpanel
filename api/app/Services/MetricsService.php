<?php

namespace App\Services;

use App\Models\Metric;
use App\Support\Tenant;
use Illuminate\Support\Carbon;

/** Fleet / server series from heartbeat stats (metrics table, 1.0 build). */
class MetricsService
{
    public const RANGES = ['1h' => [60, 60], '6h' => [360, 300], '24h' => [1440, 900], '7d' => [10080, 3600]];

    public static function since(string $range): Carbon
    {
        return now()->subMinutes(self::RANGES[$range][0] ?? 60);
    }

    /** @return array{range: string, step_seconds: int, series: array{cpu: array, ram: array, disk: array, net_rx: array, net_tx: array}} */
    public function series(?string $serverId, string $range): array
    {
        $range = isset(self::RANGES[$range]) ? $range : '1h';
        [, $step] = self::RANGES[$range];
        if (\App\Support\PrometheusMetrics::enabled()) {
            return $this->prometheusSeries($serverId, $range, $step);
        }
        $rows = Metric::where('organization_id', Tenant::current()?->id)->whereNull('container_id')
            ->when($serverId, fn ($q) => $q->where('server_id', $serverId))
            ->where('recorded_at', '>=', self::since($range))->orderBy('recorded_at')->get();

        $buckets = [];
        foreach ($rows as $row) {
            $bucket = intdiv($row->recorded_at->getTimestamp(), $step) * $step;
            $buckets[$bucket][] = $row;
        }
        $series = ['cpu' => [], 'ram' => [], 'disk' => [], 'net_rx' => [], 'net_tx' => []];
        foreach ($buckets as $ts => $items) {
            $t = Carbon::createFromTimestamp($ts)->toIso8601ZuluString();
            $c = collect($items);
            $series['cpu'][] = ['t' => $t, 'v' => round((float) $c->avg('cpu_pct'), 2)];
            $series['ram'][] = ['t' => $t, 'v' => round((float) $c->avg('ram_pct'), 2)];
            $series['disk'][] = ['t' => $t, 'v' => round((float) $c->avg('disk_pct'), 2)];
            $series['net_rx'][] = ['t' => $t, 'v' => (int) $c->max('net_rx')];
            $series['net_tx'][] = ['t' => $t, 'v' => (int) $c->max('net_tx')];
        }

        return ['range' => $range, 'server_id' => $serverId, 'step_seconds' => $step, 'series' => $series];
    }

    private function prometheusSeries(?string $serverId, string $range, int $step): array
    {
        $ids = $serverId ? [$serverId] : \App\Models\Server::pluck('id')->all();
        $prom = app(\App\Support\PrometheusMetrics::class);
        $series = [];
        foreach (\App\Support\PrometheusMetrics::serverQueries(\App\Support\PrometheusMetrics::selector($ids)) as $key => $query) {
            $series[$key] = $prom->range($query, self::since($range), now(), $step);
        }

        return ['range' => $range, 'server_id' => $serverId, 'step_seconds' => $step, 'series' => $series, 'source' => 'prometheus'];
    }

    public function average(string $serverId, string $column, int $minutes): ?float
    {
        $q = Metric::where('server_id', $serverId)->whereNull('container_id')->where('recorded_at', '>=', now()->subMinutes(max(1, $minutes)));

        return $q->count() ? (float) $q->avg($column) : null;
    }

    public function pruneRaw(int $days = 15): int
    {
        return Metric::where('recorded_at', '<', now()->subDays($days))->delete();
    }
}
