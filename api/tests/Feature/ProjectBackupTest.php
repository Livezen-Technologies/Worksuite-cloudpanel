<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\BackupJob;
use App\Models\BackupPolicy;
use App\Models\EnvVar;
use App\Models\Project;
use App\Models\RestoreJob;
use App\Services\BackupService;
use App\Support\Tenant;
use Tests\TestCase;

/** Project-level backup and restore: fan-out to apps, roll-up, configuration snapshot, schedule, restore. */
class ProjectBackupTest extends TestCase
{
    private function setUpProject(): array
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        [$project, $web, $api] = app(Tenant::class)->as($org->id, function () use ($server) {
            $p = Project::create(['name' => 'WorkSuite Cloud']);
            $web = Application::create(['name' => 'web', 'source_type' => 'image', 'image' => 'nginx:1', 'port' => 80, 'server_id' => $server->id, 'project_id' => $p->id, 'volume_path' => '/data', 'cpu_limit' => 2]);
            $api = Application::create(['name' => 'api', 'source_type' => 'image', 'image' => 'acme/api:3', 'port' => 8080, 'server_id' => $server->id, 'project_id' => $p->id]);
            EnvVar::create(['application_id' => $web->id, 'key' => 'APP_KEY', 'value' => 'super-secret']);

            return [$p, $web, $api];
        });

        return [$org, $token, $secret, $project, $web, $api];
    }

    /** Answers every pending backup.run command; $fail lists app ids whose backup fails. */
    private function finish(string $secret, array $fail = []): int
    {
        $n = 0;
        foreach (collect($this->heartbeat($secret)['commands'])->where('type', 'backup.run') as $cmd) {
            $job = BackupJob::allTenants()->where('job_id', $cmd['job_id'] ?? null)->first()
                ?? BackupJob::allTenants()->whereIn('status', ['running'])->whereNotNull('parent_id')->get()->first(fn ($b) => in_array('job-'.$b->id, $cmd['payload']['tags'] ?? [], true));
            $bad = $job && in_array($job->application_id, $fail, true);
            $this->commandResult($secret, $cmd['id'], $bad ? 'failed' : 'succeeded', $bad ? ['message' => 'disk full'] : ['output' => ['snapshot_id' => 'snap'.$n, 'summary' => ['snapshot_id' => 'snap'.$n, 'total_bytes_processed' => 10485760], 'check' => ['ok' => true, 'subset' => '5%']]])->assertOk();
            $n++;
        }

        return $n;
    }

    public function test_project_backup_fans_out_rolls_up_and_snapshots_configuration(): void
    {
        [$org, $token, $secret, $project, $web] = $this->setUpProject();
        $run = $this->api($token)->postJson("/api/v1/projects/{$project->id}/backups")->assertStatus(202)->json('data');
        $parent = BackupJob::allTenants()->find($run['id']);
        $this->assertSame('running', $parent->status);
        $this->assertSame(2, BackupJob::allTenants()->where('parent_id', $parent->id)->count());
        $this->assertStringNotContainsString('super-secret', json_encode($parent->metadata));
        $this->assertSame(['APP_KEY'], collect($parent->metadata['applications'])->firstWhere('name', 'web')['env_keys']);

        $this->assertSame(2, $this->finish($secret));
        $this->assertSame('succeeded', $parent->fresh()->status);

        $d = $this->api($token)->getJson("/api/v1/project-backups/{$parent->id}")->assertOk()->json('data');
        $this->assertSame('WorkSuite Cloud', $d['project']);
        $this->assertEquals(20.0, $d['size_mb']);
        $this->assertTrue($d['verified']);
        $this->assertCount(2, $d['applications']);
        $list = $this->api($token)->getJson("/api/v1/projects/{$project->id}/backups")->assertOk()->json('data');
        $this->assertSame($parent->id, $list['backups'][0]['id']);
    }

    public function test_one_failed_app_makes_the_backup_partial(): void
    {
        [, $token, $secret, $project, , $api] = $this->setUpProject();
        $run = $this->api($token)->postJson("/api/v1/projects/{$project->id}/backups")->json('data');
        $this->finish($secret, [$api->id]);
        $parent = BackupJob::allTenants()->find($run['id']);
        $this->assertSame('partial', $parent->status);
        $this->assertStringContainsString('api', $parent->error);
    }

    public function test_restore_always_takes_a_recovery_point_and_respects_scope(): void
    {
        [, $token, $secret, $project, $web] = $this->setUpProject();
        $run = $this->api($token)->postJson("/api/v1/projects/{$project->id}/backups")->json('data');
        $this->finish($secret);

        $this->api($token)->postJson("/api/v1/project-backups/{$run['id']}/restore", ['scope' => 'project'])->assertStatus(422); // confirm required
        $r = $this->api($token)->postJson("/api/v1/project-backups/{$run['id']}/restore", ['confirm' => true, 'scope' => 'applications', 'application_ids' => [$web->id], 'mode' => 'separate'])
            ->assertStatus(202)->json('data');
        $this->assertCount(1, $r['restored']);
        $restore = RestoreJob::allTenants()->find($r['restored'][0]['restore_job_id']);
        $this->assertNotNull($restore->pre_restore_backup_job_id, 'a recovery point is always taken first');
        $this->assertSame('side_by_side', $restore->mode);
        $this->assertSame($run['id'], $restore->project_backup_job_id);

        $d = $this->api($token)->getJson("/api/v1/project-backups/{$run['id']}")->json('data');
        $this->assertCount(1, $d['restores']);
        $this->assertSame('web', $d['restores'][0]['application']);
    }

    public function test_schedule_creates_a_project_policy_that_the_scheduler_runs(): void
    {
        [$org, $token, , $project] = $this->setUpProject();
        $s = $this->api($token)->putJson("/api/v1/projects/{$project->id}/backup-schedule", ['enabled' => true, 'frequency' => 'daily', 'retention_days' => 7, 'capacity_gb' => 50])->assertOk()->json('data');
        $this->assertTrue($s['enabled']);
        $this->assertSame('daily', $s['frequency']);
        $policy = BackupPolicy::allTenants()->where('target_type', 'project')->sole();
        $this->assertSame('30 20 * * *', $policy->schedule);

        $parent = app(Tenant::class)->as($org->id, fn () => app(BackupService::class)->run($policy, 'project', $project->id, 'schedule'));
        $this->assertSame('project', $parent->target_type);
        $this->assertSame($policy->id, $parent->backup_policy_id);
        $this->assertSame(2, BackupJob::allTenants()->where('parent_id', $parent->id)->count());

        $this->api($token)->putJson("/api/v1/projects/{$project->id}/backup-schedule", ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);
    }
}
