<?php

namespace Tests\Feature;

use App\Models\AgentCommand;
use App\Models\Vps;
use Tests\TestCase;

/** UVPS: container VPS under Sysbox on servers with the "vps" role. */
class CustomerVpsTest extends TestCase
{
    private const KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIKhkp4Qd0lVbq3KXc8fWl5WQh2v0b3d0aTVpY2hrZXk me@laptop';

    private function host(): array
    {
        [, $platform, $staff] = $this->org();
        $server = $this->server($platform, ['shared_pool' => true, 'roles' => ['vps'], 'ip' => '203.0.113.7', 'region' => 'sg']);
        [$secret] = $this->enrollAgent($server, $staff);
        $this->heartbeat($secret);

        return [$server, $secret];
    }

    public function test_a_customer_creates_and_runs_a_vps(): void
    {
        [$server, $secret] = $this->host();
        [, , $token] = $this->org();

        $page = $this->api($token)->getJson('/api/v1/app/vps')->assertOk()->json('data');
        $this->assertSame(['sg'], $page['regions']);
        $this->assertFalse(collect($page['plans'])->firstWhere('id', 'vps-4')['available'], 'KVM is not offered yet');

        $this->api($token)->postJson('/api/v1/app/vps', ['name' => 'web-1', 'plan' => 'vps-4', 'os' => 'ubuntu-2404', 'ssh_keys' => [self::KEY]])->assertStatus(422);
        $this->api($token)->postJson('/api/v1/app/vps', ['name' => 'web-1', 'plan' => 'ct-2', 'os' => 'ubuntu-2404', 'ssh_keys' => ['hello; rm -rf /']])->assertStatus(422);
        $v = $this->api($token)->postJson('/api/v1/app/vps', ['name' => 'web-1', 'plan' => 'ct-2', 'os' => 'ubuntu-2404', 'ssh_keys' => [self::KEY]])
            ->assertStatus(202)->assertJsonPath('data.status', 'provisioning')->json('data');
        $this->assertSame('ssh -p 22000 root@203.0.113.7', $v['ssh']);
        $this->api($token)->postJson('/api/v1/app/vps', ['name' => 'web-1', 'plan' => 'ct-2', 'os' => 'ubuntu-2404', 'ssh_keys' => [self::KEY]])->assertStatus(409);

        $cmd = AgentCommand::allTenants()->where('type', 'vps.create')->sole();
        $this->assertSame(2048, $cmd->payload['memory_mb']);
        $this->assertSame([self::KEY], $cmd->payload['authorized_keys']);
        $this->assertSame($server->id, $cmd->server_id);

        // Not before it exists.
        $this->api($token)->postJson("/api/v1/app/vps/{$v['id']}/stop")->assertStatus(409);
        $this->commandResult($secret, $cmd->id, 'succeeded', ['output' => ['container_id' => 'abc123', 'ssh_port' => 22000]])->assertOk();
        $this->api($token)->getJson("/api/v1/app/vps/{$v['id']}")->assertJsonPath('data.status', 'running');

        $this->api($token)->postJson("/api/v1/app/vps/{$v['id']}/stop")->assertStatus(202);
        $this->assertSame('abc123', AgentCommand::allTenants()->where('type', 'container.stop')->sole()->payload['container_id']);
        $this->api($token)->deleteJson("/api/v1/app/vps/{$v['id']}", ['confirm' => 'nope'])->assertStatus(422);
        $this->api($token)->deleteJson("/api/v1/app/vps/{$v['id']}", ['confirm' => 'web-1'])->assertStatus(202);
        $this->assertSame(0, Vps::allTenants()->count());
    }

    public function test_failures_quota_and_isolation(): void
    {
        [, $secret] = $this->host();
        [, , $a] = $this->org();
        [, , $b] = $this->org();
        config(['platform.vps.limit' => 1]);

        $v = $this->api($a)->postJson('/api/v1/app/vps', ['name' => 'box', 'plan' => 'ct-1', 'os' => 'debian-12', 'ssh_keys' => [self::KEY]])->assertStatus(202)->json('data');
        $this->api($a)->postJson('/api/v1/app/vps', ['name' => 'box2', 'plan' => 'ct-1', 'os' => 'debian-12', 'ssh_keys' => [self::KEY]])->assertStatus(409)->assertJsonPath('error.reason', 'quota');
        // The second customer gets the next SSH port on the same host.
        $w = $this->api($b)->postJson('/api/v1/app/vps', ['name' => 'box', 'plan' => 'ct-1', 'os' => 'debian-12', 'ssh_keys' => [self::KEY]])->assertStatus(202)->json('data');
        $this->assertSame(22001, $w['ssh_port']);

        $cmd = AgentCommand::allTenants()->where('type', 'vps.create')->oldest('created_at')->first();
        $this->commandResult($secret, $cmd->id, 'failed', ['message' => 'this server cannot host a VPS: the Sysbox runtime (sysbox-runc) is not installed'])->assertOk();
        $this->api($a)->getJson("/api/v1/app/vps/{$v['id']}")->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error', fn ($e) => str_contains($e, 'Sysbox'));

        $this->api($b)->getJson("/api/v1/app/vps/{$v['id']}")->assertNotFound();
        $this->api($b)->deleteJson("/api/v1/app/vps/{$v['id']}", ['confirm' => 'box'])->assertNotFound();
        $this->assertCount(1, $this->api($b)->getJson('/api/v1/app/vps')->json('data.servers'));
    }

    public function test_no_vps_host_means_no_capacity(): void
    {
        [, $platform, $staff] = $this->org();
        $this->server($platform, ['shared_pool' => true, 'roles' => ['app']]);
        [, , $token] = $this->org();
        $this->api($token)->postJson('/api/v1/app/vps', ['name' => 'x1', 'plan' => 'ct-1', 'os' => 'debian-12', 'ssh_keys' => [self::KEY]])
            ->assertStatus(409)->assertJsonPath('error.reason', 'no_capacity');
    }
}
