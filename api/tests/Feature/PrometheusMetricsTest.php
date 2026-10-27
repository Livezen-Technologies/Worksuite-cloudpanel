<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Container;
use App\Support\Tenant;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** MON-001 / SRS 2.2 with METRICS_DRIVER=prometheus. */
class PrometheusMetricsTest extends TestCase
{
    public function test_metrics_and_requests_come_from_prometheus(): void
    {
        config(['platform.drivers.metrics' => 'prometheus', 'platform.prometheus.url' => 'http://prom.test:9090']);
        $t = now()->startOfMinute()->getTimestamp();
        Http::fake([
            'prom.test:9090/api/v1/query_range*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => [['metric' => [], 'values' => [[$t - 60, '41.5'], [$t, '43.25']]]]]]),
            'prom.test:9090/api/v1/query?*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [['metric' => [], 'value' => [$t, '1234.6']]]]]),
        ]);
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        $m = $this->api($token)->getJson("/api/v1/metrics?server_id={$server->id}&range=1h")->assertOk()->json('data');
        $this->assertSame('prometheus', $m['source']);
        $this->assertSame(43.25, $m['series']['cpu'][1]['v']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'query_range') && str_contains(urldecode($r->url()), 'node_cpu_seconds_total{mode="idle",server_id="'.$server->id.'"}'));

        $app = app(Tenant::class)->as($org->id, function () use ($server) {
            $app = Application::create(['name' => 'shop', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'size' => 'small', 'server_id' => $server->id]);
            Container::create(['server_id' => $server->id, 'name' => 'app-shop', 'image' => 'nginx', 'state' => 'running', 'application_id' => $app->id]);

            return $app;
        });
        $this->api($token)->getJson("/api/v1/app/apps/{$app->id}")->assertOk()->assertJsonPath('data.requests_24h', 1235);
        Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'traefik_service_requests_total{service=~"app-shop@.*"}[24h]'));
        $this->api($token)->getJson("/api/v1/app/apps/{$app->id}/metrics?range=1h")->assertOk()->assertJsonPath('data.source', 'prometheus')->assertJsonPath('data.ram.0.v', 41.5);
    }

    public function test_database_driver_is_default(): void
    {
        Http::fake();
        [, $org, $token] = $this->org();
        $this->api($token)->getJson('/api/v1/metrics?range=1h')->assertOk()->assertJsonMissingPath('data.source');
        Http::assertNothingSent();
    }
}
