<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\BackupArtifact;
use App\Models\Job;
use App\Models\RestoreJob;
use App\Support\Tenant;
use Tests\TestCase;

class BackupTest extends TestCase
{
    public function test_customer_restore_takes_a_fresh_backup_first(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'site', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'size' => 'small', 'server_id' => $server->id]));

        $this->api($token)->postJson('/api/v1/app/backups', ['app_id' => $app->id])->assertStatus(202);
        $cmd = $this->heartbeat($secret)['commands'][0];
        $this->assertSame('backup.run', $cmd['type']);
        $this->assertSame(['/var/lib/docker/volumes/app-site-data/_data'], $cmd['payload']['paths']);
        $this->assertArrayHasKey('RESTIC_PASSWORD', $cmd['payload']['env']);
        $this->commandResult($secret, $cmd['id'], 'succeeded', ['output' => ['summary' => ['snapshot_id' => 'snap1', 'total_bytes_processed' => 10485760]]]);

        $backups = $this->api($token)->getJson("/api/v1/app/backups?app_id={$app->id}")->assertOk()->json('data');
        $this->assertCount(1, $backups);
        $this->assertEquals(10, $backups[0]['size_mb']);

        $restore = $this->api($token)->postJson("/api/v1/app/backups/{$backups[0]['id']}/restore")->assertStatus(202)->json('data');
        $pre = $this->heartbeat($secret)['commands'];
        $this->assertSame(['backup.run'], array_column($pre, 'type'), 'safety backup runs before the restore');
        $this->commandResult($secret, $pre[0]['id'], 'succeeded', ['output' => ['snapshot_id' => 'snap2']]);

        $restoreCmd = $this->heartbeat($secret)['commands'];
        $this->assertSame(['restore.run'], array_column($restoreCmd, 'type'));
        $this->assertSame('snap1', $restoreCmd[0]['payload']['snapshot']);
        $this->commandResult($secret, $restoreCmd[0]['id'], 'succeeded');

        $this->assertSame('succeeded', Job::allTenants()->find($restore['job_id'])->status);
        $this->assertSame('succeeded', RestoreJob::allTenants()->find($restore['restore_job_id'])->status);
        $this->assertSame(2, BackupArtifact::allTenants()->count());
        // Download starts an export first (BackupExportTest covers the full flow).
        $this->api($token)->getJson("/api/v1/app/backups/{$backups[0]['id']}/download")->assertStatus(202)->assertJsonStructure(['data' => ['job_id', 'status']]);
    }

    public function test_policies_require_encryption_for_remote_and_run_on_schedule(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        $volumeId = $this->api($token)->postJson('/api/v1/volumes', ['server_id' => $server->id, 'name' => 'data'])->assertStatus(202)->json('data.volume.id');
        $this->api($token)->postJson('/api/v1/backup-policies', ['name' => 'p', 'target_type' => 'volume', 'target_id' => $volumeId, 'destination' => 's3', 'encrypted' => false])->assertStatus(422);
        $policy = $this->api($token)->postJson('/api/v1/backup-policies', ['name' => 'p', 'target_type' => 'volume', 'target_id' => $volumeId, 'destination' => 's3', 'schedule' => '* * * * *', 'retention_days' => 7,
            'destination_config' => ['endpoint' => 'https://s3.example.test', 'bucket' => 'bk', 'access_key' => 'AK', 'secret_key' => 'SK']])
            ->assertCreated()->assertJsonMissingPath('data.recovery_key')->assertJsonCount(14, 'data.success_14d')->json('data');
        $this->api($token)->getJson('/api/v1/volumes')->assertJsonPath('data.0.has_backup_policy', true);

        app(Tenant::class)->set(null);
        $this->artisan('backups:run-due')->assertSuccessful();
        $this->api($token)->getJson('/api/v1/backup-jobs')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.backup_policy_id', $policy['id']);
        $this->api($token)->postJson('/api/v1/backup-jobs', ['policy_id' => $policy['id']])->assertStatus(202);
    }
}
