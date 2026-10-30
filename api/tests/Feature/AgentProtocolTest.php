<?php

namespace Tests\Feature;

use App\Models\AgentCommand;
use App\Models\Container;
use App\Models\EnrollmentToken;
use App\Models\Image;
use App\Models\Job;
use App\Models\Server;
use App\Support\CommandSigner;
use Tests\TestCase;

class AgentProtocolTest extends TestCase
{
    public function test_enrollment_token_is_single_use_hashed_and_expires(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['last_heartbeat_at' => null]);
        $data = $this->api($token)->postJson('/api/v1/servers/enrollment', ['server_id' => $server->id])->assertCreated()
            ->assertJsonStructure(['data' => ['token', 'expires_at', 'install_command']])->json('data');

        $this->assertStringContainsString('curl -fsSL https://panel.test/agent/v1/install.sh | sudo sh -s -- --url https://panel.test --token '.$data['token'], $data['install_command']);
        $stored = EnrollmentToken::allTenants()->first();
        $this->assertSame(hash('sha256', $data['token']), $stored->token_hash);
        $this->assertEqualsWithDelta(now()->addMinutes(15)->timestamp, $stored->expires_at->timestamp, 5);

        $enroll = fn () => $this->postJson('/agent/v1/enroll', ['token' => $data['token'], 'hostname' => 'n1', 'facts' => ['os' => 'Debian 12', 'docker_version' => '26.1.0', 'cpu_cores' => 2, 'ram_mb' => 4096, 'disk_gb' => 40]]);
        $enroll()->assertOk()->assertJsonStructure(['server_id', 'agent_secret', 'hmac_key', 'heartbeat_interval'])->assertJsonPath('heartbeat_interval', 15);
        $enroll()->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
        $this->assertSame('Debian 12', Server::allTenants()->find($server->id)->os);

        $second = $this->api($token)->postJson('/api/v1/servers/enrollment', ['server_id' => $server->id])->json('data.token');
        $this->travel(16)->minutes();
        $this->postJson('/agent/v1/enroll', ['token' => $second])->assertStatus(401);
    }

    public function test_heartbeat_reconciles_inventory_and_status(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['last_heartbeat_at' => null]);
        [$secret] = $this->enrollAgent($server, $token);
        $this->assertSame('pending', Server::allTenants()->find($server->id)->status);

        $this->heartbeat($secret, [
            'containers' => [
                ['docker_id' => 'c1', 'name' => '/web', 'image' => 'nginx:1', 'state' => 'running', 'health' => 'healthy', 'cpu_pct' => 2.5, 'mem_mb' => 30, 'ports' => ['0.0.0.0:80->80/tcp'], 'created' => '2026-01-01T00:00:00Z'],
                ['docker_id' => 'c2', 'name' => 'job', 'image' => 'alpine', 'state' => 'exited', 'health' => '', 'cpu_pct' => 0, 'mem_mb' => 0, 'ports' => [], 'created' => '2026-01-01T00:00:00Z'],
            ],
            'images' => [['id' => 'sha256:aa', 'tags' => ['nginx:1'], 'size_mb' => 50, 'created' => '2026-01-01T00:00:00Z']],
            'networks' => [['id' => 'n1', 'name' => 'bridge', 'driver' => 'bridge', 'scope' => 'local', 'internal' => false]],
            'volumes' => [['name' => 'data', 'driver' => 'local', 'mountpoint' => '/var/lib/docker/volumes/data/_data']],
        ]);
        $s = Server::allTenants()->find($server->id);
        $this->assertSame('online', $s->status);
        $this->assertSame(2, Container::allTenants()->where('server_id', $server->id)->count());
        $this->assertSame('web', Container::allTenants()->where('docker_id', 'c1')->value('name'));
        $this->assertSame(1, Image::allTenants()->where('server_id', $server->id)->count());

        // Docker unreachable: null lists keep the last inventory.
        $this->heartbeat($secret, ['containers' => null, 'images' => null, 'networks' => null, 'volumes' => null, 'docker_error' => 'cannot connect']);
        $this->assertSame(2, Container::allTenants()->where('server_id', $server->id)->count());
        $this->assertSame('warning', Server::allTenants()->find($server->id)->status);

        // Gone from Docker → removed.
        $this->heartbeat($secret, ['containers' => [['docker_id' => 'c1', 'name' => 'web', 'image' => 'nginx:1', 'state' => 'running']]]);
        $this->assertSame(1, Container::allTenants()->where('server_id', $server->id)->count());

        $this->api($token)->getJson("/api/v1/servers/{$server->id}/verification")->assertOk()->assertJsonPath('data.ok', true);
        $this->api($token)->getJson('/api/v1/metrics?server_id='.$server->id)->assertOk()->assertJsonCount(1, 'data.series.cpu');

        $this->travel(91)->seconds();
        $this->assertSame('offline', Server::allTenants()->find($server->id)->status);
    }

    public function test_heartbeat_keeps_old_container_and_new_placeholder_apart_during_recreate(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['last_heartbeat_at' => null]);
        [$secret] = $this->enrollAgent($server, $token);
        $old = ['docker_id' => 'old1', 'name' => '/app-shop', 'image' => 'shop:1', 'state' => 'running', 'health' => '', 'cpu_pct' => 1, 'mem_mb' => 10, 'ports' => [], 'created' => '2026-01-01T00:00:00Z'];
        // A recreate deploy adds a placeholder (no docker_id yet) with the same name while the old one still runs.
        // (Placeholder inserted first so a scan-order "first()" would pick it.)
        Container::allTenants()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'name' => 'app-shop', 'image' => 'shop:2', 'state' => 'creating']);
        Container::allTenants()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'docker_id' => 'old1', 'name' => 'app-shop', 'image' => 'shop:1', 'state' => 'running']);

        $this->heartbeat($secret, ['containers' => [$old], 'images' => [], 'networks' => [], 'volumes' => []]);
        $this->assertSame(1, Container::allTenants()->where('docker_id', 'old1')->count());
        $this->assertSame(1, Container::allTenants()->whereNull('docker_id')->where('name', 'app-shop')->count());

        // The new container appears: it takes over the placeholder; the old one is gone.
        $new = ['docker_id' => 'new1', 'image' => 'shop:2'] + $old;
        $this->heartbeat($secret, ['containers' => [$new], 'images' => [], 'networks' => [], 'volumes' => []]);
        $this->assertSame(['new1'], Container::allTenants()->where('server_id', $server->id)->pluck('docker_id')->all());
    }

    public function test_commands_are_signed_delivered_once_and_results_idempotent(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret, $hmac] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret, ['containers' => [['docker_id' => 'c1', 'name' => 'web', 'image' => 'nginx', 'state' => 'running']]]);
        $container = Container::allTenants()->where('docker_id', 'c1')->first();

        $jobId = $this->api($token)->postJson("/api/v1/containers/{$container->id}/actions", ['action' => 'stop', 'timeout' => 10])->assertStatus(202)->json('data.job_id');
        $commands = $this->heartbeat($secret)['commands'];
        $this->assertCount(1, $commands);
        $cmd = $commands[0];
        $this->assertSame('container.stop', $cmd['type']);
        $this->assertSame(1, $cmd['version']);
        $this->assertSame(['container_id' => 'c1', 'timeout' => 10], $cmd['payload']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $cmd['issued_at']);
        $this->assertSame(CommandSigner::sign($hmac, $cmd['id'], $cmd['type'], $cmd['payload'], $cmd['issued_at']), $cmd['signature']);
        $this->assertSame([], $this->heartbeat($secret)['commands'], 'a command is delivered once');

        $this->commandResult($secret, $cmd['id'], 'running', ['progress' => 50])->assertOk();
        $this->assertSame('running', Job::allTenants()->find($jobId)->status);
        $this->commandResult($secret, $cmd['id'], 'succeeded', ['output' => ['ok' => true]])->assertOk();
        $this->commandResult($secret, $cmd['id'], 'failed', ['message' => 'late duplicate'])->assertOk()->assertJsonPath('status', 'succeeded');

        $job = Job::allTenants()->find($jobId);
        $this->assertSame('succeeded', $job->status);
        $this->assertSame(100, $job->progress);
        $this->assertSame('exited', $container->fresh()->state);

        // Another agent cannot report on this command; bad secrets are refused.
        $this->commandResult('wrong-secret', $cmd['id'])->assertStatus(401);
        $this->api($token)->getJson("/api/v1/jobs/{$jobId}")->assertOk()->assertJsonPath('data.status', 'succeeded')
            ->assertJsonStructure(['data' => ['id', 'type', 'status', 'progress', 'stages' => [['name', 'status', 'started_at', 'finished_at', 'message']], 'result', 'error', 'created_at']]);
    }

    public function test_maintenance_mode_blocks_container_create_and_deploys(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        $this->api($token)->postJson("/api/v1/servers/{$server->id}/maintenance", ['enabled' => true])->assertOk()->assertJsonPath('data.status', 'maintenance');
        $this->api($token)->postJson('/api/v1/containers', ['server_id' => $server->id, 'name' => 'web', 'image' => 'nginx:1'])->assertStatus(409)->assertJsonPath('error.code', 'conflict');
    }

    public function test_container_create_applies_secure_defaults(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->api($token)->postJson('/api/v1/containers', ['server_id' => $server->id, 'name' => 'web', 'image' => 'nginx:1', 'privileged' => true])->assertStatus(422);
        $this->api($token)->postJson('/api/v1/containers', ['server_id' => $server->id, 'name' => 'web', 'image' => 'nginx:1', 'user' => 'root'])->assertStatus(422);
        $this->api($token)->postJson('/api/v1/containers', [
            'server_id' => $server->id, 'name' => 'web', 'image' => 'nginx:1', 'env' => [['key' => 'A', 'value' => 'b']],
            'ports' => [['container_port' => 80, 'host_port' => 8080]], 'limits' => ['memory_mb' => 256],
        ])->assertStatus(202);
        $cmd = $this->heartbeat($secret)['commands'][0];
        $this->assertSame('container.create', $cmd['type']);
        $this->assertTrue($cmd['payload']['read_only_rootfs']);
        $this->assertSame(['A' => 'b'], $cmd['payload']['env']);
        $this->assertSame(256, $cmd['payload']['memory_mb']);
        $this->assertSame([['container_port' => 80, 'host_port' => 8080, 'protocol' => 'tcp']], $cmd['payload']['ports']);
        $this->assertArrayNotHasKey('privileged', $cmd['payload']);
        $this->commandResult($secret, $cmd['id'], 'succeeded', ['output' => ['container_id' => 'dock123']])->assertOk();
        $this->assertSame('dock123', Container::allTenants()->where('name', 'web')->value('docker_id'));
    }

    public function test_install_script_and_binary_download(): void
    {
        $dir = storage_path('framework/testing/agent');
        @mkdir($dir, 0777, true);
        file_put_contents("{$dir}/install.sh", "#!/bin/sh\necho install\n");
        file_put_contents("{$dir}/dockermgr-agent-linux-amd64", 'BIN');
        config(['platform.agent_binary_dir' => $dir]);

        $this->get('/agent/v1/install.sh')->assertOk()->assertSee('echo install');
        $this->get('/agent/v1/download/dockermgr-agent-linux-amd64')->assertOk();
        $this->get('/agent/v1/download/dockermgr-agent-linux-arm64')->assertNotFound();
        $this->get('/agent/v1/download/dockermgr-agent-linux-mips')->assertNotFound();
    }

    public function test_an_out_of_date_agent_gets_a_plain_update_message(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $command = app(\App\Services\CommandBus::class)->dispatch($server, 'container.restart', ['container_id' => 'web']);
        $this->commandResult($secret, $command->id, 'failed', ['message' => 'rejected: unknown command type "fs.list"'])->assertOk();
        $message = \App\Models\AgentCommand::allTenants()->find($command->id)->message;
        $this->assertStringContainsString('is too old to run "fs.list"', $message);
        $this->assertStringContainsString('Update agent', $message);
    }
}
