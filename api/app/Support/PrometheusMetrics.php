<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * METRICS_DRIVER=prometheus (MON-001, SRS 2.2): series come from Prometheus' HTTP API
 * (query_range) over node-exporter, cAdvisor and Traefik metrics scraped on each server.
 * Targets carry a `server_id` label (docs/deploy/observability/prometheus.yml).
 * The agent stays outbound-only; Prometheus scrapes the exporters, not the agent.
 */
class PrometheusMetrics
{
    public static function enabled(): bool
    {
        return config('platform.drivers.metrics') === 'prometheus';
    }

    private static function label(): string
    {
        return config('platform.prometheus.instance_label');
    }

    /** Label matcher for one server or a set of servers. */
    public static function selector(array $serverIds): string
    {
        $ids = array_map(fn ($id) => preg_replace('/[^a-zA-Z0-9-]/', '', $id), $serverIds);

        return count($ids) === 1 ? self::label().'="'.$ids[0].'"' : self::label().'=~"'.(implode('|', $ids) ?: '__none__').'"';
    }

    public static function serverQueries(string $sel): array
    {
        $net = 'device!~"lo|veth.*|docker.*|br-.*"';

        return [
            'cpu' => "100 * (1 - avg(rate(node_cpu_seconds_total{mode=\"idle\",{$sel}}[5m])))",
            'ram' => "100 * (1 - sum(node_memory_MemAvailable_bytes{{$sel}}) / sum(node_memory_MemTotal_bytes{{$sel}}))",
            'disk' => "100 * (1 - sum(node_filesystem_avail_bytes{mountpoint=\"/\",{$sel}}) / sum(node_filesystem_size_bytes{mountpoint=\"/\",{$sel}}))",
            'net_rx' => "sum(node_network_receive_bytes_total{{$net},{$sel}})",
            'net_tx' => "sum(node_network_transmit_bytes_total{{$net},{$sel}})",
        ];
    }

    public static function containerQueries(string $container): array
    {
        $name = preg_replace('/[^a-zA-Z0-9_.-]/', '', $container);

        return [
            'cpu' => "sum(rate(container_cpu_usage_seconds_total{name=\"{$name}\"}[5m])) * 100",
            'ram' => "sum(container_memory_working_set_bytes{name=\"{$name}\"}) / 1048576",
        ];
    }

    /** @return array<array{t: string, v: float}> */
    public function range(string $query, Carbon $start, Carbon $end, int $step): array
    {
        $response = $this->get('/api/v1/query_range', ['query' => $query, 'start' => $start->getTimestamp(), 'end' => $end->getTimestamp(), 'step' => $step]);
        $values = $response['data']['result'][0]['values'] ?? [];

        return array_map(fn ($p) => ['t' => Carbon::createFromTimestamp((int) $p[0])->toIso8601ZuluString(), 'v' => round((float) $p[1], 2)], $values);
    }

    public function instant(string $query): ?float
    {
        $result = $this->get('/api/v1/query', ['query' => $query])['data']['result'][0]['value'][1] ?? null;

        return $result === null ? null : (float) $result;
    }

    /** Requests served by an app's Traefik service in the last 24 h. */
    public function requests24h(string $appName): ?int
    {
        $service = 'app-'.preg_replace('/[^a-z0-9-]/', '-', strtolower($appName));
        $v = $this->instant("sum(increase(traefik_service_requests_total{service=~\"{$service}@.*\"}[24h]))");

        return $v === null ? null : (int) round($v);
    }

    /** SCL-2: requests per second over the last 3 minutes across an app's services (copies and pool). */
    public function requestsPerSecond(string $appName): ?float
    {
        $service = 'app-'.preg_replace('/[^a-z0-9-]/', '-', strtolower($appName));

        return $this->instant("sum(rate(traefik_service_requests_total{service=~\"{$service}(-pool)?@.*\"}[3m]))");
    }

    private function get(string $path, array $params): array
    {
        $req = Http::timeout(config('platform.prometheus.timeout'))->acceptJson();
        if ($token = config('platform.prometheus.token')) {
            $req = $req->withToken($token);
        }
        $response = $req->get(rtrim(config('platform.prometheus.url'), '/').$path, $params);
        $response->throw();

        return $response->json() ?? [];
    }
}
