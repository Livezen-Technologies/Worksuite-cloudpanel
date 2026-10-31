<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Container;
use App\Models\ScalingEvent;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** SCL-1..5, 9, 10 / USCL-1..3: copies, autoscaling, schedules, health checks and limits. */
class ScalingTest extends TestCase
{
    /** A deployed app with a domain; returns [org, token, secret, appId]. */
    private function deployed(array $attrs = []): array
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $appId = $this->api($token)->postJson('/api/v1/applications', $attrs + ['name' => 'shop', 'source_type' => 'image', 'image' => 'ghcr.io/acme/shop:1.0', 'server_id' => $server->id, 'port' => 8080])->assertCreated()->json('data.id');
        $this->api($token)->postJson('/api/v1/domains', ['name' => 'shop.acme.test', 'application_id' => $appId, 'target_port' => 8080])->assertCreated();
        $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->assertStatus(202);
        $create = $this->heartbeat($secret)['commands'][0];
        $this->commandResult($secret, $create['id'], 'succeeded', ['output' => ['container_id' => 'd-1']])->assertOk();
        $this->passHealthChecks($secret);
        $this->heartbeat($secret);

        return [$org, $token, $secret, $appId];
    }

    public function test_scaling_up_adds_balanced_copies_and_down_drains_them(): void
    {
        [, $token, $secret, $appId] = $this->deployed();
        $res = $this->api($token)->patchJson("/api/v1/applications/{$appId}/scaling", ['replicas' => 3, 'health' => ['path' => '/healthz']])->assertOk()->json('data');
        $this->assertSame(3, $res['replicas']);
        $cmds = $this->heartbeat($secret)['commands'];
        $this->assertSame(['app-shop-r2', 'app-shop-r3'], array_column(array_column($cmds, 'payload'), 'name'));
        $labels = $cmds[0]['payload']['labels'];
        $this->assertSame('8080', $labels['traefik.http.services.app-shop.loadbalancer.server.port'], 'the same service as the primary, so Traefik balances across them');
        $this->assertSame('/healthz', $labels['traefik.http.services.app-shop.loadbalancer.healthcheck.path']);

        $this->api($token)->patchJson("/api/v1/applications/{$appId}/scaling", ['replicas' => 2])->assertOk();
        $down = $this->heartbeat($secret)['commands'];
        $this->assertSame([['container.stop', 'app-shop-r3', 30], ['container.remove', 'app-shop-r3', null]],
            array_map(fn ($c) => [$c['type'], $c['payload']['container_id'], $c['payload']['timeout'] ?? null], $down));
        $this->assertSame([[1, 3, 'manual'], [3, 2, 'manual']], ScalingEvent::allTenants()->orderBy('id')->get()->map(fn ($e) => [$e->from, $e->to, $e->reason])->all());
    }

    public function test_a_new_release_replaces_each_copy_and_sticky_sessions_reach_traefik(): void
    {
        [, $token, $secret, $appId] = $this->deployed();
        $this->api($token)->patchJson("/api/v1/applications/{$appId}/scaling", ['replicas' => 2, 'sticky' => true])->assertOk();
        $this->heartbeat($secret);
        $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->assertStatus(202);
        foreach ($this->heartbeat($secret)['commands'] as $c) {
            $this->commandResult($secret, $c['id'], 'succeeded', ['output' => ['container_id' => 'd-2']])->assertOk();
        }
        $this->passHealthChecks($secret);
        $after = collect($this->heartbeat($secret)['commands'])->filter(fn ($c) => str_contains($c['payload']['container_id'] ?? $c['payload']['name'] ?? '', '-r2'));
        $this->assertSame(['container.remove', 'container.create'], $after->pluck('type')->values()->all());
        $this->assertSame('ws_affinity', $after->last()['payload']['labels']['traefik.http.services.app-shop.loadbalancer.sticky.cookie.name']);
    }

    public function test_autoscale_adds_a_copy_after_three_hot_minutes_and_stops_at_the_maximum(): void
    {
        Carbon::setTestNow('2026-11-02 10:00:00');
        [$org, $token, $secret, $appId] = $this->deployed();
        $this->api($token)->patchJson("/api/v1/applications/{$appId}/scaling", ['mode' => 'autoscale', 'min' => 1, 'max' => 2, 'cpu_target' => 70])->assertOk();
        Container::allTenants()->where('application_id', $appId)->update(['cpu_pct' => 92, 'state' => 'running']);

        $this->artisan('scaling:evaluate')->assertSuccessful();
        $this->assertSame(1, Application::allTenants()->find($appId)->replicas, 'one hot minute is not enough');
        Carbon::setTestNow('2026-11-02 10:03:30');
        $this->heartbeat($secret);
        $this->artisan('scaling:evaluate')->assertSuccessful();
        $this->assertSame(2, Application::allTenants()->find($appId)->replicas);
        $this->assertStringStartsWith('autoscale: CPU 92% > 70%', ScalingEvent::allTenants()->latest('id')->value('reason'));

        Container::allTenants()->where('application_id', $appId)->update(['cpu_pct' => 95, 'state' => 'running']);
        Carbon::setTestNow('2026-11-02 10:04:00');
        $this->artisan('scaling:evaluate');
        Carbon::setTestNow('2026-11-02 10:07:30');
        $this->heartbeat($secret);
        $this->artisan('scaling:evaluate');
        $this->assertSame(2, Application::allTenants()->find($appId)->replicas, 'never above the maximum');
        $this->assertSame('at_max', ScalingEvent::allTenants()->latest('id')->value('result'), 'hitting the maximum is recorded as a warning');
        Carbon::setTestNow();
    }

    public function test_scheduled_scaling_follows_its_windows(): void
    {
        Carbon::setTestNow('2026-11-02 10:00:00'); // Monday
        [, $token, $secret, $appId] = $this->deployed();
        $this->api($token)->patchJson("/api/v1/applications/{$appId}/scaling", ['mode' => 'scheduled', 'min' => 1, 'max' => 4,
            'schedule' => [['days' => [1, 2, 3, 4, 5], 'from' => '09:00', 'to' => '18:00', 'replicas' => 3]]])->assertOk();
        $this->artisan('scaling:evaluate');
        $this->assertSame(3, Application::allTenants()->find($appId)->replicas);
        Carbon::setTestNow('2026-11-02 19:00:00');
        $this->heartbeat($secret);
        $this->artisan('scaling:evaluate');
        $this->assertSame(1, Application::allTenants()->find($appId)->replicas);
        Carbon::setTestNow();
    }

    public function test_customers_scale_within_their_plan_and_see_shared_state_warnings(): void
    {
        [$org, $token, , $appId] = $this->deployed(['volume_path' => '/var/www/html/uploads']);
        Application::allTenants()->whereKey($appId)->update(['kind' => 'customer']);
        app(Tenant::class)->as($org->id, function () {
            $plan = app(\App\Services\BillingService::class)->plan();
            $plan->update(['limits' => array_merge($plan->limits ?? [], ['max_replicas' => 2, 'autoscale' => 0])]);
        });
        $page = $this->api($token)->getJson("/api/v1/app/apps/{$appId}/scaling")->assertOk()->json('data');
        $this->assertSame(['max_replicas' => 2, 'autoscale' => false, 'schedule' => false, 'placement' => false, 'canary' => false, 'routing' => false], $page['limits']);
        $this->assertNull($page['rps']);
        $this->assertNotEmpty(array_filter($page['warnings'], fn ($w) => str_contains($w, '/var/www/html/uploads')));

        $this->api($token)->patchJson("/api/v1/app/apps/{$appId}/scaling", ['replicas' => 3])->assertStatus(402);
        $this->api($token)->patchJson("/api/v1/app/apps/{$appId}/scaling", ['mode' => 'autoscale'])->assertStatus(402);
        $this->api($token)->patchJson("/api/v1/app/apps/{$appId}/scaling", ['replicas' => 2])->assertOk()->assertJsonPath('data.replicas', 2);

        [, , $other] = $this->org();
        $this->api($other)->getJson("/api/v1/app/apps/{$appId}/scaling")->assertNotFound();
    }

    public function test_customers_see_requests_and_copies_routing_is_read_only_and_splitting_needs_the_plan(): void
    {
        [$org, $token, , $appId] = $this->deployed();
        Application::allTenants()->whereKey($appId)->update(['kind' => 'customer']);
        config(['platform.drivers.metrics' => 'prometheus', 'platform.prometheus.url' => 'http://prom:9090']);
        \Illuminate\Support\Facades\Http::fake(fn () => \Illuminate\Support\Facades\Http::response(['status' => 'success', 'data' => ['result' => [['value' => [0, '42.5']]]]]));

        // USCL-1: the app list and the scaling page carry copies and requests/s.
        $row = collect($this->api($token)->getJson('/api/v1/app/apps')->assertOk()->json('data'))->firstWhere('id', $appId);
        $this->assertSame(1, $row['replicas']);
        $this->assertEquals(42.5, $row['rps']);
        $page = $this->api($token)->getJson("/api/v1/app/apps/{$appId}/scaling")->assertOk()->json('data');
        $this->assertEquals(42.5, $page['rps']);
        $this->assertArrayHasKey('health', $page['copies'][0]);

        // USCL-3: health checks, sessions and spreading are shown, not changed.
        foreach ([['sticky' => true], ['health' => ['path' => '/up']], ['strategy' => 'least_connections']] as $body) {
            $this->api($token)->patchJson("/api/v1/app/apps/{$appId}/scaling", $body)->assertStatus(422);
        }
        $this->api($token)->patchJson("/api/v1/app/apps/{$appId}/scaling", ['replicas' => 2])->assertOk();

        // Traffic splitting only on packages that include it.
        $this->api($token)->postJson("/api/v1/app/apps/{$appId}/canary", ['image' => 'acme/shop:2'])->assertForbidden();
        $this->api($token)->deleteJson("/api/v1/app/apps/{$appId}/canary")->assertStatus(409); // aborting stays open, so a downgrade can remove one
        app(Tenant::class)->as($org->id, function () {
            $plan = app(\App\Services\BillingService::class)->plan();
            $plan->update(['limits' => array_merge($plan->limits ?? [], ['traffic_splitting' => 1])]);
        });
        $this->assertTrue($this->api($token)->getJson("/api/v1/app/apps/{$appId}/scaling")->json('data.limits.canary'));
    }

    public function test_autoscale_on_requests_per_second_per_copy_from_traefik_metrics(): void
    {
        Carbon::setTestNow('2026-11-02 10:00:00');
        [$org, $token, $secret, $appId] = $this->deployed();
        $this->api($token)->patchJson("/api/v1/applications/{$appId}/scaling", ['mode' => 'autoscale', 'metric' => 'rps', 'rps_target' => 40])->assertStatus(422)->assertSee('METRICS_DRIVER');

        config(['platform.drivers.metrics' => 'prometheus', 'platform.prometheus.url' => 'http://prom:9090']);
        $rps = 120.0;
        $queries = [];
        \Illuminate\Support\Facades\Http::fake(function ($r) use (&$rps, &$queries) {
            $queries[] = $r['query'];

            return \Illuminate\Support\Facades\Http::response(['status' => 'success', 'data' => ['result' => [['value' => [0, (string) $rps]]]]]);
        });
        $this->api($token)->patchJson("/api/v1/applications/{$appId}/scaling", ['mode' => 'autoscale', 'min' => 1, 'max' => 3, 'metric' => 'rps', 'rps_target' => 40])->assertOk();

        $this->artisan('scaling:evaluate')->assertSuccessful();
        Carbon::setTestNow('2026-11-02 10:03:30');
        $this->heartbeat($secret);
        $this->artisan('scaling:evaluate')->assertSuccessful();
        $this->assertSame(2, Application::allTenants()->find($appId)->replicas);
        $this->assertSame('autoscale: 120 requests/s per copy > 40 for 3 min', ScalingEvent::allTenants()->latest('id')->value('reason'));
        $this->assertStringContainsString('rate(traefik_service_requests_total{service=~"app-shop(-pool)?@.*"}[3m])', $queries[0]);

        // 30 req/s over two copies is 15 each, under half the target: one copy goes after 15 minutes.
        $rps = 30.0;
        Carbon::setTestNow('2026-11-02 10:04:00');
        $this->artisan('scaling:evaluate');
        Carbon::setTestNow('2026-11-02 10:19:30');
        $this->heartbeat($secret);
        $this->artisan('scaling:evaluate');
        $this->assertSame(1, Application::allTenants()->find($appId)->replicas);
        Carbon::setTestNow();
    }
}
