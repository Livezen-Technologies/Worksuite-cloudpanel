<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\AuditLog;
use App\Models\Metric;
use App\Models\ScheduledJob;
use App\Services\AuditLogger;
use App\Support\Tenant;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    public function test_audit_log_is_hash_chained_and_append_only(): void
    {
        [, $org, $token] = $this->org();
        $this->api($token)->postJson('/api/v1/servers', ['name' => 'a'])->assertCreated();
        $this->api($token)->postJson('/api/v1/servers', ['name' => 'b'])->assertCreated();

        $logs = AuditLog::where('organization_id', $org->id)->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(2, $logs->count());
        $this->assertSame('servers.store', $logs->last()->action);
        $this->assertSame($logs[$logs->count() - 2]->hash, $logs->last()->prev_hash);
        $this->api($token)->getJson('/api/v1/audit-logs/verify')->assertOk()->assertJsonPath('data.valid', true);

        try {
            $logs->last()->update(['action' => 'tampered']);
            $this->fail('audit rows must be immutable');
        } catch (\LogicException) {
        }
        \DB::table('audit_logs')->where('id', $logs->first()->id)->update(['actor' => 'mallory']);
        $this->assertFalse(app(AuditLogger::class)->verify($org->id)['valid']);
    }

    public function test_default_alert_rules_seeded_and_evaluated(): void
    {
        [, $org, $token] = $this->org();
        $this->assertSame(7, AlertRule::allTenants()->where('organization_id', $org->id)->where('is_default', true)->count());
        $server = $this->server($org, ['cpu_pct' => 97]);
        foreach (range(0, 9) as $m) {
            Metric::create(['organization_id' => $org->id, 'server_id' => $server->id, 'cpu_pct' => 97, 'ram_pct' => 20, 'disk_pct' => 30, 'recorded_at' => now()->subMinutes($m)]);
        }
        $this->api($token)->postJson('/api/v1/notification-channels', ['name' => 'log', 'type' => 'log'])->assertCreated()->assertJsonMissingPath('data.config');
        app(Tenant::class)->set(null);
        $this->artisan('alerts:evaluate')->assertSuccessful();
        $alert = Alert::allTenants()->where('resource_id', $server->id)->first();
        $this->assertSame('critical', $alert->severity);
        $this->api($token)->postJson("/api/v1/alerts/{$alert->id}/ack")->assertOk()->assertJsonPath('data.status', 'acknowledged');
        $this->api($token)->getJson('/api/v1/dashboard?range=1h')->assertOk()
            ->assertJsonStructure(['data' => ['kpis' => ['servers_online', 'servers_total', 'containers_running', 'cpu_pct', 'ram_pct'], 'series' => ['cpu', 'ram'], 'alerts', 'deployments', 'top', 'status']]);
    }

    public function test_scheduled_jobs_cron_and_run_now(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response(['ok' => true])]);
        [, $org, $token] = $this->org();
        $this->api($token)->postJson('/api/v1/scheduled-jobs', ['name' => 'bad', 'cron' => 'not cron', 'action' => 'webhook', 'params' => ['url' => 'https://hooks.example.com/x']])->assertStatus(422);
        $job = $this->api($token)->postJson('/api/v1/scheduled-jobs', ['name' => 'ping', 'trigger' => 'cron', 'cron' => '* * * * *', 'action' => 'webhook', 'params' => ['url' => 'https://hooks.example.com/x']])
            ->assertCreated()->json('data');
        $this->assertNotNull(ScheduledJob::allTenants()->find($job['id'])->next_run_at);
        $this->api($token)->postJson("/api/v1/scheduled-jobs/{$job['id']}/run")->assertStatus(202)->assertJsonPath('data.status', 'succeeded');
        app(Tenant::class)->set(null);
        $this->artisan('scheduled-jobs:run-due')->assertSuccessful();
        Http::assertSentCount(2);
        $this->assertSame('succeeded', ScheduledJob::allTenants()->find($job['id'])->last_status);
    }

    public function test_users_invite_and_role_change(): void
    {
        [, , $token] = $this->org();
        $invite = $this->api($token)->postJson('/api/v1/users/invite', ['email' => 'new@example.test', 'role' => 'devops'])->assertCreated()->json('data');
        $this->api($token)->postJson('/api/v1/users/invite', ['email' => 'new@example.test', 'role' => 'devops'])->assertStatus(409);
        $this->api($token)->postJson('/api/v1/users/invite', ['email' => 'x@example.test', 'role' => 'super_admin'])->assertForbidden();
        $this->api($token)->patchJson("/api/v1/users/{$invite['id']}", ['role' => 'viewer'])->assertOk()->assertJsonPath('data.role', 'viewer');
        $this->api($token)->getJson('/api/v1/users')->assertOk()->assertJsonPath('meta.total', 2);
        $this->api($token)->deleteJson("/api/v1/users/{$invite['id']}")->assertNoContent();
    }

    public function test_compose_validate_diff_deploy_and_rollback(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $yaml1 = "services:\n  web:\n    image: nginx:1\n    depends_on: [db]\n  db:\n    image: postgres:16\n";
        $id = $this->api($token)->postJson('/api/v1/compose-projects', ['name' => 'stack', 'server_id' => $server->id, 'yaml' => $yaml1])->assertCreated()
            ->assertJsonPath('data.revision', 1)->json('data.id');
        $this->api($token)->postJson("/api/v1/compose-projects/{$id}/validate", ['yaml' => "services:\n  x:\n    privileged: true\n"])->assertOk()
            ->assertJsonPath('data.valid', false);
        $this->api($token)->postJson("/api/v1/compose-projects/{$id}/validate", ['yaml' => "services: [\n"])->assertOk()->assertJsonPath('data.valid', false);

        $this->api($token)->postJson("/api/v1/compose-projects/{$id}/deploy")->assertStatus(202);
        $cmd = $this->heartbeat($secret)['commands'][0];
        $this->assertSame('compose.up', $cmd['type']);
        $this->assertSame('stack', $cmd['payload']['project']);
        $this->assertSame($yaml1, $cmd['payload']['compose_yaml']);
        $this->commandResult($secret, $cmd['id'], 'succeeded');

        $yaml2 = str_replace('nginx:1', 'nginx:2', $yaml1);
        $diff = $this->api($token)->postJson("/api/v1/compose-projects/{$id}/diff", ['yaml' => $yaml2])->assertOk()->json('data');
        $this->assertSame(1, $diff['added']);
        $this->assertSame(1, $diff['removed']);
        $this->api($token)->postJson("/api/v1/compose-projects/{$id}/deploy", ['yaml' => $yaml2])->assertStatus(202);
        $revisions = $this->api($token)->getJson("/api/v1/compose-projects/{$id}/revisions")->assertOk()->json('data');
        $this->assertCount(2, $revisions);
        $first = collect($revisions)->firstWhere('number', 1);
        $this->api($token)->postJson("/api/v1/compose-projects/{$id}/rollback", ['revision_id' => $first['id']])->assertStatus(202);
        $this->assertSame(3, $this->api($token)->getJson("/api/v1/compose-projects/{$id}")->json('data.revision'));
    }

    public function test_networks_conflict_check(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        $this->api($token)->postJson('/api/v1/networks', ['server_id' => $server->id, 'name' => 'app', 'subnet' => '10.10.0.0/16'])->assertStatus(202);
        $this->api($token)->postJson('/api/v1/networks/check', ['server_id' => $server->id, 'name' => 'other', 'subnet' => '10.10.5.0/24'])->assertOk()
            ->assertJsonPath('data.ok', false)->assertJsonPath('data.conflicts.0.type', 'ip');
        $this->api($token)->postJson('/api/v1/networks', ['server_id' => $server->id, 'name' => 'other', 'subnet' => '10.10.5.0/24'])->assertStatus(409);
        $this->api($token)->postJson('/api/v1/networks', ['server_id' => $server->id, 'name' => 'h', 'driver' => 'host'])->assertForbidden();
    }

    public function test_search_and_customer_templates(): void
    {
        [, $org, $token] = $this->org();
        $this->server($org, ['name' => 'findme-01']);
        $this->api($token)->getJson('/api/v1/search?q=findme')->assertOk()->assertJsonPath('data.0.type', 'server');
        $ids = collect($this->api($token)->getJson('/api/v1/app/templates')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame(['wordpress', 'n8n', 'ghost', 'postgresql', 'redis', 'uptime-kuma'], $ids);
    }

    public function test_database_from_template_uses_secret_reference(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $res = $this->api($token)->postJson('/api/v1/databases', ['name' => 'main', 'engine' => 'postgres', 'server_id' => $server->id])->assertStatus(202)->json('data');
        $this->assertStringStartsWith('secret://', $res['database']['credentials_ref']);
        $cmd = $this->heartbeat($secret)['commands'][0];
        $this->assertSame('postgres:16', $cmd['payload']['image']);
        $this->assertNotEmpty($cmd['payload']['env']['POSTGRES_PASSWORD']);
        $this->commandResult($secret, $cmd['id'], 'succeeded');
        $this->api($token)->getJson("/api/v1/databases/{$res['database']['id']}")->assertJsonPath('data.status', 'running');
    }

    public function test_billing_payment_method_never_takes_card_data(): void
    {
        [, , $token] = $this->org();
        $this->api($token)->postJson('/api/v1/app/billing/payment-method', ['payment_method_token' => 'pm_fake_visa', 'card_number' => '4242424242424242'])->assertStatus(422);
        $this->api($token)->postJson('/api/v1/app/billing/payment-method', ['payment_method_token' => 'pm_fake_visa'])->assertOk()->assertJsonPath('data.payment_method.last4', '4242');
        $this->api($token)->getJson('/api/v1/app/billing/plans')->assertOk()->assertJsonCount(3, 'data');
    }
}
