<?php

namespace Tests\Feature;

use App\Models\BackupArtifact;
use App\Models\BackupJob;
use App\Models\BackupPolicy;
use App\Services\BackupService;
use App\Support\Tenant;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** External policies: backups made by a job outside the panel that reports each run here. */
class BackupExternalPolicyTest extends TestCase
{
    private function external(): array
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        $p = $this->api($token)->postJson('/api/v1/backup-policies', [
            'name' => 'Host DB dumps', 'target_type' => 'server', 'target_id' => $server->id, 'method' => 'external',
            'schedule' => '15 2 * * *', 'destination' => 'local', 'retention_days' => 30,
        ])->assertCreated()->json('data');

        return [$org, $token, $server, $p];
    }

    public function test_external_policy_is_never_run_by_the_panel(): void
    {
        [$org, $token, , $p] = $this->external();
        $this->travelTo(now()->setTime(2, 15));
        $this->assertFalse(app(BackupService::class)->dueSchedules()->contains('id', $p['id']));
        $this->api($token)->postJson('/api/v1/backup-jobs', ['policy_id' => $p['id']])->assertStatus(409);
        $this->assertSame(0, app(Tenant::class)->as($org->id, fn () => BackupJob::count()));
    }

    public function test_recording_a_run_shows_it_in_the_summary_and_snapshots(): void
    {
        [$org, $token, , $p] = $this->external();
        $manifest = tempnam(sys_get_temp_dir(), 'm');
        file_put_contents($manifest, "gpet-db\t2097152\tabc123\tsftp://backup/db/2026-11-19\nshop-db\t1048576\tdef456\tsftp://backup/db/2026-11-19\n");

        $this->assertSame(0, Artisan::call('backups:record-external', ['policy' => $p['id'], '--status' => 'succeeded', '--manifest' => $manifest]));

        $artifacts = app(Tenant::class)->as($org->id, fn () => BackupArtifact::where('backup_policy_id', $p['id'])->get());
        $this->assertCount(2, $artifacts);
        $this->assertEquals(2.0, (float) $artifacts->firstWhere('snapshot_id', 'gpet-db')->size_mb);
        $summary = app(Tenant::class)->as($org->id, fn () => app(BackupService::class)->summary());
        $this->assertSame(1, $summary['runs_30d']);
        $this->assertEquals(100, $summary['success_rate_30d']);
        $this->assertSame(1, $summary['protected_targets']);
        $this->assertNotNull($summary['next_run_at']);
        $this->assertSame('succeeded', BackupPolicy::allTenants()->find($p['id'])->last_status);

        $this->api($token)->postJson("/api/v1/backup-artifacts/{$artifacts[0]->id}/restore-test", [])->assertStatus(409);
    }

    public function test_a_failed_run_is_recorded_without_artifacts(): void
    {
        [$org, , , $p] = $this->external();
        $this->assertSame(0, Artisan::call('backups:record-external', ['policy' => $p['id'], '--status' => 'failed', '--error' => 'upload=FAILED']));
        $job = app(Tenant::class)->as($org->id, fn () => BackupJob::where('backup_policy_id', $p['id'])->sole());
        $this->assertSame('failed', $job->status);
        $this->assertSame('upload=FAILED', $job->error);
        $this->assertSame(0, app(Tenant::class)->as($org->id, fn () => BackupArtifact::count()));
    }

    public function test_run_log_is_kept_and_fetched_per_job(): void
    {
        [$org, $token, , $p] = $this->external();
        $log = tempnam(sys_get_temp_dir(), 'l');
        file_put_contents($log, "dump gpet-db ok\nupload FAILED: connection reset\n");
        $this->assertSame(0, Artisan::call('backups:record-external', ['policy' => $p['id'], '--status' => 'failed', '--error' => 'upload=FAILED', '--log' => $log]));
        $job = app(Tenant::class)->as($org->id, fn () => BackupJob::where('backup_policy_id', $p['id'])->sole());

        $list = $this->api($token)->getJson('/api/v1/backup-jobs')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('log', $list);
        $this->api($token)->getJson("/api/v1/backup-jobs/{$job->id}/log")->assertOk()
            ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error', 'upload=FAILED')
            ->assertSee('connection reset');
    }

    public function test_seed_defaults_adds_the_overdue_rule_once(): void
    {
        [, $org] = $this->org();
        $this->assertSame(0, Artisan::call('alerts:seed-defaults'));
        $this->assertSame(0, Artisan::call('alerts:seed-defaults'));
        $this->assertSame(1, \App\Models\AlertRule::allTenants()->where('organization_id', $org->id)->where('metric', 'backup_overdue')->count());
    }
}
