<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\BackupArtifact;
use App\Models\BackupPolicy;
use App\Models\Job;
use App\Models\Secret;
use App\Support\BackupDestination;
use App\Support\Tenant;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** BAK-002..005, UBAK-002: dumps, integrity checks, retention, restore tests, exports, key separation. */
class BackupExportTest extends TestCase
{
    private function appBackup(): array
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'site', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'size' => 'small', 'server_id' => $server->id]));
        $this->api($token)->postJson('/api/v1/app/backups', ['app_id' => $app->id])->assertStatus(202);
        $cmd = $this->heartbeat($secret)['commands'][0];
        $this->commandResult($secret, $cmd['id'], 'succeeded', ['output' => [
            'snapshot_id' => 'snap1', 'summary' => ['snapshot_id' => 'snap1', 'total_bytes_processed' => 2097152],
            'forget' => ['removed' => 1], 'check' => ['ok' => true, 'subset' => '5%'],
        ]])->assertOk();

        return [$org, $token, $secret, $app, $cmd];
    }

    public function test_backup_run_carries_retention_check_and_a_separate_repository_password(): void
    {
        [$org, $token, , $app, $cmd] = $this->appBackup();
        $p = $cmd['payload'];
        $this->assertSame(['keep_within' => '7d', 'keep_last' => 1, 'tag' => 'app-'.$app->id], $p['forget'], 'small plan keeps 7 days');
        $this->assertSame('5%', $p['check']['read_data_subset']);
        $this->assertSame('/var/lib/dockermgr/backups/'.$org->id.'/org', $p['repository']);
        $password = Secret::allTenants()->where('organization_id', $org->id)->where('name', 'restic-repository-password')->first();
        $this->assertSame($password->value, $p['env']['RESTIC_PASSWORD']);
        $escrow = Secret::allTenants()->where('organization_id', $org->id)->where('name', 'restic-recovery-escrow')->first();
        $this->assertStringStartsWith('escrow:', $escrow->value);
        $this->assertSame($password->value, BackupDestination::unescrow($escrow->value));

        $list = $this->api($token)->getJson('/api/v1/app/backups')->assertOk()->json('data.0');
        $this->assertTrue($list['verified']);
        $this->assertNotNull($list['verified_at']);
        $this->assertEquals(2, $list['size_mb']);
    }

    public function test_failed_integrity_check_fails_the_job_and_leaves_artifact_unverified(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'site', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'size' => 'small', 'server_id' => $server->id]));
        $job = $this->api($token)->postJson('/api/v1/app/backups', ['app_id' => $app->id])->json('data.job_id');
        $cmd = $this->heartbeat($secret)['commands'][0];
        $this->commandResult($secret, $cmd['id'], 'succeeded', ['output' => ['snapshot_id' => 's', 'check' => ['ok' => false, 'message' => 'pack abc: data corrupted']]]);
        $j = Job::allTenants()->find($job);
        $this->assertSame('failed', $j->status);
        $this->assertSame('failed', collect($j->stages)->firstWhere('name', 'verify')['status']);
        $this->assertFalse(BackupArtifact::allTenants()->first()->verified);
    }

    public function test_database_policy_dumps_through_docker_exec_and_restores_the_dump(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $dbId = $this->api($token)->postJson('/api/v1/databases', ['name' => 'main', 'engine' => 'postgres', 'server_id' => $server->id])->assertStatus(202)->json('data.database.id')
            ?? \App\Models\Database::allTenants()->value('id');
        $this->heartbeat($secret); // drain container.create
        $policy = $this->api($token)->postJson('/api/v1/backup-policies', ['name' => 'db', 'target_type' => 'database', 'target_id' => $dbId, 'retention_days' => 30, 'check_subset' => '10%'])->assertCreated()->json('data');
        $this->api($token)->postJson('/api/v1/backup-jobs', ['policy_id' => $policy['id']])->assertStatus(202);
        $cmd = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'backup.run');
        $dump = $cmd['payload']['dump'];
        $this->assertSame(['engine' => 'postgres', 'container' => 'db-main', 'database' => 'postgres', 'user' => 'postgres'], array_diff_key($dump, ['password' => 1]));
        $this->assertSame(Secret::allTenants()->where('name', 'main-password')->value('value'), $dump['password']);
        $this->assertSame([], $cmd['payload']['paths']);
        $this->assertSame(['keep_within' => '30d', 'keep_last' => 1, 'tag' => 'policy-'.$policy['id']], $cmd['payload']['forget']);
        $this->assertSame('10%', $cmd['payload']['check']['read_data_subset']);
        $this->assertStringEndsWith('/policy-'.$policy['id'], $cmd['payload']['repository']);
        $this->commandResult($secret, $cmd['id'], 'succeeded', ['output' => ['snapshot_id' => 'dbsnap', 'dump' => ['engine' => 'postgres', 'bytes' => 4096], 'check' => ['ok' => true, 'subset' => '10%']]]);
        $artifact = BackupArtifact::allTenants()->first();
        $this->assertSame('postgres', $artifact->dump_engine);
        $this->assertTrue($artifact->verified);
        $this->assertStringStartsWith('escrow:', BackupPolicy::allTenants()->find($policy['id'])->recovery_key, 'recovery copy lives on the policy, apart from the password secret');

        $this->api($token)->postJson('/api/v1/restore-jobs', ['artifact_id' => $artifact->id, 'fresh_backup' => false])->assertStatus(202);
        $restore = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'restore.run');
        $this->assertSame('dbsnap', $restore['payload']['snapshot']);
        $this->assertSame('postgres', $restore['payload']['dump']['engine']);
        $this->assertArrayNotHasKey('include', $restore['payload']);
    }

    public function test_restore_test_records_status(): void
    {
        [$org, $token, $secret] = $this->appBackup();
        $artifact = BackupArtifact::allTenants()->first();
        $this->api($token)->postJson("/api/v1/backup-artifacts/{$artifact->id}/restore-test")->assertStatus(202);
        $cmd = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'backup.test');
        $this->assertSame('snap1', $cmd['payload']['snapshot']);
        $this->commandResult($secret, $cmd['id'], 'succeeded', ['output' => ['files' => 12, 'bytes' => 20480, 'check' => ['ok' => true]]]);
        $this->api($token)->getJson('/api/v1/app/backups')->assertJsonPath('data.0.restore_test.status', 'passed')
            ->assertJsonPath('data.0.restore_test.message', 'Restored 12 file(s), 20 KiB');

        // Scheduled: customer app artifacts are picked by backups.restore_test_schedule.
        config(['backups.restore_test_schedule' => '* * * * *']);
        app(Tenant::class)->set(null);
        $this->artisan('backups:restore-tests')->expectsOutput('Restore tests started: 1')->assertSuccessful();
    }

    public function test_local_export_uploads_to_signed_url_and_downloads_the_real_file(): void
    {
        Storage::fake('local');
        [$org, $token, $secret] = $this->appBackup();
        $artifact = BackupArtifact::allTenants()->first();
        $first = $this->api($token)->getJson("/api/v1/app/backups/{$artifact->id}/download")->assertStatus(202)->json('data');
        $this->assertSame('exporting', $first['status']);
        $this->api($token)->getJson("/api/v1/app/backups/{$artifact->id}/download")->assertStatus(202)->assertJsonPath('data.job_id', $first['job_id']);

        $cmd = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'backup.export');
        $this->assertSame('/var/lib/docker/volumes/app-site-data/_data', $cmd['payload']['path']);
        $url = $cmd['payload']['upload']['url'];
        $this->assertStringStartsWith('https://panel.test/agent/v1/backup-exports/'.$artifact->id.'?expires=', $url);
        $path = substr($url, strlen('https://panel.test'));

        $this->call('PUT', '/agent/v1/backup-exports/'.$artifact->id, [], [], [], [], 'forged')->assertForbidden();
        $archive = gzencode("fake tar\n");
        $this->call('PUT', $path, [], [], [], ['HTTP_X_CONTENT_SHA256' => hash('sha256', $archive), 'CONTENT_TYPE' => 'application/gzip'], $archive)->assertCreated();
        $this->commandResult($secret, $cmd['id'], 'succeeded', ['output' => ['bytes' => strlen($archive), 'sha256' => hash('sha256', $archive)]]);
        $this->assertSame('succeeded', Job::allTenants()->find($first['job_id'])->status);

        $ready = $this->api($token)->getJson("/api/v1/app/backups/{$artifact->id}/download")->assertOk()->json('data');
        $this->assertSame('ready', $ready['status']);
        $this->assertSame(hash('sha256', $archive), $ready['sha256']);
        $file = $this->withHeaders(['Authorization' => ''])->get($ready['url'])->assertOk();
        $this->assertSame($archive, $file->streamedContent());
        $this->get('/api/v1/backup-download/'.$artifact->id)->assertForbidden();
    }

    public function test_s3_destination_uses_presigned_urls_and_keeps_keys_write_only(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $volumeId = $this->api($token)->postJson('/api/v1/volumes', ['server_id' => $server->id, 'name' => 'data'])->json('data.volume.id');
        $this->heartbeat($secret);
        $this->api($token)->postJson('/api/v1/backup-policies', ['name' => 'p', 'target_type' => 'volume', 'target_id' => $volumeId, 'destination' => 's3',
            'destination_config' => ['endpoint' => 'https://s3.example.test']])->assertStatus(422)->assertJsonPath('error.fields', fn ($f) => isset($f['destination_config.bucket']));
        $policy = $this->api($token)->postJson('/api/v1/backup-policies', ['name' => 'p', 'target_type' => 'volume', 'target_id' => $volumeId, 'destination' => 's3',
            'destination_config' => ['endpoint' => 'https://minio.example.test:9000', 'bucket' => 'bk', 'region' => 'eu-1', 'access_key' => 'AKIA1', 'secret_key' => 'SECRET1']])->assertCreated();
        $this->assertStringNotContainsString('SECRET1', $policy->getContent());
        $policy->assertJsonPath('data.destination_summary.has_secret_key', true)->assertJsonPath('data.destination_summary.bucket', 'bk');
        $id = $policy->json('data.id');
        $this->api($token)->patchJson("/api/v1/backup-policies/{$id}", ['destination_config' => ['endpoint' => 'https://minio.example.test:9000', 'bucket' => 'bk2', 'region' => 'eu-1', 'access_key' => '', 'secret_key' => '']])->assertOk();
        $this->assertSame('SECRET1', BackupPolicy::allTenants()->find($id)->destination_config['secret_key'], 'blank keys keep the stored ones');

        $this->api($token)->postJson('/api/v1/backup-jobs', ['policy_id' => $id])->assertStatus(202);
        $cmd = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'backup.run');
        $this->assertSame('s3:https://minio.example.test:9000/bk2/dockermgr/'.$org->id.'/policy-'.$id, $cmd['payload']['repository']);
        $this->assertSame(['AKIA1', 'SECRET1', 'eu-1'], [$cmd['payload']['env']['AWS_ACCESS_KEY_ID'], $cmd['payload']['env']['AWS_SECRET_ACCESS_KEY'], $cmd['payload']['env']['AWS_DEFAULT_REGION']]);
        $this->commandResult($secret, $cmd['id'], 'succeeded', ['output' => ['snapshot_id' => 'x']]);
        $artifact = BackupArtifact::allTenants()->first();

        $this->api($token)->getJson("/api/v1/backup-artifacts/{$artifact->id}/download")->assertStatus(202);
        $export = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'backup.export');
        $put = $export['payload']['upload']['url'];
        $this->assertStringStartsWith('https://minio.example.test:9000/bk2/dockermgr/'.$org->id.'/policy-'.$id.'/exports/'.$artifact->id.'.tar.gz?', $put);
        $this->assertStringContainsString('X-Amz-Signature=', $put);
        $this->commandResult($secret, $export['id'], 'succeeded', ['output' => ['bytes' => 10, 'sha256' => str_repeat('a', 64)]]);
        $get = $this->api($token)->getJson("/api/v1/backup-artifacts/{$artifact->id}/download")->assertOk()->json('data.url');
        $this->assertStringContainsString('/exports/'.$artifact->id.'.tar.gz?', $get);
        $this->assertStringContainsString('X-Amz-Expires=900', $get);
        $this->assertStringContainsString('response-content-disposition=attachment', $get);
    }
}
