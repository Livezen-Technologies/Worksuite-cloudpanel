<?php

namespace Tests\Feature;

use App\Models\AgentCommand;
use App\Models\Deployment;
use App\Models\Image;
use App\Models\Job;
use App\Models\Release;
use App\Models\Secret;
use App\Models\Server;
use Tests\TestCase;

/** Real image builds on builder nodes, Trivy scans, and post-start health checks (APP-001/002, IMG-003, UHOST-006, SRS 2.4). */
class BuildPipelineTest extends TestCase
{
    /** @return array{0:string,1:\App\Models\Organization,2:Server,3:string,4:Server,5:string} [token, org, target, targetSecret, builder, builderSecret] */
    private function fleet(): array
    {
        [, $org, $token] = $this->org();
        $target = $this->server($org, ['name' => 'prod-1']);
        [$targetSecret] = $this->enrollAgent($target, $token);
        $this->heartbeat($targetSecret);
        $builder = $this->server($org, ['name' => 'builder-1', 'environment' => 'staging']);
        [$builderSecret] = $this->enrollAgent($builder, $token);
        $this->heartbeat($builderSecret);
        $this->api($token)->patchJson("/api/v1/servers/{$builder->id}", ['roles' => ['builder']])->assertOk()->assertJsonPath('data.roles', ['builder']);

        return [$token, $org, $target, $targetSecret, $builder, $builderSecret];
    }

    private function gitApp(string $token, Server $target, array $source = [], array $extra = []): string
    {
        return $this->api($token)->postJson('/api/v1/applications', array_merge([
            'name' => 'api', 'source_type' => 'git', 'server_id' => $target->id, 'port' => 8080, 'environment' => 'staging',
            'source' => array_merge(['repo' => 'https://git.example.com/acme/api.git', 'branch' => 'main', 'dockerfile_path' => 'docker/Dockerfile', 'context' => '.', 'build_args' => ['GO_VERSION' => '1.24']], $source),
        ], $extra))->assertCreated()->json('data.id');
    }

    public function test_git_build_runs_on_the_builder_pushes_by_digest_and_deploys_on_the_target(): void
    {
        [$token, , $target, $targetSecret, $builder, $builderSecret] = $this->fleet();
        $registry = $this->api($token)->postJson('/api/v1/registries', ['name' => 'reg', 'type' => 'private', 'url' => 'https://reg.example.com:5000', 'username' => 'ci', 'password' => 'pw'])->assertCreated()->json('data.id');
        $appId = $this->gitApp($token, $target, ['push_registry_id' => $registry, 'git_token' => 'ghp_secret']);
        $this->assertArrayNotHasKey('git_token', $this->api($token)->getJson("/api/v1/applications/{$appId}")->json('data.source_summary'));
        $this->assertSame('ghp_secret', Secret::allTenants()->latest()->first()->value);

        $dep = $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->assertStatus(202)->json('data');
        $this->assertSame([], $this->heartbeat($targetSecret)['commands'], 'nothing is built on the production target');
        [$build] = $this->heartbeat($builderSecret)['commands'];
        $this->assertSame('image.build', $build['type']);
        $p = $build['payload'];
        $this->assertSame('https://git.example.com/acme/api.git', $p['repo']);
        $this->assertSame('main', $p['ref']);
        $this->assertSame('docker/Dockerfile', $p['dockerfile_path']);
        $this->assertSame(['GO_VERSION' => '1.24'], $p['build_args']);
        $this->assertSame(['token' => 'ghp_secret'], $p['git_auth']);
        $this->assertSame(['registry' => 'reg.example.com:5000', 'username' => 'ci', 'password' => 'pw'], $p['push']);
        $this->assertStringStartsWith('reg.example.com:5000/', $p['image_tag']);

        // Build log lines stream into the pipeline view.
        $this->commandResult($builderSecret, $build['id'], 'running', ['progress' => 20, 'message' => "Step 1/2 : FROM scratch\nStep 2/2 : COPY app /app"])->assertOk();
        $job = $this->api($token)->getJson("/api/v1/jobs/{$dep['job_id']}")->assertOk()->json('data');
        $this->assertSame(['Step 1/2 : FROM scratch', 'Step 2/2 : COPY app /app'], $job['build_log']);
        $this->assertSame('running', collect($job['stages'])->firstWhere('name', 'checkout')['status']);

        [$name] = explode(':', substr($p['image_tag'], strlen('reg.example.com:5000/')), 2);
        $repoDigest = 'reg.example.com:5000/'.$name.'@sha256:d1';
        $this->commandResult($builderSecret, $build['id'], 'succeeded', ['output' => [
            'image' => $p['image_tag'], 'image_id' => 'sha256:feed', 'digest' => 'sha256:d1', 'repo_digest' => $repoDigest,
            'commit' => '0123456789abcdef', 'log' => ['Step 1/2 : FROM scratch', 'Step 2/2 : COPY app /app', 'pushed'],
        ]])->assertOk();

        $stages = collect(Job::allTenants()->find($dep['job_id'])->stages)->keyBy('name');
        $this->assertSame('succeeded', $stages['checkout']['status']);
        $this->assertStringContainsString('0123456789ab', $stages['checkout']['message']);
        $this->assertSame('succeeded', $stages['build_scan']['status']);
        $this->assertSame('succeeded', $stages['push']['status']);
        $this->assertSame("Placed on {$target->name}", $stages['placement']['message']);

        [$create] = $this->heartbeat($targetSecret)['commands'];
        $this->assertSame('container.create', $create['type']);
        $this->assertSame($repoDigest, $create['payload']['image']);
        $this->assertSame('missing', $create['payload']['pull']);
        $this->assertSame('ci', $create['payload']['registry_auth']['username']);
        $this->commandResult($targetSecret, $create['id'], 'succeeded', ['output' => ['container_id' => 'c1']]);
        $this->passHealthChecks($targetSecret);

        $release = Release::allTenants()->where('application_id', $appId)->first();
        $this->assertSame($repoDigest, $release->image);
        $this->assertSame('sha256:d1', $release->digest);
        $this->assertSame('succeeded', Deployment::allTenants()->find($dep['deployment_id'])->status);
        $this->assertSame(['Step 1/2 : FROM scratch', 'Step 2/2 : COPY app /app', 'pushed'], $this->api($token)->getJson("/api/v1/jobs/{$dep['job_id']}")->json('data.build_log'));
    }

    public function test_unpushed_build_needs_a_builder_target_and_the_setting(): void
    {
        [$token, , $target, $targetSecret, , $builderSecret] = $this->fleet();
        $appId = $this->gitApp($token, $target);
        $jobId = $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->assertStatus(202)->json('data.job_id');
        $job = Job::allTenants()->find($jobId);
        $this->assertSame('failed', $job->status);
        $this->assertStringContainsString('No push registry', $job->error);
        $this->assertSame([], $this->heartbeat($builderSecret)['commands']);

        // Target is a builder and the organisation allows building on deploy targets.
        $this->api($token)->patchJson("/api/v1/servers/{$target->id}", ['roles' => ['app', 'builder']])->assertOk();
        $this->api($token)->patchJson('/api/v1/settings', ['security' => ['allow_builds_on_deploy_targets' => true]])->assertOk();
        $jobId = $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->assertStatus(202)->json('data.job_id');
        [$build] = $this->heartbeat($targetSecret)['commands'];
        $this->assertSame('image.build', $build['type']);
        $this->assertArrayNotHasKey('push', $build['payload']);
        $this->assertStringStartsWith('dmp-build/', $build['payload']['image_tag']);
        $this->commandResult($targetSecret, $build['id'], 'succeeded', ['output' => ['image_id' => 'sha256:aa', 'digest' => 'sha256:aa', 'log' => ['ok']]]);
        [$create] = $this->heartbeat($targetSecret)['commands'];
        $this->assertSame($build['payload']['image_tag'], $create['payload']['image']);
        $this->assertSame('never', $create['payload']['pull']);
        $this->assertSame('skipped', collect(Job::allTenants()->find($jobId)->stages)->firstWhere('name', 'push')['status']);
    }

    public function test_build_failure_keeps_the_running_release_and_shows_the_log(): void
    {
        [$token, , $target, $targetSecret, , $builderSecret] = $this->fleet();
        $registry = $this->api($token)->postJson('/api/v1/registries', ['name' => 'reg', 'type' => 'private', 'url' => 'reg.example.com'])->json('data.id');
        $appId = $this->gitApp($token, $target, ['push_registry_id' => $registry]);
        $jobId = $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->json('data.job_id');
        [$build] = $this->heartbeat($builderSecret)['commands'];
        $this->commandResult($builderSecret, $build['id'], 'failed', ['message' => 'build failed: COPY failed', 'output' => ['log' => ['Step 1/2 : FROM scratch', 'ERROR: COPY failed']]]);
        $job = Job::allTenants()->find($jobId);
        $this->assertSame('failed', $job->status);
        $this->assertSame('failed', collect($job->stages)->firstWhere('name', 'build_scan')['status']);
        $this->assertNull(collect($job->stages)->firstWhere('name', 'rollback'));
        $this->assertSame(['Step 1/2 : FROM scratch', 'ERROR: COPY failed'], Deployment::allTenants()->where('job_id', $jobId)->first()->logs_tail);
        $this->assertSame([], $this->heartbeat($targetSecret)['commands']);
    }

    public function test_trivy_scan_runs_on_the_agent_and_blocks_critical_cves(): void
    {
        config(['platform.drivers.scanner' => 'trivy']);
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $appId = $this->api($token)->postJson('/api/v1/applications', ['name' => 'web', 'source_type' => 'image', 'image' => 'nginx:1.27', 'server_id' => $server->id, 'environment' => 'staging'])->json('data.id');
        $this->api($token)->patchJson('/api/v1/settings', ['security' => ['block_critical_cves' => true]])->assertOk();
        $vulns = ['scanner' => 'trivy', 'total' => 3, 'counts' => ['critical' => 1, 'high' => 1, 'low' => 1], 'vulnerabilities' => [
            ['id' => 'CVE-2024-1', 'package' => 'openssl', 'installed' => '3.0', 'fixed' => '3.1', 'severity' => 'CRITICAL'],
            ['id' => 'CVE-2024-2', 'package' => 'zlib', 'installed' => '1', 'severity' => 'HIGH'],
            ['id' => 'CVE-2024-3', 'package' => 'curl', 'installed' => '8', 'severity' => 'LOW'],
        ]];

        $jobId = $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->json('data.job_id');
        [$scan] = $this->heartbeat($secret)['commands'];
        $this->assertSame(['image.scan', ['image' => 'nginx:1.27']], [$scan['type'], $scan['payload']]);
        $this->commandResult($secret, $scan['id'], 'succeeded', ['output' => $vulns]);
        $job = Job::allTenants()->find($jobId);
        $this->assertSame('failed', $job->status);
        $this->assertStringContainsString('1 critical vulnerabilities; deploy blocked', $job->error);
        $this->assertSame('1 critical · 1 high · 0 medium · 1 low — blocked by policy', collect($job->stages)->firstWhere('name', 'build_scan')['message']);
        $this->assertSame([], $this->heartbeat($secret)['commands'], 'no container is created');

        // Policy off: same findings are recorded and the deploy goes ahead.
        $this->api($token)->patchJson('/api/v1/settings', ['security' => ['block_critical_cves' => false]])->assertOk();
        $jobId = $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->json('data.job_id');
        [$scan] = $this->heartbeat($secret)['commands'];
        $this->commandResult($secret, $scan['id'], 'succeeded', ['output' => $vulns]);
        $this->assertSame(['container.create'], array_column($this->heartbeat($secret)['commands'], 'type'));
        $this->assertSame(1, Deployment::allTenants()->where('job_id', $jobId)->first()->scan_result['critical']);

        // "trivy not installed" fails the scan cleanly; not blocking while the policy is off.
        $jobId = $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->json('data.job_id');
        $scan = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'image.scan');
        $this->commandResult($secret, $scan['id'], 'failed', ['message' => 'trivy not installed']);
        $this->assertStringContainsString('trivy not installed', collect(Job::allTenants()->find($jobId)->stages)->firstWhere('name', 'build_scan')['message']);
    }

    public function test_images_scan_endpoint_uses_the_agent_with_trivy(): void
    {
        config(['platform.drivers.scanner' => 'trivy']);
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret, ['images' => [['id' => 'sha256:aa', 'tags' => ['nginx:1'], 'size_mb' => 50, 'created' => '2026-01-01T00:00:00Z']], 'containers' => [], 'networks' => [], 'volumes' => []]);
        $image = Image::allTenants()->where('server_id', $server->id)->first();
        $this->api($token)->postJson("/api/v1/images/{$image->id}/scan")->assertStatus(202);
        $scan = collect($this->heartbeat($secret, ['images' => null])['commands'])->firstWhere('type', 'image.scan');
        $this->assertSame('nginx:1', $scan['payload']['image']);
        $this->commandResult($secret, $scan['id'], 'succeeded', ['output' => ['vulnerabilities' => [['id' => 'CVE-1', 'severity' => 'CRITICAL', 'package' => 'x']]]]);
        $this->api($token)->patchJson('/api/v1/settings', ['security' => ['block_critical_cves' => true]])->assertOk();
        $row = collect($this->api($token)->getJson('/api/v1/images')->json('data'))->firstWhere('id', $image->id);
        $this->assertSame('scanned', $row['scan_status']);
        $this->assertSame(1, $row['scan_result']['critical']);
        $this->assertTrue($row['blocks_deploy']);
    }

    public function test_failed_health_check_returns_logs_and_blue_green_keeps_the_old_release(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $appId = $this->api($token)->postJson('/api/v1/applications', ['name' => 'shop', 'source_type' => 'image', 'image' => 'shop:1', 'server_id' => $server->id,
            'environment' => 'staging', 'strategy' => 'blue_green', 'port' => 8080, 'health_path' => '/healthz', 'health_timeout' => 30])->assertCreated()->json('data.id');
        $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId]);
        $this->commandResult($secret, $this->heartbeat($secret)['commands'][0]['id'], 'succeeded', ['output' => ['container_id' => 'old']]);
        [$check] = $this->passHealthChecks($secret);
        $this->assertSame(['container_id' => 'app-shop', 'port' => 8080, 'path' => '/healthz', 'timeout_s' => 30, 'log_lines' => 50], $check['payload']);

        $this->api($token)->patchJson("/api/v1/applications/{$appId}", ['image' => 'shop:broken'])->assertOk();
        $jobId = $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->json('data.job_id');
        [$create] = $this->heartbeat($secret)['commands'];
        $this->assertSame('container.create', $create['type'], 'old container is not removed first');
        $this->commandResult($secret, $create['id'], 'succeeded', ['output' => ['container_id' => 'new']]);
        $logs = implode("\n", array_map(fn ($i) => "line {$i}", range(1, 60)));
        $this->passHealthChecks($secret, 'failed', ['message' => 'health check failed: not healthy after 30s: GET http://172.18.0.5:8080/healthz returned 500', 'output' => ['healthy' => false, 'logs' => $logs]]);

        $deployment = Deployment::allTenants()->where('job_id', $jobId)->first();
        $this->assertSame('rolled_back', $deployment->status);
        $this->assertCount(50, $deployment->logs_tail);
        $this->assertSame('line 60', $deployment->logs_tail[49]);
        $job = Job::allTenants()->find($jobId);
        $this->assertSame('failed', $job->status);
        $this->assertSame('failed', collect($job->stages)->firstWhere('name', 'health_check')['status']);
        $this->assertSame('succeeded', collect($job->stages)->firstWhere('name', 'rollback')['status']);
        $remove = $this->heartbeat($secret)['commands'];
        $this->assertSame(['container.remove'], array_column($remove, 'type'));
        $this->assertStringStartsWith('app-shop-', $remove[0]['payload']['container_id'], 'only the new container is removed');
        $this->assertSame(1, Release::allTenants()->where('application_id', $appId)->count());
    }

    public function test_customer_health_failure_shows_last_50_lines(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['shared_pool' => true]);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $res = $this->api($token)->postJson('/api/v1/app/apps', ['name' => 'blog', 'source' => ['type' => 'image', 'image' => 'nginx:1'], 'port' => 80, 'size' => 'small', 'health_path' => '/up'])->assertStatus(202)->json('data');
        $create = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'container.create');
        $this->commandResult($secret, $create['id'], 'succeeded', ['output' => ['container_id' => 'x']]);
        [$check] = $this->passHealthChecks($secret, 'failed', ['message' => 'health check failed: container is exited (exit code 1)', 'output' => ['logs' => "boot\npanic: missing DATABASE_URL\n"]]);
        $this->assertSame('/up', $check['payload']['path']);
        $detail = $this->api($token)->getJson("/api/v1/app/apps/{$res['app']['id']}")->assertOk()->json('data');
        $this->assertSame('failed', $detail['last_deployment']['status']);
        $this->assertSame(['boot', 'panic: missing DATABASE_URL'], $detail['last_deployment']['logs_tail']);
        $this->assertSame('/up', $detail['health_path']);
        $this->assertSame(['container.stop'], array_column($this->heartbeat($secret)['commands'], 'type'));
    }

    public function test_builder_only_servers_never_receive_workloads(): void
    {
        [, $org, $token] = $this->org();
        $builder = $this->server($org, ['shared_pool' => true, 'roles' => ['builder']]);
        $jobId = $this->api($token)->postJson('/api/v1/app/apps', ['name' => 'blog', 'source' => ['type' => 'image', 'image' => 'nginx:1'], 'size' => 'small'])->json('data.job_id');
        $this->assertSame('failed', Job::allTenants()->find($jobId)->status, 'no app-role capacity');
        $this->api($token)->patchJson("/api/v1/servers/{$builder->id}", ['roles' => ['root']])->assertStatus(422);
        $this->assertSame(['builder'], Server::allTenants()->find($builder->id)->roles);
        $this->assertSame(0, AgentCommand::allTenants()->count());
    }

    public function test_auto_builder_records_stack_cache_hits_and_build_minutes_within_the_plan(): void
    {
        [$token, $org, $target, , , $builderSecret] = $this->fleet();
        $registry = $this->api($token)->postJson('/api/v1/registries', ['name' => 'reg', 'type' => 'private', 'url' => 'https://reg.example.com', 'username' => 'ci', 'password' => 'pw'])->assertCreated()->json('data.id');
        $appId = $this->gitApp($token, $target, ['builder' => 'auto', 'no_cache' => true, 'dockerfile_path' => null, 'push_registry_id' => $registry]);
        $dep = $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->assertStatus(202)->json('data');
        [$build] = $this->heartbeat($builderSecret)['commands'];
        $this->assertSame('auto', $build['payload']['builder']);
        $this->assertTrue($build['payload']['no_cache']);
        $this->assertSame(3600, $build['payload']['timeout_seconds']);

        $this->commandResult($builderSecret, $build['id'], 'succeeded', ['output' => [
            'image' => $build['payload']['image_tag'], 'image_id' => 'sha256:feed', 'digest' => 'sha256:feed',
            'stack' => 'node', 'cache_steps' => 4, 'cache_hits' => 3, 'duration_seconds' => 130,
        ]])->assertOk();
        $d = Deployment::allTenants()->where("job_id", $dep["job_id"])->first();
        $this->assertSame(['node', 130, 4, 3], [$d->build_stack, $d->build_seconds, $d->build_cache_steps, $d->build_cache_hits]);
        $stage = collect(Job::allTenants()->find($dep['job_id'])->stages)->firstWhere('name', 'build_scan');
        $this->assertStringContainsString('detected node, 3/4 steps cached, 130s', $stage['message']);

        $usage = $this->api($token)->getJson('/api/v1/builds/usage')->assertOk()->json('data');
        $this->assertSame([1, 3, 75, ['node' => 1]], [$usage['builds'], $usage['minutes_used'], (int) $usage['cache_hit_rate'], $usage['stacks']]);

        // Customer packages cap monthly build minutes.
        \App\Models\Application::allTenants()->whereKey($appId)->update(['kind' => 'customer']);
        app(\App\Support\Tenant::class)->as($org->id, function () {
            $plan = app(\App\Services\BillingService::class)->plan();
            $plan->update(['limits' => array_merge($plan->limits ?? [], ['build_minutes' => 3, 'build_timeout_minutes' => 10])]);
        });
        $page = $this->api($token)->getJson('/api/v1/app/builds/usage')->assertOk()->json('data');
        $this->assertSame(['build_minutes' => 3, 'timeout_minutes' => 10], $page['limits']);
        $this->assertSame(3, $page['minutes_used']);
        $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->assertStatus(409)->assertSee('build minutes are used up');
    }

    public function test_builds_reuse_pushed_images_as_cache_and_old_images_are_cleaned_up(): void
    {
        [$token, , $target, , , $builderSecret] = $this->fleet();
        $registry = $this->api($token)->postJson('/api/v1/registries', ['name' => 'reg', 'type' => 'private', 'url' => 'https://reg.example.com', 'username' => 'ci', 'password' => 'pw'])->assertCreated()->json('data.id');
        $appId = $this->gitApp($token, $target, ['push_registry_id' => $registry]);
        $digest = fn ($i) => 'sha256:'.str_repeat((string) $i, 64);
        foreach (range(1, 7) as $v) {
            Release::allTenants()->create(['organization_id' => \App\Models\Application::allTenants()->find($appId)->organization_id, 'application_id' => $appId, 'version' => $v,
                'image' => 'reg.example.com/acme/api@'.$digest($v), 'config' => [], 'status' => $v === 7 ? 'active' : 'superseded', 'created_at' => now()]);
        }
        $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->assertStatus(202);
        [$build] = $this->heartbeat($builderSecret)['commands'];
        $this->assertSame(['reg.example.com/acme/api@'.$digest(7), 'reg.example.com/acme/api@'.$digest(6)], $build['payload']['cache_from']);
        $repo = substr($build['payload']['image_tag'], 0, strrpos($build['payload']['image_tag'], ':'));
        $this->assertStringStartsWith('reg.example.com/', $repo);
        $this->assertSame($repo.':buildcache', $build['payload']['cache_ref'], 'BuildKit keeps its own cache beside the images');

        // Clean-up keeps the last 5 releases; dry run first.
        $preview = $this->api($token)->postJson("/api/v1/registries/{$registry}/gc", ['dry_run' => true])->assertOk()->json('data.images');
        $this->assertSame([2, 1], array_column($preview, 'version'));
        \Illuminate\Support\Facades\Http::fake(['reg.example.com/v2/acme/api/manifests/'.$digest(2) => \Illuminate\Support\Facades\Http::response('', 202),
            'reg.example.com/v2/acme/api/manifests/*' => \Illuminate\Support\Facades\Http::response('', 405)]);
        $run = $this->api($token)->postJson("/api/v1/registries/{$registry}/gc", ['dry_run' => false])->assertOk()->json('data');
        $this->assertSame(1, $run['deleted']);
        $this->assertStringContainsString('does not allow deletes', collect($run['images'])->firstWhere('version', 1)['error']);
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => $r->method() === 'DELETE' && $r->hasHeader('Authorization') && str_ends_with($r->url(), $digest(2)));

        $v2 = Release::allTenants()->where('application_id', $appId)->where('version', 2)->first();
        $this->assertNotEmpty($v2->config['image_deleted_at']);
        $this->api($token)->postJson("/api/v1/applications/{$appId}/rollback", ['release_id' => $v2->id])->assertStatus(409)->assertSee('removed from the registry');
    }
}
