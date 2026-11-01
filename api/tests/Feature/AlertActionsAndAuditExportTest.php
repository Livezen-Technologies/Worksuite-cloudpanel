<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use App\Services\UptimeService;
use App\Support\Tenant;
use Tests\TestCase;

class AlertActionsAndAuditExportTest extends TestCase
{
    public function test_alerts_can_be_snoozed_and_resolved_by_hand(): void
    {
        [, $org, $token] = $this->org();
        $alert = app(Tenant::class)->as($org->id, fn () => Alert::create(['severity' => 'critical', 'title' => 'Site down', 'message' => 'x', 'resource_type' => 'domain', 'resource_id' => (string) \Illuminate\Support\Str::uuid()]));
        Alert::allTenants()->whereKey($alert->id)->update(['created_at' => now()->subHours(3), 'last_notified_at' => now()->subHours(2), 'notify_count' => 1]);

        $this->api($token)->postJson("/api/v1/alerts/{$alert->id}/snooze", ['minutes' => 60])->assertOk();
        $f = app(UptimeService::class)->followUpOpenIncidents();
        $this->assertSame(['renotified' => 0, 'escalated' => 0], $f, 'a snoozed alert sends no reminder and does not escalate');
        $this->api($token)->postJson("/api/v1/alerts/{$alert->id}/snooze", ['minutes' => 0])->assertOk()->assertJsonPath('data.snoozed_until', null);
        $this->api($token)->postJson("/api/v1/alerts/{$alert->id}/snooze", ['minutes' => 20000])->assertStatus(422);

        $this->api($token)->postJson("/api/v1/alerts/{$alert->id}/resolve", ['note' => 'Fixed at the provider'])->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->assertDatabaseHas('audit_logs', ['action' => 'alert.resolve', 'resource_id' => $alert->id]);
        $this->api($token)->postJson("/api/v1/alerts/{$alert->id}/snooze", ['minutes' => 30])->assertStatus(409);
    }

    public function test_audit_log_exports_as_csv_with_filters_and_is_itself_audited(): void
    {
        [$user, $org, $token] = $this->org();
        [, $other] = $this->org();
        app(Tenant::class)->as($org->id, function () {
            app(AuditLogger::class)->log('server.create', 'servers', 's1', ['name' => 'web-1']);
            app(AuditLogger::class)->log('domain.create', 'domains', '=cmd|calc', []);
        });
        app(Tenant::class)->as($other->id, fn () => app(AuditLogger::class)->log('server.create', 'servers', 'theirs', []));
        app(Tenant::class)->set(null);

        $res = $this->api($token)->get('/api/v1/audit-logs/export?action=server.*')->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $csv = $res->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertStringStartsWith('id,time_utc,actor,user_email,action', $lines[0]);
        $this->assertStringContainsString('server.create', $csv);
        $this->assertStringNotContainsString('domain.create', $csv, 'action filter applied');
        $this->assertStringNotContainsString('theirs', $csv, 'only this organisation');
        $this->assertDatabaseHas('audit_logs', ['action' => 'audit.export', 'organization_id' => $org->id]);

        $all = $this->api($token)->get('/api/v1/audit-logs/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=cmd|calc", $all, 'spreadsheet formulas are neutralised');
    }
}
