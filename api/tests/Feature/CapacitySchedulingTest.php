<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Application;
use App\Models\Container;
use App\Models\Plan;
use App\Services\Scheduler;
use App\Support\Tenant;
use Tests\TestCase;

/** Capacity-aware placement: reservations, container limits, thresholds, eligibility and locks. */
class CapacitySchedulingTest extends TestCase
{
    private function app($org, array $attrs = []): Application
    {
        return app(Tenant::class)->as($org->id, fn () => Application::create($attrs + ['name' => 'a'.uniqid(), 'size' => 'standard', 'source_type' => 'image', 'image' => 'nginx', 'port' => 80]));
    }

    private function sched(): Scheduler
    {
        return app(Scheduler::class);
    }

    private function plans(): void
    {
        Plan::query()->updateOrCreate(['code' => 'standard'], ['name' => 'Standard', 'ram_mb' => 4096, 'vcpu' => 2]);
    }

    public function test_reserved_cpu_decides_even_when_the_node_looks_idle(): void
    {
        $this->plans();
        [, $org] = $this->org();
        // 4 cores, nearly idle right now, but two 2-vCPU apps already reserve all of it.
        $busy = $this->server($org, ['name' => 'busy', 'cpu_cores' => 4, 'ram_mb' => 65536, 'cpu_pct' => 1, 'ram_pct' => 1]);
        $this->app($org, ['server_id' => $busy->id]);
        $this->app($org, ['server_id' => $busy->id]);
        $quiet = $this->server($org, ['name' => 'quiet', 'cpu_cores' => 8, 'ram_mb' => 16384, 'cpu_pct' => 60, 'ram_pct' => 20]);

        $this->assertSame($quiet->id, $this->sched()->place($this->app($org), 4096)->id);
        $c = $this->sched()->capacity($busy);
        $this->assertSame(4.0, (float) $c['reserved_by_apps_cpu']);
        $this->assertSame(0.0, (float) $c['free_cpu']);
    }

    public function test_ram_is_not_counted_twice(): void
    {
        $this->plans();
        [, $org] = $this->org();
        // 16 GB, apps reserve 8 GB and the agent measures 50 % (the same 8 GB): 8 GB is free, not 0.
        $s = $this->server($org, ['cpu_cores' => 16, 'ram_mb' => 16384, 'ram_pct' => 50]);
        $this->app($org, ['server_id' => $s->id]);
        $this->app($org, ['server_id' => $s->id]);
        $this->assertSame(8192.0, (float) $this->sched()->capacity($s)['free_ram_mb']);
        $this->assertSame($s->id, $this->sched()->place($this->app($org), 4096)->id);
    }

    public function test_container_limit_threshold_reserve_and_eligibility_exclude_a_node(): void
    {
        $this->plans();
        [, $org] = $this->org();
        $full = $this->server($org, ['name' => 'full', 'max_containers' => 1]);
        app(Tenant::class)->as($org->id, fn () => Container::create(['server_id' => $full->id, 'name' => 'c1', 'image' => 'nginx', 'status' => 'running']));
        $hot = $this->server($org, ['name' => 'hot', 'cpu_pct' => 75, 'max_cpu_pct' => 70]);
        $reserved = $this->server($org, ['name' => 'reserved', 'reserved_ram_mb' => 14000]);
        $manual = $this->server($org, ['name' => 'manual', 'auto_deploy' => false]);
        $locked = $this->server($org, ['name' => 'locked', 'deploy_lock' => true]);

        try {
            $this->sched()->place($this->app($org), 4096);
            $this->fail('placement should be refused');
        } catch (ApiException $e) {
            $msg = $e->getMessage();
            foreach (['full: container limit reached (1/1)', 'hot: CPU above 70%', 'reserved: not enough free RAM', 'manual: excluded from automatic placement', 'locked: deployments are locked'] as $why) {
                $this->assertStringContainsString($why, $msg);
            }
        }

        $ok = $this->server($org, ['name' => 'ok']);
        $this->assertSame($ok->id, $this->sched()->place($this->app($org), 4096)->id);
    }

    public function test_capacity_settings_are_saved_and_shown(): void
    {
        [, $org, $token] = $this->org();
        $s = $this->server($org);
        $this->api($token)->patchJson("/api/v1/servers/{$s->id}", ['reserved_cpu' => 1, 'cpu_overcommit' => 2, 'max_ram_pct' => 80, 'auto_deploy' => false, 'deploy_lock' => true])->assertOk();
        $data = $this->api($token)->getJson("/api/v1/servers/{$s->id}")->assertOk()->json('data');
        $this->assertFalse($data['auto_deploy']);
        $this->assertTrue($data['deploy_lock']);
        $this->assertSame(15.0, (float) $data['capacity']['allocatable_cpu']);  // 8 cores x 2 - 1
        $this->assertSame(80, $data['capacity']['max_ram_pct']);
        $this->api($token)->patchJson("/api/v1/servers/{$s->id}", ['max_cpu_pct' => 5])->assertStatus(422);
    }
}
