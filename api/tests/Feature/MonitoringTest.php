<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Container;
use App\Models\Domain;
use App\Models\MaintenanceWindow;
use App\Models\NotificationChannel;
use App\Models\UptimeCheck;
use App\Services\UptimeService;
use App\Support\Tenant;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** MON-00x: cooldowns, maintenance suppression, escalation, auto-restart, uptime reports. */
class MonitoringTest extends TestCase
{
    private function channel(string $orgId): NotificationChannel
    {
        return app(Tenant::class)->as($orgId, fn () => NotificationChannel::create([
            'name' => 'ops', 'type' => 'webhook', 'config' => ['url' => 'https://hooks.test/x'], 'enabled' => true,
        ]));
    }

    private function openAlert(string $orgId, array $attrs = []): Alert
    {
        return app(Tenant::class)->as($orgId, function () use ($attrs) {
            $a = new Alert();
            $a->fill(array_merge([
                'resource_type' => 'server', 'resource_id' => (string) \Illuminate\Support\Str::uuid(),
                'severity' => 'critical', 'status' => 'open', 'title' => 'thing is down', 'message' => 'because',
            ], $attrs));
            $a->save();

            return $a;
        });
    }

    public function test_an_open_incident_is_not_re_notified_inside_the_cooldown(): void
    {
        Http::fake();
        [, $org] = $this->org();
        $this->channel($org->id);
        $alert = $this->openAlert($org->id, ['last_notified_at' => now()->subMinutes(5), 'notify_count' => 1]);

        $r = app(UptimeService::class)->followUpOpenIncidents();

        $this->assertSame(0, $r['renotified'], 'five minutes into a thirty-minute cooldown');
        Http::assertNothingSent();
        $this->assertSame(1, (int) $alert->fresh()->notify_count);
    }

    public function test_an_open_incident_is_re_notified_once_the_cooldown_passes(): void
    {
        Http::fake();
        [, $org] = $this->org();
        $this->channel($org->id);
        $alert = $this->openAlert($org->id, ['last_notified_at' => now()->subMinutes(45), 'notify_count' => 1]);

        $r = app(UptimeService::class)->followUpOpenIncidents();

        $this->assertSame(1, $r['renotified']);
        Http::assertSentCount(1);
        $this->assertSame(2, (int) $alert->fresh()->notify_count);
    }

    public function test_an_incident_open_too_long_escalates_once_to_emergency(): void
    {
        Http::fake();
        [, $org] = $this->org();
        $this->channel($org->id);
        $alert = $this->openAlert($org->id, ['last_notified_at' => now()->subMinutes(45), 'notify_count' => 1]);
        // Older than escalate_after_minutes (60).
        $alert->created_at = now()->subMinutes(90);
        $alert->saveQuietly();

        $first = app(UptimeService::class)->followUpOpenIncidents();
        $this->assertSame(1, $first['escalated']);
        $alert->refresh();
        $this->assertSame('emergency', $alert->severity);
        $this->assertNotNull($alert->escalated_at);

        // It must not escalate again on the next sweep.
        $alert->last_notified_at = now()->subMinutes(45);
        $alert->saveQuietly();
        $second = app(UptimeService::class)->followUpOpenIncidents();
        $this->assertSame(0, $second['escalated'], 'escalation happens once, not every sweep');
    }

    public function test_a_maintenance_window_silences_follow_ups_for_its_server(): void
    {
        Http::fake();
        [, $org] = $this->org();
        $server = $this->server($org);
        $this->channel($org->id);
        app(Tenant::class)->as($org->id, fn () => MaintenanceWindow::create([
            'name' => 'patching', 'server_id' => $server->id, 'suppress_alerts' => true,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(),
        ]));
        $alert = $this->openAlert($org->id, ['resource_id' => $server->id, 'last_notified_at' => now()->subHours(3), 'notify_count' => 1]);
        $alert->created_at = now()->subHours(3);
        $alert->saveQuietly();

        $r = app(UptimeService::class)->followUpOpenIncidents();

        $this->assertSame(0, $r['renotified']);
        $this->assertSame(0, $r['escalated'], 'a window must suppress escalation too, not only re-notification');
        Http::assertNothingSent();
    }

    public function test_an_expired_maintenance_window_no_longer_silences(): void
    {
        Http::fake();
        [, $org] = $this->org();
        $server = $this->server($org);
        $this->channel($org->id);
        app(Tenant::class)->as($org->id, fn () => MaintenanceWindow::create([
            'name' => 'yesterday', 'server_id' => $server->id, 'suppress_alerts' => true,
            'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay(),
        ]));
        $alert = $this->openAlert($org->id, ['resource_id' => $server->id, 'last_notified_at' => now()->subHours(3), 'notify_count' => 1]);

        $this->assertSame(1, app(UptimeService::class)->followUpOpenIncidents()['renotified']);
    }

    public function test_auto_restart_is_off_by_default(): void
    {
        [, $org] = $this->org();
        $server = $this->server($org);
        app(Tenant::class)->as($org->id, fn () => Container::create([
            'server_id' => $server->id, 'name' => 'wp', 'image' => 'wordpress', 'state' => 'running', 'health' => 'unhealthy',
        ]));

        $this->assertSame(0, app(UptimeService::class)->autoRestartUnhealthy()['restarted'],
            'restarting a customer container must be opt-in');
    }

    public function test_auto_restart_restarts_an_unhealthy_container_and_then_rate_limits(): void
    {
        Http::fake();
        [, $org] = $this->org();
        $org->settings = array_merge($org->settings ?? [], ['monitoring' => ['auto_restart_unhealthy' => true, 'auto_restart_max_per_hour' => 2]]);
        $org->save();
        $server = $this->server($org);
        $c = app(Tenant::class)->as($org->id, fn () => Container::create([
            'server_id' => $server->id, 'name' => 'wp', 'image' => 'wordpress', 'state' => 'running', 'health' => 'unhealthy',
        ]));

        $svc = app(UptimeService::class);
        $this->assertSame(1, $svc->autoRestartUnhealthy()['restarted']);
        $this->assertSame(1, (int) $c->fresh()->auto_restart_attempts);
        $this->assertSame(1, $svc->autoRestartUnhealthy()['restarted']);

        $third = $svc->autoRestartUnhealthy();
        $this->assertSame(0, $third['restarted'], 'the hourly cap stops a broken container restarting forever');
        $this->assertSame(1, $third['skipped']);

        $this->assertDatabaseCount('agent_commands', 2);
    }

    public function test_a_healthy_container_is_never_restarted(): void
    {
        [, $org] = $this->org();
        $org->settings = array_merge($org->settings ?? [], ['monitoring' => ['auto_restart_unhealthy' => true]]);
        $org->save();
        $server = $this->server($org);
        app(Tenant::class)->as($org->id, fn () => Container::create([
            'server_id' => $server->id, 'name' => 'ok', 'image' => 'nginx', 'state' => 'running', 'health' => 'healthy',
        ]));

        $this->assertSame(0, app(UptimeService::class)->autoRestartUnhealthy()['restarted']);
    }

    public function test_uptime_report_measures_from_recorded_checks(): void
    {
        [, $org, $token] = $this->org();
        $domain = app(Tenant::class)->as($org->id, fn () => Domain::create([
            'name' => 'shop.example.com', 'dns_status' => 'verified',
        ]));
        app(Tenant::class)->as($org->id, function () use ($domain) {
            foreach (range(1, 19) as $i) {
                UptimeCheck::create(['domain_id' => $domain->id, 'ok' => true, 'status' => 200, 'ms' => 100 + $i, 'checked_at' => now()->subMinutes($i * 5)]);
            }
            UptimeCheck::create(['domain_id' => $domain->id, 'ok' => false, 'status' => 502, 'ms' => 5000, 'checked_at' => now()->subMinutes(100)]);
        });

        $report = $this->api($token)->getJson('/api/v1/uptime')->assertOk()->json('data');
        $this->assertEqualsWithDelta(95.0, $report['domains'][0]['uptime_pct'], 0.001, '19 of 20 checks ok');
        $this->assertSame(20, $report['domains'][0]['checks']);
        $this->assertSame(1, $report['domains'][0]['failed']);
        $this->assertSame(1, $report['summary']['measured']);
    }

    public function test_a_domain_with_no_checks_reports_unknown_not_one_hundred(): void
    {
        [, $org, $token] = $this->org();
        app(Tenant::class)->as($org->id, fn () => Domain::create(['name' => 'new.example.com', 'dns_status' => 'verified']));

        $report = $this->api($token)->getJson('/api/v1/uptime')->assertOk()->json('data');
        $this->assertNull($report['domains'][0]['uptime_pct'], 'no data is unknown, not perfect');
        $this->assertSame(1, $report['summary']['unmeasured']);
    }

    public function test_a_customer_sees_only_their_own_incidents(): void
    {
        [, $mine, $myToken] = $this->org();
        [, $theirs] = $this->org();
        $this->openAlert($mine->id, ['title' => 'mine is down']);
        $this->openAlert($theirs->id, ['title' => 'theirs is down']);

        $rows = $this->api($myToken)->getJson('/api/v1/app/incidents')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('mine is down', $rows[0]['title']);
    }

    public function test_channels_from_the_settings_form_store_their_target_and_secret_and_telegram_sends(): void
    {
        [, , $token] = $this->org();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $id = $this->api($token)->postJson('/api/v1/notification-channels', ['name' => 'ops', 'type' => 'telegram', 'target' => '-1001234', 'secret' => '123:ABC', 'enabled' => true])
            ->assertCreated()->assertJsonPath('data.target', '-1001234')->assertJsonMissingPath('data.config')->json('data.id');
        // Renaming without re-entering the token keeps it.
        $this->api($token)->patchJson("/api/v1/notification-channels/{$id}", ['name' => 'ops-2'])->assertOk();
        $this->api($token)->postJson("/api/v1/notification-channels/{$id}/test")->assertOk();
        Http::assertSent(fn ($r) => $r->url() === 'https://api.telegram.org/bot123:ABC/sendMessage' && $r['chat_id'] === '-1001234' && str_contains($r['text'], 'Test notification'));

        $this->api($token)->postJson('/api/v1/notification-channels', ['name' => 'mail', 'type' => 'email', 'target' => 'not-an-email'])->assertStatus(422);
        $this->api($token)->postJson('/api/v1/notification-channels', ['name' => 'hook', 'type' => 'webhook', 'target' => 'https://hooks.example.com/x'])->assertCreated()->assertJsonPath('data.target', 'hooks.example.com');
    }

    public function test_an_incident_whose_container_was_replaced_is_closed_instead_of_re_notified_for_ever(): void
    {
        Http::fake();
        [, $org] = $this->org();
        $this->channel($org->id);

        // A container that has since been recreated under a new id: the row the
        // incident points at is gone, so checkInfrastructure's loop never visits
        // it again and followUpOpenIncidents would re-notify it indefinitely.
        $gone = $this->openAlert($org->id, [
            'resource_type' => 'container', 'resource_id' => (string) \Illuminate\Support\Str::uuid(),
            'title' => 'blog-wordpress-1 is running and unhealthy',
        ]);
        $server = $this->server($org);
        $live = app(Tenant::class)->as($org->id, fn () => Container::create([
            'server_id' => $server->id, 'name' => 'blog-wordpress-1', 'image' => 'wordpress:6',
            'state' => 'running', 'health' => 'healthy',
        ]));
        $kept = $this->openAlert($org->id, [
            'resource_type' => 'container', 'resource_id' => $live->id, 'title' => 'blog-wordpress-1 is running and unhealthy',
        ]);

        $r = app(UptimeService::class)->checkInfrastructure();

        $this->assertSame('resolved', $gone->fresh()->status, 'the incident for the replaced container must be closed');
        $this->assertNotNull($gone->fresh()->resolved_at);
        $this->assertGreaterThanOrEqual(1, $r['closed']);
        // The live container is healthy, so its own incident is closed by the
        // normal recovery path - not by the orphan sweep.
        $this->assertSame('resolved', $kept->fresh()->status);
    }
}
