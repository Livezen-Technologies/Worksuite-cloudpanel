<?php

namespace Tests\Feature;

use App\Models\AgentCommand;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Job;
use App\Models\Release;
use App\Services\DeploymentService;
use App\Support\Tenant;
use Tests\TestCase;

class DeploymentTest extends TestCase
{
    private function setUpApp(array $attributes = []): array
    {
        [$user, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $appId = $this->api($token)->postJson('/api/v1/applications', array_merge([
            'name' => 'shop', 'source_type' => 'image', 'image' => 'ghcr.io/acme/shop:1.0', 'server_id' => $server->id, 'port' => 8080,
            'env' => [['key' => 'DB_PASSWORD', 'value' => 's3cret', 'secret' => true]],
        ], $attributes))->assertCreated()->assertJsonPath('data.env.0.value', null)->json('data.id');
        $this->api($token)->postJson('/api/v1/domains', ['name' => 'shop.acme.test', 'application_id' => $appId, 'target_port' => 8080])->assertCreated();

        return [$user, $org, $token, $server, $secret, $appId];
    }

    public function test_deployment_pipeline_creates_release(): void
    {
        [, , $token, , $secret, $appId] = $this->setUpApp();
        $response = $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->assertStatus(202);
        $jobId = $response->json('data.job_id');
        $deploymentId = $response->json('data.deployment_id');

        $job = Job::allTenants()->find($jobId);
        $this->assertSame(DeploymentService::ADMIN_STAGES, array_column($job->stages, 'name'));
        $this->assertSame('running', collect($job->stages)->firstWhere('name', 'deploy')['status']);
        $this->assertSame('skipped', collect($job->stages)->firstWhere('name', 'checkout')['status']);

        $commands = $this->heartbeat($secret)['commands'];
        $this->assertSame(['container.create'], array_column($commands, 'type'));
        $payload = $commands[0]['payload'];
        $this->assertSame('app-shop', $payload['name']);
        $this->assertSame('s3cret', $payload['env']['DB_PASSWORD']);
        $this->assertSame('Host(`shop.acme.test`)', $payload['labels']['traefik.http.routers.app-shop.rule']);
        $this->assertSame('8080', $payload['labels']['traefik.http.services.app-shop.loadbalancer.server.port']);

        $this->commandResult($secret, $commands[0]['id'], 'succeeded', ['output' => ['container_id' => 'd-1']])->assertOk();
        $this->assertSame('running', Deployment::allTenants()->find($deploymentId)->status, 'waits for the health check');
        [$check] = $this->passHealthChecks($secret);
        $this->assertSame(['container_id' => 'app-shop', 'port' => 8080, 'path' => '/', 'timeout_s' => 60, 'log_lines' => 50], $check['payload']);

        $this->api($token)->getJson("/api/v1/deployments/{$deploymentId}")->assertOk()
            ->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.job.status', 'succeeded')->assertJsonPath('data.job.progress', 100);
        $releases = $this->api($token)->getJson("/api/v1/applications/{$appId}/releases")->assertOk()->json('data');
        $this->assertCount(1, $releases);
        $this->assertSame(1, $releases[0]['version']);
        $this->assertArrayNotHasKey('env_encrypted', $releases[0]['config']);
        $this->assertSame('running', Application::allTenants()->find($appId)->status);
    }

    public function test_admin_delete_stops_the_app_containers_and_keeps_volumes(): void
    {
        [, , $token, , $secret, $appId] = $this->setUpApp(['environment' => 'staging']);
        $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->assertStatus(202);
        $create = $this->heartbeat($secret)['commands'][0];
        $this->commandResult($secret, $create['id'], 'succeeded', ['output' => ['container_id' => 'd-1']])->assertOk();
        $this->passHealthChecks($secret);

        $this->api($token)->deleteJson("/api/v1/applications/{$appId}")->assertNoContent();
        $commands = $this->heartbeat($secret)['commands'];
        $this->assertSame(['container.remove'], array_column($commands, 'type'));
        $this->assertTrue($commands[0]['payload']['keep_volumes']);
        $this->assertSoftDeleted('applications', ['id' => $appId]);
    }

    public function test_redeploy_replaces_container_and_rollback_uses_previous_release(): void
    {
        [, , $token, , $secret, $appId] = $this->setUpApp();
        $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->assertStatus(202);
        $cmd = $this->heartbeat($secret)['commands'][0];
        $this->commandResult($secret, $cmd['id'], 'succeeded', ['output' => ['container_id' => 'd-1']]);
        $this->passHealthChecks($secret);
        $v1 = Release::allTenants()->where('application_id', $appId)->first();

        $this->api($token)->patchJson("/api/v1/applications/{$appId}", ['image' => 'ghcr.io/acme/shop:2.0'])->assertOk();
        $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->assertStatus(202);
        $commands = $this->heartbeat($secret)['commands'];
        $this->assertSame(['container.remove', 'container.create'], array_column($commands, 'type'));
        $this->assertTrue($commands[0]['payload']['keep_volumes']);
        $this->assertSame('ghcr.io/acme/shop:2.0', $commands[1]['payload']['image']);
        foreach ($commands as $c) {
            $this->commandResult($secret, $c['id'], 'succeeded', ['output' => ['container_id' => 'd-2']]);
        }
        $this->passHealthChecks($secret);
        $this->assertSame(2, Release::allTenants()->where('application_id', $appId)->count());

        $rollback = $this->api($token)->postJson("/api/v1/applications/{$appId}/rollback", ['release_id' => $v1->id])->assertStatus(202)->json('data');
        $create = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'container.create');
        $this->assertSame('ghcr.io/acme/shop:1.0', $create['payload']['image']);
        foreach (AgentCommand::allTenants()->where('job_id', $rollback['job_id'])->get() as $c) {
            $this->commandResult($secret, $c->id, 'succeeded');
        }
        $this->passHealthChecks($secret);
        $latest = Release::allTenants()->where('application_id', $appId)->orderByDesc('version')->first();
        $this->assertSame(3, $latest->version);
        $this->assertSame('ghcr.io/acme/shop:1.0', $latest->image);
    }

    public function test_failed_deploy_rolls_back_to_last_release(): void
    {
        [, , $token, , $secret, $appId] = $this->setUpApp();
        $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId]);
        $this->commandResult($secret, $this->heartbeat($secret)['commands'][0]['id'], 'succeeded');
        $this->passHealthChecks($secret);

        $this->api($token)->patchJson("/api/v1/applications/{$appId}", ['image' => 'ghcr.io/acme/shop:broken']);
        $jobId = $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->json('data.job_id');
        [$remove, $create] = $this->heartbeat($secret)['commands'];
        $this->commandResult($secret, $remove['id'], 'succeeded');
        $this->commandResult($secret, $create['id'], 'failed', ['message' => 'health check failed', 'output' => ['logs' => "boot\npanic: db"]]);

        $rollbackCommands = $this->heartbeat($secret)['commands'];
        $this->assertSame(['container.remove', 'container.create'], array_column($rollbackCommands, 'type'));
        $this->assertSame('ghcr.io/acme/shop:1.0', $rollbackCommands[1]['payload']['image']);
        foreach ($rollbackCommands as $c) {
            $this->commandResult($secret, $c['id'], 'succeeded');
        }
        $job = Job::allTenants()->find($jobId);
        $this->assertSame('failed', $job->status);
        $this->assertSame('succeeded', collect($job->stages)->firstWhere('name', 'rollback')['status']);
        $deployment = Deployment::allTenants()->where('job_id', $jobId)->first();
        $this->assertSame('rolled_back', $deployment->status);
        $this->assertSame(['boot', 'panic: db'], $deployment->logs_tail);
    }

    public function test_production_approval_gate(): void
    {
        [, $org, $token, , $secret, $appId] = $this->setUpApp(['requires_approval' => true]);
        $response = $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->assertStatus(202);
        $deploymentId = $response->json('data.deployment_id');
        $this->assertSame('awaiting_approval', $response->json('data.deployment_status'));
        $this->assertSame([], $this->heartbeat($secret)['commands']);

        $this->api($token)->postJson("/api/v1/deployments/{$deploymentId}/approve")->assertForbidden();
        [, $second] = $this->member($org, 'devops');
        $this->api($second)->postJson("/api/v1/deployments/{$deploymentId}/approve")->assertStatus(202);
        $this->assertSame(['container.create'], array_column($this->heartbeat($secret)['commands'], 'type'));
    }

    public function test_maintenance_blocks_deployment(): void
    {
        [, , $token, $server, , $appId] = $this->setUpApp();
        $this->api($token)->postJson("/api/v1/servers/{$server->id}/maintenance", ['enabled' => true]);
        $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->assertStatus(409);
    }

    /** SRS 9.1: an interrupted job resumes without running twice for one operation id. */
    public function test_interrupted_deployment_resumes_without_running_twice(): void
    {
        [$user, $org, $token, , $secret, $appId] = $this->setUpApp();
        // Simulate a crash after the deployment was claimed but before commands were sent.
        $deployment = app(Tenant::class)->as($org->id, function () use ($appId, $user) {
            \Illuminate\Support\Facades\Bus::fake();
            $d = app(DeploymentService::class)->start(Application::find($appId), $user);
            \Illuminate\Support\Facades\Bus::swap(app(\Illuminate\Bus\Dispatcher::class));

            return $d;
        });
        Deployment::allTenants()->whereKey($deployment->id)->update(['status' => 'running']);
        $this->assertSame(0, AgentCommand::allTenants()->where('job_id', $deployment->job_id)->count());

        app(Tenant::class)->set(null);
        $this->artisan('deployments:resume')->assertSuccessful();
        $this->artisan('deployments:resume')->assertSuccessful();
        app(DeploymentService::class)->run($deployment->id);
        $this->assertSame(1, AgentCommand::allTenants()->where('job_id', $deployment->job_id)->count());

        $cmd = $this->heartbeat($secret)['commands'][0];
        $this->commandResult($secret, $cmd['id'], 'succeeded');
        $this->commandResult($secret, $cmd['id'], 'succeeded');
        $this->passHealthChecks($secret);
        $this->artisan('deployments:resume')->assertSuccessful();
        $this->assertSame(1, Release::allTenants()->where('application_id', $appId)->count());
        $this->assertSame('succeeded', $deployment->fresh()->status);
    }

    public function test_stale_command_on_dead_server_fails_and_rolls_back(): void
    {
        [, , $token, , $secret, $appId] = $this->setUpApp();
        $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId]);
        $this->commandResult($secret, $this->heartbeat($secret)['commands'][0]['id'], 'succeeded');
        $this->passHealthChecks($secret);
        $jobId = $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->json('data.job_id');
        $this->travel(11)->minutes();
        app(Tenant::class)->set(null);
        $this->artisan('deployments:resume')->assertSuccessful();
        $job = Job::allTenants()->find($jobId);
        $this->assertNotNull(collect($job->stages)->firstWhere('name', 'rollback'));
    }
}
