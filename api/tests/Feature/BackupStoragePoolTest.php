<?php

namespace Tests\Feature;

use App\Models\BackupPolicy;
use App\Models\BackupStorageServer;
use App\Services\BackupPoolService;
use App\Support\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/** Backup manager → Storage pool. */
class BackupStoragePoolTest extends TestCase
{
    public function test_add_server_generates_a_key_and_hides_the_private_half(): void
    {
        [, , $token] = $this->org();
        $s = $this->api($token)->postJson('/api/v1/backup-storage-servers', ['name' => 'Backup 1', 'host' => '40.160.59.90', 'username' => 'panelbackup'])
            ->assertCreated()->json('data');
        $this->assertStringStartsWith('ssh-ed25519 ', $s['public_key']);
        $this->assertArrayNotHasKey('private_key', $s);
        $this->assertSame('untested', $s['status']);
        $this->assertSame('backups', $s['path']);
        $this->assertStringContainsString('OPENSSH', BackupStorageServer::allTenants()->find($s['id'])->private_key);

        $this->api($token)->postJson('/api/v1/backup-storage-servers', ['name' => 'Bad', 'host' => 'x; rm -rf /', 'username' => 'u'])->assertStatus(422);
        $this->api($token)->postJson('/api/v1/backup-storage-servers', ['name' => 'Bad', 'host' => 'h', 'username' => 'u', 'path' => '../etc'])->assertStatus(422);
    }

    public function test_check_pins_host_keys_records_space_and_pick_prefers_most_free(): void
    {
        [, $org, $token] = $this->org();
        Process::fake([
            '*ssh-keyscan*' => Process::result("40.160.59.90 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExample\n"),
            '*rclone*about*' => Process::result('{"total":4000000000000,"used":1000000000000,"free":3000000000000}'),
            '*' => Process::result(''),
        ]);
        $a = $this->api($token)->postJson('/api/v1/backup-storage-servers', ['name' => 'A', 'host' => '40.160.59.90', 'username' => 'pb'])->json('data');
        $checked = $this->api($token)->postJson("/api/v1/backup-storage-servers/{$a['id']}/check")->assertOk()->json('data');
        $this->assertSame('ok', $checked['status']);
        $this->assertSame(25.0, (float) $checked['usage_percent']);
        $this->assertStringContainsString('ssh-ed25519', BackupStorageServer::allTenants()->find($a['id'])->host_keys);

        $b = app(Tenant::class)->as($org->id, fn () => BackupStorageServer::create(['name' => 'B', 'host' => 'b.example', 'username' => 'pb', 'private_key' => 'k', 'public_key' => 'p', 'status' => 'ok', 'free_bytes' => 10]));
        $this->assertSame($a['id'], app(BackupPoolService::class)->pick($org->id)->id);
        $this->api($token)->patchJson("/api/v1/backup-storage-servers/{$a['id']}", ['enabled' => false])->assertOk();
        $this->assertSame($b->id, app(BackupPoolService::class)->pick($org->id)->id);
    }

    public function test_failed_check_does_not_pin_the_key(): void
    {
        [, , $token] = $this->org();
        Process::fake([
            '*ssh-keyscan*' => Process::result("h ssh-ed25519 AAAA\n"),
            '*rclone*about*' => Process::result('', 'Failed to create file system: ssh: handshake failed: unable to authenticate', 1),
            '*' => Process::result(''),
        ]);
        $a = $this->api($token)->postJson('/api/v1/backup-storage-servers', ['name' => 'A', 'host' => 'h.example', 'username' => 'pb'])->json('data');
        $r = $this->api($token)->postJson("/api/v1/backup-storage-servers/{$a['id']}/check")->assertOk()->json('data');
        $this->assertSame('error', $r['status']);
        $this->assertStringContainsString('unable to authenticate', $r['message']);
        $this->assertNull(BackupStorageServer::allTenants()->find($a['id'])->host_keys);
    }

    public function test_record_external_by_name_creates_one_policy_per_site(): void
    {
        [, $org] = $this->org();
        $server = $this->server($org);
        $args = ['policy' => '-', '--name' => 'Site: gpet', '--org' => $org->id, '--server' => $server->id, '--retention' => 3];
        $this->assertSame(0, Artisan::call('backups:record-external', $args));
        $this->assertSame(0, Artisan::call('backups:record-external', $args));
        $p = BackupPolicy::allTenants()->where('name', 'Site: gpet')->sole();
        $this->assertSame('external', $p->method);
        $this->assertSame(3, (int) $p->retention_days);
        $this->assertTrue((bool) $p->enabled);
    }
}
