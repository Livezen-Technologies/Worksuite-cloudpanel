<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Application;
use App\Models\EnvVar;
use App\Models\ScheduledJob;
use App\Models\ScheduledJobRun;
use App\Models\Server;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** CRON-1..9: run modes, overlap, retries, alerts, history, limits and the admin view. */
class CronEngineTest extends TestCase
{
    private function setUpApp(): array
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'shop', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'ghcr.io/acme/shop:1', 'server_id' => $server->id, 'port' => 80, 'status' => 'running']));

        return [$org, $token, $secret, $app, $server];
    }

    private function job(string $token, array $attrs): array
    {
        return $this->api($token)->postJson('/api/v1/app/cron', $attrs + ['name' => 'Job', 'action' => 'app_command', 'cron' => '*/5 * * * *'])->assertCreated()->json('data');
    }

    /** Hands every queued cron.exec back with the given exit code. */
    private function finish(string $secret, int $exit, string $output = ''): array
    {
        $done = [];
        foreach ($this->heartbeat($secret)['commands'] as $c) {
            if ($c['type'] === 'cron.exec') {
                $this->commandResult($secret, $c['id'], 'succeeded', ['output' => ['exit_code' => $exit, 'output' => $output, 'duration_ms' => 5]])->assertOk();
                $done[] = $c;
            }
        }

        return $done;
    }

    public function test_preview_describes_the_schedule_and_the_next_runs_in_its_timezone(): void
    {
        Carbon::setTestNow('2026-11-02 10:00:00');
        [, $token] = $this->setUpApp();
        $p = $this->api($token)->postJson('/api/v1/app/cron/preview', ['cron' => '30 2 * * 1-5', 'timezone' => 'Asia/Colombo'])->assertOk()->json('data');
        $this->assertSame('Every weekday at 02:30', $p['description']);
        $this->assertSame(['2026-11-02T21:00:00Z', '2026-11-03T21:00:00Z', '2026-11-04T21:00:00Z'], $p['next'], '02:30 in Colombo is 21:00 UTC');
        $this->api($token)->postJson('/api/v1/app/cron/preview', ['cron' => '*/15 * * * *'])->assertJsonPath('data.description', 'Every 15 minutes');
        $this->api($token)->postJson('/api/v1/app/cron/preview', ['cron' => 'nope'])->assertStatus(422);
        Carbon::setTestNow();
    }

    public function test_overlap_skip_records_a_skipped_run_and_stop_ends_the_old_one(): void
    {
        [, $token, $secret, $app] = $this->setUpApp();
        $job = $this->job($token, ['application_id' => $app->id, 'command' => 'php artisan queue:work --once']);
        $this->api($token)->postJson("/api/v1/app/cron/{$job['id']}/run")->assertStatus(202);
        $this->api($token)->postJson("/api/v1/app/cron/{$job['id']}/run")->assertStatus(202);
        $this->assertSame(['skipped', 'running'], ScheduledJobRun::allTenants()->orderByDesc('id')->pluck('status')->all());

        $this->api($token)->patchJson("/api/v1/app/cron/{$job['id']}", ['overlap' => 'stop'])->assertOk()->assertJsonPath('data.overlap', 'stop');
        $this->api($token)->postJson("/api/v1/app/cron/{$job['id']}/run")->assertStatus(202);
        $types = array_column($this->heartbeat($secret)['commands'], 'type');
        $this->assertContains('cron.stop', $types);
        $this->assertSame(['running', 'skipped', 'stopped'], ScheduledJobRun::allTenants()->orderByDesc('id')->pluck('status')->all());
    }

    public function test_failures_retry_with_backoff_then_alert_and_three_in_a_row_open_a_monitoring_alert(): void
    {
        Carbon::setTestNow('2026-11-02 10:00:00');
        [$org, $token, $secret, $app] = $this->setUpApp();
        $job = $this->job($token, ['application_id' => $app->id, 'command' => 'false', 'retries' => 1, 'retry_backoff' => 60]);
        $this->api($token)->postJson("/api/v1/app/cron/{$job['id']}/run")->assertStatus(202);
        $this->finish($secret, 1);
        $run = ScheduledJobRun::allTenants()->latest('id')->first();
        $this->assertSame('2026-11-02 10:01:00', $run->retry_at->toDateTimeString());

        Carbon::setTestNow('2026-11-02 10:01:30');
        $this->artisan('scheduled-jobs:run-due')->assertSuccessful();
        $this->finish($secret, 1);
        $retry = ScheduledJobRun::allTenants()->latest('id')->first();
        $this->assertSame([2, 'retry', null], [$retry->attempt, $retry->source, $retry->retry_at], 'the last attempt does not retry');
        $this->assertSame(1, ScheduledJob::allTenants()->find($job['id'])->failure_streak, 'one failure after retries');

        $this->api($token)->patchJson("/api/v1/app/cron/{$job['id']}", ['retries' => 0])->assertOk();
        foreach ([1, 2] as $_) {
            $this->api($token)->postJson("/api/v1/app/cron/{$job['id']}/run")->assertStatus(202);
            $this->finish($secret, 1);
        }
        $alert = Alert::allTenants()->where('resource_id', $job['id'])->first();
        $this->assertNotNull($alert);
        $this->assertStringContainsString('3 times in a row', $alert->title);

        $this->api($token)->postJson("/api/v1/app/cron/{$job['id']}/run")->assertStatus(202);
        $this->finish($secret, 0);
        $this->assertSame('resolved', $alert->fresh()->status, 'a success closes the alert');
        Carbon::setTestNow();
    }

    public function test_test_runs_raise_no_alerts_and_offline_servers_are_missed_runs(): void
    {
        [, $token, $secret, $app, $server] = $this->setUpApp();
        $job = $this->job($token, ['application_id' => $app->id, 'command' => 'false']);
        $this->api($token)->postJson("/api/v1/app/cron/{$job['id']}/run", ['test' => true])->assertStatus(202);
        $this->finish($secret, 1);
        $this->assertSame(0, ScheduledJob::allTenants()->find($job['id'])->failure_streak);
        $this->assertSame('test', ScheduledJobRun::allTenants()->latest('id')->value('source'));

        Server::allTenants()->whereKey($server->id)->update(['last_heartbeat_at' => now()->subHour()]);
        $this->api($token)->postJson("/api/v1/app/cron/{$job['id']}/run")->assertStatus(202);
        $this->assertSame('missed', ScheduledJobRun::allTenants()->latest('id')->value('status'));
    }

    public function test_a_run_the_scheduler_was_down_for_is_recorded_as_missed(): void
    {
        Carbon::setTestNow('2026-11-02 10:02:00');
        [, $token, , $app] = $this->setUpApp();
        $job = $this->job($token, ['application_id' => $app->id, 'command' => 'true', 'cron' => '0 * * * *']);
        ScheduledJob::allTenants()->whereKey($job['id'])->update(['next_run_at' => '2026-11-02 09:00:00']);
        $this->artisan('scheduled-jobs:run-due')->assertSuccessful();
        $this->assertSame('missed', ScheduledJobRun::allTenants()->latest('id')->value('status'));
        $this->assertSame('2026-11-02 11:00:00', ScheduledJob::allTenants()->find($job['id'])->next_run_at->toDateTimeString());
        Carbon::setTestNow();
    }

    public function test_long_running_runs_alert_once(): void
    {
        [$org, $token, , $app] = $this->setUpApp();
        $job = $this->job($token, ['application_id' => $app->id, 'command' => 'sleep 999', 'long_after' => 60]);
        $this->api($token)->postJson("/api/v1/app/cron/{$job['id']}/run")->assertStatus(202);
        ScheduledJobRun::allTenants()->update(['started_at' => now()->subMinutes(5)]);
        $this->artisan('scheduled-jobs:run-due')->assertSuccessful();
        $this->artisan('scheduled-jobs:run-due')->assertSuccessful();
        $this->assertTrue(ScheduledJobRun::allTenants()->first()->long_alerted);
    }

    public function test_one_off_runs_carry_the_apps_image_env_and_secrets_are_redacted(): void
    {
        [$org, $token, $secret, $app] = $this->setUpApp();
        app(Tenant::class)->as($org->id, fn () => EnvVar::create(['application_id' => $app->id, 'key' => 'DB_PASSWORD', 'value' => 'hunter2-secret', 'secret' => true]));
        $job = $this->job($token, ['application_id' => $app->id, 'command' => 'php artisan migrate --force', 'run_mode' => 'oneoff']);
        $this->api($token)->postJson("/api/v1/app/cron/{$job['id']}/run")->assertStatus(202);
        [$c] = $this->finish($secret, 0, 'connected with hunter2-secret');
        $this->assertSame(['oneoff', 'ghcr.io/acme/shop:1', 'hunter2-secret'], [$c['payload']['mode'], $c['payload']['image'], $c['payload']['env']['DB_PASSWORD']]);

        $runs = $this->api($token)->getJson("/api/v1/app/cron/{$job['id']}/runs?search=connected")->assertOk()->json('data');
        $this->assertSame('connected with ********', $runs[0]['output']);
        $this->assertCount(0, $this->api($token)->getJson("/api/v1/app/cron/{$job['id']}/runs?search=nothing-like-this")->json('data'));
        $file = $this->api($token)->getJson("/api/v1/app/cron/{$job['id']}/runs/{$runs[0]['id']}/download")->assertOk()->json('data');
        $this->assertStringEndsWith('.log', $file['filename']);
        $this->assertStringContainsString('connected with ********', $file['text']);
    }

    public function test_http_mode_uses_method_headers_and_expected_status(): void
    {
        [, $token] = $this->setUpApp();
        Http::fake(['shop.example.com/*' => Http::response('', 204)]);
        $job = $this->job($token, ['action' => 'url', 'url' => 'https://shop.example.com/hook', 'method' => 'POST', 'headers' => ['X-Token' => 't'], 'expected_status' => 204]);
        $this->api($token)->postJson("/api/v1/app/cron/{$job['id']}/run")->assertStatus(202);
        $this->assertSame('succeeded', ScheduledJobRun::allTenants()->latest('id')->value('status'));
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->hasHeader('X-Token', 't'));
    }

    public function test_duplicate_pause_resume_and_customer_limits(): void
    {
        [$org, $token, , $app] = $this->setUpApp();
        $job = $this->job($token, ['application_id' => $app->id, 'command' => 'true']);
        $copy = $this->api($token)->postJson("/api/v1/app/cron/{$job['id']}/duplicate")->assertCreated()->json('data');
        $this->assertSame(['Job (copy)', false, 'true'], [$copy['name'], $copy['enabled'], $copy['command']]);
        $this->api($token)->patchJson("/api/v1/app/cron/{$copy['id']}", ['enabled' => true])->assertOk()->assertJsonPath('data.enabled', true);

        $this->api($token)->postJson('/api/v1/app/cron', ['name' => 'x', 'action' => 'server_command', 'command' => 'df', 'cron' => '0 * * * *', 'run_mode' => 'host'])->assertStatus(422);
        $this->api($token)->postJson('/api/v1/app/cron', ['name' => 'x', 'action' => 'app_command', 'application_id' => $app->id, 'command' => 'df', 'cron' => '0 * * * *', 'timeout' => 7200])->assertStatus(422);
    }

    public function test_deleting_an_app_deletes_its_jobs(): void
    {
        [, $token, , $app] = $this->setUpApp();
        $this->job($token, ['application_id' => $app->id, 'command' => 'true']);
        Application::allTenants()->find($app->id)->retire();
        $this->assertSame(0, ScheduledJob::allTenants()->count());
    }

    public function test_platform_admins_see_every_job_pause_any_and_run_on_the_server(): void
    {
        [, $token, , $app, $server] = $this->setUpApp();
        $job = $this->job($token, ['application_id' => $app->id, 'command' => 'true']);
        [, , $admin] = $this->org('super_admin');

        $this->api($admin)->getJson('/api/v1/cron')->assertOk()->assertJsonPath('data.jobs.0.id', $job['id'])->assertJsonPath('data.modes', ['container', 'oneoff', 'host', 'http']);
        $this->api($admin)->patchJson("/api/v1/cron/{$job['id']}", ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);
        $host = $this->api($admin)->postJson('/api/v1/cron', ['name' => 'Prune', 'action' => 'server_command', 'run_mode' => 'host', 'server_id' => $server->id, 'command' => 'docker image prune -f', 'cron' => '0 3 * * *'])
            ->assertCreated()->json('data');
        $this->assertSame('host', $host['run_mode']);

        [, , $other] = $this->org();
        $this->api($other)->getJson('/api/v1/cron')->assertOk()->assertJsonCount(0, 'data.jobs');
        $this->api($other)->postJson('/api/v1/cron', ['name' => 'x', 'action' => 'server_command', 'run_mode' => 'host', 'server_id' => $server->id, 'command' => 'id', 'cron' => '0 3 * * *'])->assertStatus(422);
    }

    public function test_the_servers_own_runner_gets_the_schedule_and_reports_runs_made_while_the_panel_was_down(): void
    {
        [$org, $token, $secret, $app, $server] = $this->setUpApp();
        $job = $this->job($token, ['application_id' => $app->id, 'command' => 'php artisan backup', 'timezone' => 'Asia/Colombo']);
        $this->job($token, ['name' => 'Ping', 'action' => 'url', 'url' => 'https://example.com/ping']);
        $sched = collect($this->heartbeat($secret)['commands'])->where('type', 'cron.schedule')->last()['payload'];
        $this->assertSame([$job['id']], array_column($sched['jobs'], 'id'), 'command jobs go to the server; HTTP jobs stay with the panel');
        $this->assertSame(['*/5 * * * *', 'Asia/Colombo', 'container', 'php artisan backup'], [$sched['jobs'][0]['cron'], $sched['jobs'][0]['timezone'], $sched['jobs'][0]['exec']['mode'], $sched['jobs'][0]['exec']['command']]);
        $this->assertArrayNotHasKey('run_id', $sched['jobs'][0]['exec'], 'the agent names its own runs');

        // While the panel was down it recorded a missed run; the server ran it and reports it.
        $at = now()->startOfMinute()->subMinutes(10);
        ScheduledJobRun::allTenants()->create(['organization_id' => $org->id, 'scheduled_job_id' => $job['id'], 'status' => 'missed', 'source' => 'schedule', 'started_at' => $at, 'finished_at' => $at]);
        $result = ['job_id' => $job['id'], 'exit_code' => 0, 'output' => 'done', 'started_at' => $at->toIso8601ZuluString(), 'finished_at' => $at->copy()->addSeconds(3)->toIso8601ZuluString(), 'duration_ms' => 3000];
        $this->heartbeat($secret, ['cron_results' => [$result, $result]]);
        $runs = ScheduledJobRun::allTenants()->where('scheduled_job_id', $job['id'])->get();
        $this->assertSame([['runner', 'succeeded']], $runs->map(fn ($r) => [$r->source, $r->status])->all(), 'the missed run is replaced, and a resent result is recorded once');
        $this->assertSame('Exit 0 (ran on the server)', ScheduledJob::allTenants()->find($job['id'])->last_result);

        // A result naming another organisation's job is ignored.
        [, $token2, , $app2] = $this->setUpApp();
        $foreign = $this->job($token2, ['application_id' => $app2->id, 'command' => 'echo theirs'])['id'];
        $this->heartbeat($secret, ['cron_results' => [['job_id' => $foreign, 'exit_code' => 0, 'started_at' => now()->toIso8601ZuluString()]]]);
        $this->assertSame(0, ScheduledJobRun::allTenants()->where('scheduled_job_id', $foreign)->count());

        // Deleting the job updates the server's schedule.
        $this->api($token)->deleteJson("/api/v1/app/cron/{$job['id']}")->assertNoContent();
        $this->assertSame([], collect($this->heartbeat($secret)['commands'])->where('type', 'cron.schedule')->last()['payload']['jobs']);
    }
}
