<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BackupJob;
use App\Models\EnterpriseDeployment;
use App\Models\EnterpriseUpdate;
use App\Models\EnterpriseWebhook;
use App\Models\Image;
use App\Services\EnterpriseProvisioningService;
use App\Services\EnterpriseUpdateService;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Enterprise updates: backup first, redeploy on the new tag, health, automatic rollback, restore, auto/schedule. */
class EnterpriseUpdatesTest extends TestCase
{
    private function sync(): void
    {
        app(Tenant::class)->set(null);
        app(EnterpriseProvisioningService::class)->sync();
    }

    private function tick(): void
    {
        app(Tenant::class)->set(null);
        app(EnterpriseUpdateService::class)->tick();
    }

    /** Acknowledge every pending command of these types; returns them. */
    private function ack(string $agent, array $types, array $output = []): array
    {
        $cmds = array_values(array_filter($this->heartbeat($agent)['commands'], fn ($c) => in_array($c['type'], $types, true)));
        foreach ($cmds as $c) {
            $this->commandResult($agent, $c['id'], 'succeeded', ['output' => $output ?: ['container_id' => 'c-'.$c['id']]])->assertOk();
        }

        return $cmds;
    }

    private function backupsSucceed(string $agent): array
    {
        $cmds = array_values(array_filter($this->heartbeat($agent)['commands'], fn ($c) => $c['type'] === 'backup.run'));
        foreach ($cmds as $i => $c) {
            $out = ['snapshot_id' => 'snap'.$i, 'summary' => ['snapshot_id' => 'snap'.$i, 'total_bytes_processed' => 1024], 'check' => ['ok' => true]];
            if (isset($c['payload']['dump'])) {
                $out['dump'] = ['bytes' => 2048];
            }
            $this->commandResult($agent, $c['id'], 'succeeded', ['output' => $out])->assertOk();
        }

        return $cmds;
    }

    /** The containers of a redeploy come up (or not): every command a heartbeat hands out is answered. */
    private function rollout(string $agent, bool $healthy): void
    {
        for ($i = 0; $i < 4; $i++) {
            foreach ($this->heartbeat($agent)['commands'] as $c) {
                $out = $c['type'] === 'container.healthcheck'
                    ? ($healthy ? ['output' => ['healthy' => true, 'method' => 'http', 'url' => 'http://10.0.0.2:8080/', 'status' => 200]] : ['message' => 'unhealthy', 'output' => ['healthy' => false]])
                    : ['output' => ['container_id' => 'c-'.$c['id']]];
                $this->commandResult($agent, $c['id'], $c['type'] === 'container.healthcheck' && ! $healthy ? 'failed' : 'succeeded', $out)->assertOk();
            }
        }
    }

    /** Awaiting setup on 3.2.0-3011 with 3.2.1-3100 also loaded: [id, token, agent]. */
    private function ready(): array
    {
        [, $org, $ownerToken] = $this->org();
        $server = $this->server($org, ['ram_mb' => 65536, 'tags' => ['enterprise']]);
        [$agent] = $this->enrollAgent($server, $ownerToken);
        [$svc] = $this->member($org, 'enterprise_service');
        $token = $this->tokenFor($svc, ['enterprise.deploy']);
        config(['enterprise.require_loaded_image' => false]);
        $id = $this->api($token)->withHeader('Idempotency-Key', 'c1')->postJson('/api/v1/enterprise/deployments', [
            'external_id' => 'ent_u', 'customer_name' => 'Acme', 'slug' => 'acme', 'version' => '3.2.0-3011', 'size' => 'small',
            'callback_url' => 'https://cloud.test/hooks/enterprise',
        ])->assertStatus(202)->json('data.deployment_id');
        $this->ack($agent, ['container.create']);
        $this->sync();
        $this->ack($agent, ['container.create']);
        $this->passHealthChecks($agent);
        $this->sync();
        $this->assertSame('awaiting_setup', EnterpriseDeployment::find($id)->status);
        foreach (['3.2.0-3011', '3.2.1-3100', '3.3.0-3200', 'latest'] as $i => $tag) {
            Image::allTenants()->create(['organization_id' => $server->organization_id, 'server_id' => $server->id, 'docker_id' => 'sha256:'.$i, 'repository' => 'worksuite-server', 'tags' => ['worksuite-server:'.$tag], 'size_mb' => 900]);
        }
        $this->ack($agent, ['cron.exec'], ['exit_code' => 0, 'output' => '']); // the image scan

        return [$id, $token, $agent];
    }

    public function test_update_now_backs_up_db_and_volumes_then_redeploys_and_reports(): void
    {
        [$id, $token, $agent] = $this->ready();
        $base = "/api/v1/enterprise/deployments/{$id}/updates";

        $state = $this->api($token)->getJson($base)->assertOk()->assertJsonPath('data.current_version', '3.2.0-3011')
            ->assertJsonPath('data.can_update', true)->assertJsonPath('data.settings.mode', 'off');
        $this->assertSame(['3.3.0-3200', '3.2.1-3100'], array_column($state->json('data.available'), 'version')); // never "latest", newest first
        $this->assertSame('3.3.0-3200', $state->json('data.latest'));

        $this->api($token)->withHeader('Idempotency-Key', 'u0')->postJson($base, ['version' => '9.9.9-1'])->assertStatus(422); // not loaded
        $run = $this->api($token)->withHeader('Idempotency-Key', 'u1')->postJson($base, ['version' => '3.2.1-3100', 'reason' => 'monthly', 'requested_by' => 'Ops Person'])
            ->assertStatus(202)->assertJsonPath('data.status', 'backing_up')->assertJsonPath('data.requested_by', 'Ops Person')->json('data');
        $this->assertSame('3.2.0-3011', EnterpriseDeployment::find($id)->version); // nothing changed yet
        $this->api($token)->withHeader('Idempotency-Key', 'u2')->postJson($base, ['version' => '3.3.0-3200'])->assertStatus(409); // one at a time

        // The pre-update backup: one volume snapshot of the shared web+worker volumes and one database dump.
        $backups = $this->backupsSucceed($agent);
        $this->assertCount(2, $backups);
        $paths = collect($backups)->flatMap(fn ($c) => (array) ($c['payload']['paths'] ?? []))->all();
        $this->assertContains('/var/lib/docker/volumes/ent-acme-writable/_data', $paths);
        $this->assertContains('/var/lib/docker/volumes/ent-acme-uploads/_data', $paths);
        $this->assertSame('mariadb', collect($backups)->firstWhere('payload.dump')['payload']['dump']['engine']);
        $this->assertSame('pre_update', BackupJob::allTenants()->find($run['backup_id'])->trigger);

        $this->tick();
        $this->assertSame('deploying', EnterpriseUpdate::find($run['id'])->status);
        $this->assertSame('3.2.1-3100', EnterpriseDeployment::find($id)->version);
        $this->rollout($agent, true);
        $this->tick();

        $state = $this->api($token)->getJson($base)->assertOk()->assertJsonPath('data.current_version', '3.2.1-3100')
            ->assertJsonPath('data.last.status', 'succeeded')->assertJsonPath('data.last.backup_status', 'succeeded')->assertJsonPath('data.active', null);
        $this->assertSame(['3.3.0-3200'], array_column($state->json('data.available'), 'version'));
        $this->assertTrue(AuditLog::query()->withoutGlobalScopes()->where('action', 'enterprise.deployment.update.succeeded')->exists());
        $events = EnterpriseWebhook::where('enterprise_deployment_id', $id)->where('event', 'deployment.update')->get();
        $this->assertSame(['scheduled', 'backing_up', 'deploying', 'succeeded'], $events->pluck('payload.update.status')->unique()->values()->all());
        // The pre-update backup is an ordinary backup of the deployment.
        $this->api($token)->getJson("/api/v1/enterprise/deployments/{$id}/backups/{$run['backup_id']}")->assertOk()->assertJsonPath('data.type', 'pre_update');
    }

    public function test_an_unhealthy_new_version_rolls_back_and_the_backup_can_be_restored(): void
    {
        [$id, $token, $agent] = $this->ready();
        $base = "/api/v1/enterprise/deployments/{$id}/updates";
        $run = $this->api($token)->withHeader('Idempotency-Key', 'r1')->postJson($base, ['version' => '3.2.1-3100'])->assertStatus(202)->json('data.id');
        $this->backupsSucceed($agent);
        $this->tick();
        $this->rollout($agent, false);
        $this->tick();
        $this->assertSame('rolling_back', EnterpriseUpdate::find($run)->status);
        $this->assertSame('3.2.0-3011', EnterpriseDeployment::find($id)->version);
        $this->rollout($agent, true);
        $this->tick();
        $u = EnterpriseUpdate::find($run);
        $this->assertSame('rolled_back', $u->status);
        $this->assertNotNull($u->error);

        $this->api($token)->withHeader('Idempotency-Key', 'r2')->postJson("{$base}/{$run}/restore", ['reason' => 'data'])->assertStatus(422); // confirm
        $this->api($token)->withHeader('Idempotency-Key', 'r3')->postJson("{$base}/{$run}/restore", ['confirm' => true])->assertStatus(422); // reason
        $out = $this->api($token)->withHeader('Idempotency-Key', 'r4')->postJson("{$base}/{$run}/restore", ['confirm' => true, 'reason' => 'new version broke invoices'])
            ->assertStatus(202)->json('data');
        $this->assertCount(2, $out['restore']['restored']); // volumes + database, each after a fresh recovery point
        $this->assertNull($out['redeploy']); // already back on the previous tag
        $this->assertTrue(AuditLog::query()->withoutGlobalScopes()->where('action', 'enterprise.deployment.update.restore')->exists());
    }

    public function test_a_failed_backup_changes_nothing(): void
    {
        [$id, $token, $agent] = $this->ready();
        $run = $this->api($token)->withHeader('Idempotency-Key', 'f1')->postJson("/api/v1/enterprise/deployments/{$id}/updates", ['version' => '3.2.1-3100'])->json('data.id');
        foreach (array_filter($this->heartbeat($agent)['commands'], fn ($c) => $c['type'] === 'backup.run') as $c) {
            $this->commandResult($agent, $c['id'], 'failed', ['message' => 'repository locked'])->assertOk();
        }
        $this->tick();
        $u = EnterpriseUpdate::find($run);
        $this->assertSame('failed', $u->status);
        $this->assertStringContainsString('Nothing was changed', $u->error);
        $this->assertSame('3.2.0-3011', EnterpriseDeployment::find($id)->version);
    }

    public function test_schedule_cancel_settings_and_auto_update_once_per_version_in_the_window(): void
    {
        [$id, $token] = $this->ready();
        $base = "/api/v1/enterprise/deployments/{$id}/updates";

        $at = now()->addDay()->startOfHour();
        $run = $this->api($token)->withHeader('Idempotency-Key', 's1')->postJson($base, ['version' => '3.2.1-3100', 'scheduled_for' => $at->toIso8601String()])
            ->assertStatus(202)->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.trigger', 'scheduled')->assertJsonPath('data.can_cancel', true)->json('data.id');
        $this->tick();
        $this->assertSame('scheduled', EnterpriseUpdate::find($run)->status); // not due
        $this->api($token)->withHeader('Idempotency-Key', 's2')->postJson("{$base}/{$run}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->api($token)->withHeader('Idempotency-Key', 's3')->postJson("{$base}/{$run}/cancel")->assertStatus(409);
        $this->api($token)->withHeader('Idempotency-Key', 's4')->postJson($base, ['version' => '3.2.1-3100', 'scheduled_for' => now()->subDay()->toIso8601String()])->assertStatus(422);

        $this->api($token)->putJson("{$base}/settings", ['mode' => 'sometimes'])->assertStatus(422);
        $this->api($token)->putJson("{$base}/settings", ['window' => ['timezone' => 'Mars/Base']])->assertStatus(422);
        $now = Carbon::parse('2026-11-21 21:00:00', 'UTC'); // Sunday 02:30 in Colombo (UTC+5:30)
        Carbon::setTestNow($now);
        $this->api($token)->putJson("{$base}/settings", ['mode' => 'auto', 'channel' => '3.2', 'notice_hours' => 0, 'window' => ['days' => [7], 'start' => '02:00', 'duration_minutes' => 120, 'timezone' => 'Asia/Colombo']])
            ->assertOk()->assertJsonPath('data.settings.mode', 'auto')->assertJsonPath('data.settings.channel', '3.2')->assertJsonPath('data.latest', '3.2.1-3100');

        $this->tick();
        $auto = EnterpriseUpdate::where('enterprise_deployment_id', $id)->where('trigger', 'auto')->get();
        $this->assertCount(1, $auto); // once per version, never 3.3 on the 3.2 channel
        $this->assertSame('3.2.1-3100', $auto[0]->to_version);
        $this->assertSame('backing_up', $auto[0]->status);
        $this->assertSame(1, EnterpriseWebhook::where('enterprise_deployment_id', $id)->where('event', 'deployment.update_available')->count());
        Carbon::setTestNow();
    }

    public function test_auto_update_is_announced_notice_hours_ahead_and_only_an_announced_window_runs(): void
    {
        [$id, $token] = $this->ready();
        $base = "/api/v1/enterprise/deployments/{$id}/updates";
        $this->api($token)->putJson("{$base}/settings", ['notice_hours' => 5])->assertStatus(422);

        // Sunday 02:30 Colombo, inside the window, but nothing was announced: this window is skipped.
        Carbon::setTestNow(Carbon::parse('2026-11-21 21:00:00', 'UTC'));
        $this->api($token)->putJson("{$base}/settings", ['mode' => 'auto', 'channel' => '3.2', 'notice_hours' => 24, 'window' => ['days' => [7], 'start' => '02:00', 'duration_minutes' => 120, 'timezone' => 'Asia/Colombo']])
            ->assertOk()->assertJsonPath('data.settings.notice_hours', 24)->assertJsonMissingPath('data.settings.announced')
            ->assertJsonPath('data.next_notice.window_start', '2026-11-28T20:30:00Z')->assertJsonPath('data.next_notice.notify_at', '2026-11-27T20:30:00Z')
            ->assertJsonPath('data.next_notice.announced', false);
        $this->tick();
        $this->tick();
        $this->assertSame(0, EnterpriseUpdate::where('enterprise_deployment_id', $id)->count());
        $hooks = EnterpriseWebhook::where('enterprise_deployment_id', $id)->where('event', 'deployment.update_scheduled')->get();
        $this->assertCount(1, $hooks); // once per (version, window)
        $p = $hooks[0]->payload['update_scheduled'];
        $this->assertSame(['3.2.1-3100', '3.2.0-3011', '2026-11-28T20:30:00Z', '2026-11-28T22:30:00Z', 'Asia/Colombo', 24, '2026-11-27T20:30:00Z'],
            [$p['version'], $p['current_version'], $p['window_start'], $p['window_end'], $p['timezone'], $p['notice_hours'], $p['notify_at']]);
        $this->api($token)->getJson($base)->assertOk()->assertJsonPath('data.next_notice.announced', true);

        // The announced window opens: the run starts.
        Carbon::setTestNow(Carbon::parse('2026-11-28 21:00:00', 'UTC'));
        $this->tick();
        $auto = EnterpriseUpdate::where('enterprise_deployment_id', $id)->where('trigger', 'auto')->get();
        $this->assertCount(1, $auto);
        $this->assertSame('3.2.1-3100', $auto[0]->to_version);
        Carbon::setTestNow();
    }

    public function test_notice_window_skips_windows_too_close_to_announce(): void
    {
        $w = ['days' => [7], 'start' => '02:00', 'duration_minutes' => 120, 'timezone' => 'UTC'];
        $sat = Carbon::parse('2026-11-21 12:00', 'UTC'); // Sunday 02:00 is 14 h away
        $this->assertSame('2026-11-22T02:00:00+00:00', EnterpriseUpdateService::noticeWindow($w, 6, $sat)->toIso8601String());
        $this->assertSame('2026-11-29T02:00:00+00:00', EnterpriseUpdateService::noticeWindow($w, 24, $sat)->toIso8601String());
        $this->assertSame('2026-12-06T02:00:00+00:00', EnterpriseUpdateService::noticeWindow($w, 200, $sat)->toIso8601String());
        $this->assertSame('2026-11-22T02:00:00+00:00', EnterpriseUpdateService::openedAt($w, Carbon::parse('2026-11-22 03:00', 'UTC'))->toIso8601String());
    }

    public function test_window_and_version_rules(): void
    {
        $w = ['days' => [6], 'start' => '23:00', 'duration_minutes' => 120, 'timezone' => 'UTC'];
        $this->assertTrue(EnterpriseUpdateService::inWindow($w, Carbon::parse('2026-11-21 23:30', 'UTC'))); // Saturday
        $this->assertTrue(EnterpriseUpdateService::inWindow($w, Carbon::parse('2026-11-22 00:30', 'UTC'))); // after midnight
        $this->assertFalse(EnterpriseUpdateService::inWindow($w, Carbon::parse('2026-11-22 01:30', 'UTC')));
        $this->assertSame('2026-11-28T23:00:00+00:00', EnterpriseUpdateService::nextWindow($w, Carbon::parse('2026-11-22 01:30', 'UTC'))->toIso8601String());
        $this->assertTrue(EnterpriseUpdateService::newer('3.1.0-4515', '3.1.0-4514'));
        $this->assertTrue(EnterpriseUpdateService::newer('3.10.0-1', '3.9.9-9999'));
        $this->assertFalse(EnterpriseUpdateService::newer('latest', '3.1.0-1'));
        $this->assertTrue(EnterpriseUpdateService::onChannel('3.1.2-5', '3.1'));
        $this->assertFalse(EnterpriseUpdateService::onChannel('3.2.0-5', '3.1'));
    }
}
