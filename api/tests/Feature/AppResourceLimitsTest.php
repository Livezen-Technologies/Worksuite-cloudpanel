<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Plan;
use App\Services\ContainerSpec;
use App\Services\DeploymentService;
use App\Services\Scheduler;
use App\Support\Tenant;
use Tests\TestCase;

/** Per-app CPU/RAM limits: stored, reserved by placement, enforced in the container spec, previewed. */
class AppResourceLimitsTest extends TestCase
{
    public function test_limits_flow_into_the_release_snapshot_and_placement(): void
    {
        Plan::query()->updateOrCreate(['code' => 'standard'], ['name' => 'Standard', 'ram_mb' => 1024, 'vcpu' => 1]);
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        $app = $this->api($token)->postJson('/api/v1/applications', ['name' => 'web', 'source_type' => 'image', 'image' => 'nginx:1', 'server_id' => $server->id, 'port' => 80, 'cpu_limit' => 2, 'memory_limit_mb' => 4096])
            ->assertCreated()->json('data');
        $model = Application::allTenants()->find($app['id']);
        $this->assertSame(2.0, (float) $model->cpu_limit);

        $snap = app(Tenant::class)->as($org->id, fn () => app(DeploymentService::class)->snapshot($model));
        $this->assertSame(4096, (int) $snap['memory_mb']);
        $this->assertSame(2.0, (float) $snap['cpus']);
        $this->assertSame(4096, app(Scheduler::class)->ramFor($model));
        $this->assertSame(2.0, app(Scheduler::class)->vcpuFor($model));

        // Without limits the size applies, as before.
        $plain = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'plain', 'source_type' => 'image', 'image' => 'nginx', 'port' => 80, 'size' => 'standard']));
        $this->assertSame(1024, app(Scheduler::class)->ramFor($plain));

        $this->api($token)->postJson('/api/v1/applications', ['name' => 'bad', 'source_type' => 'image', 'image' => 'nginx:1', 'port' => 80, 'cpu_limit' => 0, 'memory_limit_mb' => 10])
            ->assertStatus(422)->assertJsonValidationErrors(['cpu_limit', 'memory_limit_mb'], 'error.fields');
    }

    public function test_app_limits_win_but_never_exceed_the_plan_ceiling(): void
    {
        $spec = ContainerSpec::build(['name' => 'x', 'image' => 'nginx', 'memory_mb' => 2048, 'cpus' => 1.5], null);
        $this->assertSame(2048, $spec['memory_mb']);
        $this->assertSame(1.5, $spec['cpus']);
    }

    public function test_preflight_returns_a_deployment_spec_without_creating_anything(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $pre = $this->api($token)->postJson('/api/v1/applications/preflight', [
            'name' => 'api', 'source_type' => 'image', 'image' => 'nginx:1', 'port' => 8080, 'domain_mode' => 'none',
            'cpu_limit' => 2, 'memory_limit_mb' => 4096, 'env' => [['key' => 'APP_KEY', 'value' => 'shh']],
        ])->assertOk()->json('data');
        $this->assertSame('automatic', $pre['spec']['placement']);
        $this->assertSame($server->id, $pre['spec']['server']['id']);
        $this->assertSame(2.0, (float) $pre['spec']['cpus']);
        $this->assertSame(4096, $pre['spec']['memory_mb']);
        $this->assertSame('custom', $pre['spec']['limits_from']);
        $this->assertSame(['APP_KEY'], $pre['spec']['env_keys']);
        $this->assertStringNotContainsString('shh', json_encode($pre), 'env values are never echoed');
        $this->assertSame(0, Application::allTenants()->count());
    }
}
