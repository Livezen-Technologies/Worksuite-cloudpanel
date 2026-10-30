<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\BackupArtifact;
use App\Models\BackupPolicy;
use App\Models\Job;
use App\Models\RestoreJob;
use App\Models\Volume;
use App\Support\Tenant;
use Tests\TestCase;

/** BKM-001..008: summary, retention, run-all, filters, restore wizard, destinations, object lock. */
class BackupManagerTest extends TestCase
{
    private function setUpServer(): array
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);

        return [$org, $token, $secret, $server];
    }

    private function policy(string $token, array $attrs): array
    {
        return $this->api($token)->postJson('/api/v1/backup-policies', $attrs + ['name' => 'P', 'schedule' => '0 3 * * *', 'destination' => 'local', 'retention_days' => 30])->assertCreated()->json('data');
    }

    private function succeed(string $secret, string $snapshot = 'snap1', int $bytes = 10485760): array
    {
        $cmd = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'backup.run');
        $this->commandResult($secret, $cmd['id'], 'succeeded', ['output' => ['snapshot_id' => $snapshot, 'summary' => ['snapshot_id' => $snapshot, 'total_bytes_processed' => $bytes], 'check' => ['ok' => true, 'subset' => '5%']]])->assertOk();

        return $cmd;
    }

    public function test_daily_weekly_monthly_retention_and_object_lock_reach_restic(): void
    {
        [$org, $token, $secret, $server] = $this->setUpServer();
        $volume = app(Tenant::class)->as($org->id, fn () => Volume::create(['name' => 'shop-data', 'server_id' => $server->id]));
        $p = $this->policy($token, ['target_type' => 'volume', 'target_id' => $volume->id, 'keep_daily' => 7, 'keep_weekly' => 4, 'keep_monthly' => 6]);
        $this->api($token)->postJson('/api/v1/backup-jobs', ['policy_id' => $p['id']])->assertStatus(202);
        $cmd = $this->succeed($secret);
        $this->assertSame(['keep_daily' => 7, 'keep_weekly' => 4, 'keep_monthly' => 6, 'keep_last' => 1, 'tag' => 'policy-'.$p['id']], $cmd['payload']['forget']);

        $this->api($token)->patchJson("/api/v1/backup-policies/{$p['id']}", ['object_lock_days' => 30])->assertStatus(422)->assertSee('S3, R2 or B2');
        $s3 = $this->policy($token, ['target_type' => 'volume', 'target_id' => $volume->id, 'destination' => 's3', 'retention_days' => 14,
            'destination_config' => ['bucket' => 'locked', 'access_key' => 'AK', 'secret_key' => 'SK']]);
        $this->api($token)->patchJson("/api/v1/backup-policies/{$s3['id']}", ['object_lock_days' => 30])->assertStatus(422)->assertSee('retention window');
        $this->api($token)->patchJson("/api/v1/backup-policies/{$s3['id']}", ['object_lock_days' => 14])->assertOk();
        $this->api($token)->postJson('/api/v1/backup-jobs', ['policy_id' => $s3['id']])->assertStatus(202);
        $cmd = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'backup.run');
        $this->assertTrue($cmd['payload']['forget']['no_prune'], 'a locked bucket is never pruned');
    }

    public function test_summary_run_all_and_snapshot_filters(): void
    {
        [$org, $token, $secret, $server] = $this->setUpServer();
        [$a, $b] = app(Tenant::class)->as($org->id, fn () => [Volume::create(['name' => 'a', 'server_id' => $server->id]), Volume::create(['name' => 'b', 'server_id' => $server->id])]);
        $pa = $this->policy($token, ['target_type' => 'volume', 'target_id' => $a->id]);
        $pb = $this->policy($token, ['target_type' => 'volume', 'target_id' => $b->id]);
        $this->api($token)->postJson('/api/v1/backup-jobs', ['policy_id' => $pa['id']])->assertStatus(202);
        $this->succeed($secret);
        $this->api($token)->postJson('/api/v1/backup-jobs', ['policy_id' => $pb['id']])->assertStatus(202);
        $cmd = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'backup.run');
        $this->commandResult($secret, $cmd['id'], 'failed', ['message' => 'disk full'])->assertOk();

        $sum = $this->api($token)->getJson('/api/v1/backups/summary')->assertOk()->json('data');
        $this->assertSame(2, $sum['protected_targets']);
        $this->assertEquals(50, $sum['success_rate_30d']);
        $this->assertEquals(10, $sum['stored_mb']);
        $this->assertNotNull($sum['next_run_at']);

        $this->api($token)->postJson('/api/v1/backups/run-all', ['which' => 'failed'])->assertStatus(202)->assertJsonPath('data.started', 1);
        $types = array_column($this->heartbeat($secret)['commands'], 'type');
        $this->assertSame(['backup.run'], $types);

        $this->api($token)->patchJson("/api/v1/backup-policies/{$pa['id']}", ['enabled' => false])->assertOk();
        $this->assertSame(1, $this->api($token)->getJson('/api/v1/backups/summary')->json('data.paused'));

        $rows = $this->api($token)->getJson("/api/v1/backup-artifacts?target_type=volume&target_id={$a->id}")->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($a->id, $rows[0]['target_id']);
        $this->assertCount(0, $this->api($token)->getJson("/api/v1/backup-artifacts?target_id={$b->id}")->json('data'));
    }

    public function test_restore_wizard_stops_the_app_restores_side_by_side_and_checks_health(): void
    {
        [$org, $token, $secret, $server] = $this->setUpServer();
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'site', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'size' => 'small', 'server_id' => $server->id, 'port' => 80]));
        $this->api($token)->postJson('/api/v1/app/backups', ['app_id' => $app->id])->assertStatus(202);
        $this->succeed($secret);
        $artifact = BackupArtifact::allTenants()->firstOrFail();

        $this->api($token)->postJson('/api/v1/restore-jobs', ['artifact_id' => $artifact->id, 'scope' => 'database'])->assertStatus(422);

        // Replace in place: stop, restore, start, health check.
        $res = $this->api($token)->postJson('/api/v1/restore-jobs', ['artifact_id' => $artifact->id, 'fresh_backup' => false, 'stop_app' => true, 'health_check' => true])->assertStatus(202)->json('data');
        $cmds = $this->heartbeat($secret)['commands'];
        $this->assertSame(['container.stop', 'restore.run'], array_column($cmds, 'type'));
        $this->assertSame('/', $cmds[1]['payload']['target']);
        foreach ($cmds as $c) {
            $this->commandResult($secret, $c['id'], 'succeeded')->assertOk();
        }
        $start = $this->heartbeat($secret)['commands'];
        $this->assertSame(['container.start'], array_column($start, 'type'));
        $this->commandResult($secret, $start[0]['id'], 'succeeded')->assertOk();
        $hc = $this->heartbeat($secret)['commands'];
        $this->assertSame(['container.healthcheck'], array_column($hc, 'type'));
        $this->commandResult($secret, $hc[0]['id'], 'succeeded', ['output' => ['healthy' => true]])->assertOk();
        $job = Job::allTenants()->find($res['job_id']);
        $this->assertSame('succeeded', $job->status);
        $this->assertSame(['stop_app', 'restore', 'start_app', 'health_check', 'complete'], array_column($job->stages, 'name'));

        // Side by side: into its own directory, the app keeps running.
        $res = $this->api($token)->postJson('/api/v1/restore-jobs', ['artifact_id' => $artifact->id, 'fresh_backup' => false, 'mode' => 'side_by_side', 'stop_app' => true])->assertStatus(202)->json('data');
        $cmds = $this->heartbeat($secret)['commands'];
        $this->assertSame(['restore.run'], array_column($cmds, 'type'), 'side by side never stops the app');
        $restore = RestoreJob::allTenants()->find($res['restore_job_id']);
        $this->assertSame('/var/lib/dockermgr/restores/'.$restore->id, $cmds[0]['payload']['target']);
        $this->commandResult($secret, $cmds[0]['id'], 'succeeded')->assertOk();
        $this->assertSame('succeeded', $restore->fresh()->status);
    }

    public function test_a_failed_restore_still_starts_the_app_again(): void
    {
        [$org, $token, $secret, $server] = $this->setUpServer();
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'site', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'size' => 'small', 'server_id' => $server->id]));
        $this->api($token)->postJson('/api/v1/app/backups', ['app_id' => $app->id])->assertStatus(202);
        $this->succeed($secret);
        $artifact = BackupArtifact::allTenants()->firstOrFail();
        $this->api($token)->postJson('/api/v1/restore-jobs', ['artifact_id' => $artifact->id, 'fresh_backup' => false, 'stop_app' => true])->assertStatus(202);
        $cmds = $this->heartbeat($secret)['commands'];
        $this->commandResult($secret, $cmds[0]['id'], 'succeeded')->assertOk();
        $this->commandResult($secret, $cmds[1]['id'], 'failed', ['message' => 'snapshot not found'])->assertOk();
        $this->assertSame(['container.start'], array_column($this->heartbeat($secret)['commands'], 'type'));
        $this->assertSame('failed', RestoreJob::allTenants()->first()->status);
    }

    public function test_sftp_destination_probe_and_usage_warning(): void
    {
        [$org, $token, $secret, $server] = $this->setUpServer();
        $volume = app(Tenant::class)->as($org->id, fn () => Volume::create(['name' => 'shop-data', 'server_id' => $server->id]));
        $this->api($token)->postJson('/api/v1/backup-policies', ['name' => 'Off-site', 'target_type' => 'volume', 'target_id' => $volume->id, 'destination' => 'sftp', 'destination_config' => ['host' => 'backup.example.com']])
            ->assertStatus(422);
        $key = "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAA\n-----END OPENSSH PRIVATE KEY-----";
        $p = $this->policy($token, ['target_type' => 'volume', 'target_id' => $volume->id, 'destination' => 'sftp', 'capacity_gb' => 1,
            'destination_config' => ['host' => 'backup.example.com', 'user' => 'restic', 'path' => '/srv/backups', 'private_key' => $key]]);
        $this->assertTrue($p['destination_summary']['has_private_key']);
        $this->assertArrayNotHasKey('private_key', $p['destination_summary'], 'the key is write-only');

        $this->api($token)->postJson("/api/v1/backup-policies/{$p['id']}/probe")->assertStatus(202);
        $probe = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'backup.probe');
        $this->assertStringStartsWith('rclone:dmpsftp:/srv/backups/', $probe['payload']['repository']);
        $this->assertSame('backup.example.com', $probe['payload']['env']['RCLONE_CONFIG_DMPSFTP_HOST']);
        $this->assertStringNotContainsString("\n", $probe['payload']['env']['RCLONE_CONFIG_DMPSFTP_KEY_PEM']);
        $this->commandResult($secret, $probe['id'], 'succeeded', ['output' => ['reachable' => true, 'snapshots' => 3, 'stored_bytes' => 966367641]])->assertOk();

        $usage = $this->api($token)->getJson("/api/v1/backup-policies/{$p['id']}")->assertOk()->json('data.usage');
        $this->assertEquals(90, $usage['percent']);
        $this->assertTrue($usage['warning'], 'above 85 % full');
        $this->assertTrue(BackupPolicy::allTenants()->find($p['id'])->last_probe['reachable']);
    }
}
