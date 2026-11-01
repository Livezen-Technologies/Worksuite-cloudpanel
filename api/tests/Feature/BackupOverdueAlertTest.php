<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\BackupJob;
use App\Models\BackupPolicy;
use App\Services\AlertService;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** A policy whose last two scheduled runs passed without a successful backup raises "Backups overdue". */
class BackupOverdueAlertTest extends TestCase
{
    public function test_missed_runs_raise_the_alert_and_a_success_clears_it(): void
    {
        Carbon::setTestNow('2026-11-05 12:00:00');
        [, $org, $token] = $this->org();
        $alerts = app(AlertService::class);
        app(Tenant::class)->as($org->id, function () use ($alerts, $org) {
            $alerts->seedDefaults($org);
            $rule = AlertRule::where('metric', 'backup_overdue')->firstOrFail();
            $daily = BackupPolicy::create(['name' => 'nightly', 'target_type' => 'server', 'schedule' => '0 3 * * *']);
            $daily->forceFill(['created_at' => '2026-10-01 00:00:00'])->save();
            $fresh = BackupPolicy::create(['name' => 'brand-new', 'target_type' => 'server', 'schedule' => '0 3 * * *']);

            // Never succeeded: overdue. A policy created after the run before last is left alone.
            $r = $alerts->evaluate($rule);
            $this->assertTrue($r[$daily->id][0]);
            $this->assertArrayNotHasKey($fresh->id, $r);

            // Last success two days ago (before the 03:00 run on the 4th): still overdue.
            $old = BackupJob::create(['backup_policy_id' => $daily->id, 'target_type' => 'server', 'trigger' => 'schedule', 'status' => 'succeeded']);
            $old->forceFill(['created_at' => '2026-11-03 03:05:00'])->save();
            $this->assertTrue($alerts->evaluate($rule)[$daily->id][0]);
            $this->assertSame(56.9, $alerts->evaluate($rule)[$daily->id][2]);

            // Last night's run succeeded: one missed run is not overdue.
            $ok = BackupJob::create(['backup_policy_id' => $daily->id, 'target_type' => 'server', 'trigger' => 'schedule', 'status' => 'succeeded']);
            $ok->forceFill(['created_at' => '2026-11-05 03:04:00'])->save();
            $this->assertFalse($alerts->evaluate($rule)[$daily->id][0]);
            $ok->delete();
        });
        // The policy list and the summary show the same verdict.
        $row = collect($this->api($token)->getJson('/api/v1/backup-policies')->assertOk()->json('data'))->firstWhere('name', 'nightly');
        $this->assertTrue($row['overdue']);
        $this->assertStringStartsWith('2026-11-03T03:05', $row['last_success_at']);
        $this->assertFalse(collect($this->api($token)->getJson('/api/v1/backup-policies')->json('data'))->firstWhere('name', 'brand-new')['overdue']);
        $this->assertSame(1, $this->api($token)->getJson('/api/v1/backups/summary')->assertOk()->json('data.overdue'));
        $this->api($token)->postJson('/api/v1/alert-rules', ['name' => 'Overdue', 'metric' => 'backup_overdue', 'operator' => '>=', 'threshold' => 24, 'scope_type' => 'organization'])->assertCreated();
        Carbon::setTestNow();
    }
}
