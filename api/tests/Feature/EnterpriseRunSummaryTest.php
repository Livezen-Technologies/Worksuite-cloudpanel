<?php

namespace Tests\Feature;

use App\Models\EnterpriseDeployment;
use App\Models\EnterpriseRunReport;
use App\Models\EnterpriseUpdate;
use App\Models\Image;
use App\Services\EmailTemplateService;
use App\Services\EnterpriseProvisioningService;
use App\Services\EnterpriseRunReportService;
use App\Services\EnterpriseUpdateService;
use App\Support\Tenant;
use Tests\TestCase;

/** ENT-MAIL: an Enterprise run sends one summary email; its containers and backup jobs send none of their own. */
class EnterpriseRunSummaryTest extends TestCase
{
    private $mail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mail = new class(app(\App\Services\AuditLogger::class)) extends EmailTemplateService
        {
            public array $log = [];

            public function notify(string $key, \App\Models\User|string $to, array $vars = []): bool
            {
                $this->log['notify'][] = [$key, $to, $vars];

                return true;
            }

            public function notifyOrganization(?string $organizationId, string $key, array $vars = [], ?string $dedupeKey = null, int $dedupeMinutes = 0): int
            {
                $this->log['notifyOrganization'][] = [$organizationId, $key, $vars];

                return 1;
            }

            public function notifyStaff(string $key, array $vars = [], ?string $dedupeKey = null, int $dedupeMinutes = 0): int
            {
                $this->log['notifyStaff'][] = [$key, $vars];

                return 1;
            }
        };
        $this->app->instance(EmailTemplateService::class, $this->mail);
    }

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

    private function ack(string $agent, array $types, array $output = []): array
    {
        $cmds = array_values(array_filter($this->heartbeat($agent)['commands'], fn ($c) => in_array($c['type'], $types, true)));
        foreach ($cmds as $c) {
            $this->commandResult($agent, $c['id'], 'succeeded', ['output' => $output ?: ['container_id' => 'c-'.$c['id']]])->assertOk();
        }

        return $cmds;
    }

    /** Every backup.run handed out succeeds, or fails with $error. */
    private function backups(string $agent, ?string $error = null): array
    {
        $cmds = array_values(array_filter($this->heartbeat($agent)['commands'], fn ($c) => $c['type'] === 'backup.run'));
        foreach ($cmds as $i => $c) {
            if ($error) {
                $this->commandResult($agent, $c['id'], 'failed', ['message' => $error])->assertOk();

                continue;
            }
            $out = ['snapshot_id' => 'snap'.$i, 'summary' => ['snapshot_id' => 'snap'.$i, 'total_bytes_processed' => 1024], 'check' => ['ok' => true]];
            if (isset($c['payload']['dump'])) {
                $out['dump'] = ['bytes' => 2048];
            }
            $this->commandResult($agent, $c['id'], 'succeeded', ['output' => $out])->assertOk();
        }

        return $cmds;
    }

    private function rollout(string $agent): void
    {
        for ($i = 0; $i < 4; $i++) {
            foreach ($this->heartbeat($agent)['commands'] as $c) {
                $out = $c['type'] === 'container.healthcheck' ? ['output' => ['healthy' => true, 'method' => 'http', 'url' => 'http://10.0.0.2:8080/', 'status' => 200]] : ['output' => ['container_id' => 'c-'.$c['id']]];
                $this->commandResult($agent, $c['id'], 'succeeded', $out)->assertOk();
            }
        }
    }

    /** Awaiting setup on 3.2.0-3011 with 3.2.1-3100 loaded: [id, token, agent]. */
    private function ready(): array
    {
        [, $org, $ownerToken] = $this->org();
        $server = $this->server($org, ['ram_mb' => 65536, 'tags' => ['enterprise']]);
        [$agent] = $this->enrollAgent($server, $ownerToken);
        [$svc] = $this->member($org, 'enterprise_service');
        $token = $this->tokenFor($svc, ['enterprise.deploy']);
        config(['enterprise.require_loaded_image' => false]);
        $id = $this->api($token)->withHeader('Idempotency-Key', 'c1')->postJson('/api/v1/enterprise/deployments', [
            'external_id' => 'ent_s', 'customer_name' => 'Acme', 'slug' => 'acme', 'version' => '3.2.0-3011', 'size' => 'small',
        ])->assertStatus(202)->json('data.deployment_id');
        $this->ack($agent, ['container.create']);
        $this->sync();
        $this->ack($agent, ['container.create']);
        $this->passHealthChecks($agent);
        $this->sync();
        $this->assertSame('awaiting_setup', EnterpriseDeployment::find($id)->status);
        foreach (['3.2.0-3011', '3.2.1-3100'] as $i => $tag) {
            Image::allTenants()->create(['organization_id' => $server->organization_id, 'server_id' => $server->id, 'docker_id' => 'sha256:'.$i, 'repository' => 'worksuite-server', 'tags' => ['worksuite-server:'.$tag], 'size_mb' => 900]);
        }
        $this->ack($agent, ['cron.exec'], ['exit_code' => 0, 'output' => '']);

        return [$id, $token, $agent];
    }

    /** The summaries sent so far: [key, vars] per recipient. */
    private function summaries(?string $run = null): array
    {
        $out = [];
        foreach ($this->calls('notify') as [$key, , $vars]) {
            if (in_array($key, [EnterpriseRunReportService::SUCCEEDED, EnterpriseRunReportService::FAILED], true) && ($run === null || str_starts_with($vars['run'], $run))) {
                $out[] = [$key, $vars];
            }
        }

        return $out;
    }

    /** @return array<int, array> the argument lists of every call to $method */
    private function calls(string $method): array
    {
        return $this->mail->log[$method] ?? [];
    }

    private function assertNoPerItemEmails(): void
    {
        foreach ($this->calls('notifyOrganization') as $args) {
            $this->assertNotContains($args[1], ['deploy_succeeded', 'deploy_failed', 'backup_failed'], 'no per-item email for an enterprise item');
        }
        foreach ($this->calls('notifyStaff') as $args) {
            $this->assertNotContains($args[0], ['app_installed_admin', 'deploy_failed'], 'no per-item staff email for an enterprise item');
        }
    }

    public function test_provisioning_sends_one_summary_and_no_per_container_email(): void
    {
        [$id] = $this->ready();
        $this->sync();
        $this->sync();

        $sent = $this->summaries('Provisioning');
        $this->assertCount(1, $sent, 'one summary for the owner, once');
        [$key, $vars] = $sent[0];
        $this->assertSame(EnterpriseRunReportService::SUCCEEDED, $key);
        $this->assertSame('Ready for the setup wizard', $vars['result']);
        $this->assertSame(EnterpriseDeployment::find($id)->hostname, $vars['deployment']);
        $this->assertStringContainsString('WorkSuite Server', $vars['steps']);
        $this->assertStringContainsString('/admin/projects/', $vars['deployment_url']);
        $this->assertNoPerItemEmails();
    }

    public function test_an_update_whose_backup_fails_sends_one_failed_summary_with_the_error(): void
    {
        [$id, $token, $agent] = $this->ready();
        $run = $this->api($token)->withHeader('Idempotency-Key', 'u1')->postJson("/api/v1/enterprise/deployments/{$id}/updates", ['version' => '3.2.1-3100', 'reason' => 'monthly', 'requested_by' => 'Ops Person'])
            ->assertStatus(202)->json('data');
        $this->assertCount(2, $this->backups($agent, 'restic is not installed on this server'));
        $this->tick();
        $this->tick();
        $this->sync();
        $this->assertSame('failed', EnterpriseUpdate::find($run['id'])->status);

        $sent = $this->summaries('Update');
        $this->assertCount(1, $sent);
        [$key, $vars] = $sent[0];
        $this->assertSame(EnterpriseRunReportService::FAILED, $key);
        $this->assertSame('Ops Person', $vars['triggered_by']);
        $this->assertSame('monthly', $vars['reason']);
        $this->assertSame('3.2.0-3011 → 3.2.1-3100', $vars['versions']);
        $this->assertStringContainsString('restic is not installed on this server', $vars['failures']);
        $this->assertStringContainsString('Fix: '.EnterpriseProvisioningService::HINTS['RESTIC_MISSING'], $vars['failures']);
        $this->assertStringContainsString('database ent-acme-db', $vars['steps']);
        $this->assertStringContainsString('application ent-acme', $vars['steps']);
        $this->assertStringStartsWith($run['backup_id'], $vars['backup']);
        $this->assertNoPerItemEmails();
        $this->assertSame(1, EnterpriseRunReport::where('run_key', 'update:'.$run['id'])->whereNotNull('emailed_at')->count());

        // The rendered email leads with the failure and carries the per-job table.
        $real = new EmailTemplateService(app(\App\Services\AuditLogger::class));
        $mail = $real->render(EnterpriseRunReportService::FAILED, $vars);
        $this->assertStringContainsString('failed', $mail['subject']);
        $this->assertStringContainsString('restic is not installed on this server', $mail['text']);
        $this->assertLessThan(strpos($mail['text'], 'STEP'), strpos($mail['text'], 'restic is not installed'), 'failures come before the table');
    }

    public function test_a_successful_update_sends_one_summary_and_no_is_live_emails(): void
    {
        [$id, $token, $agent] = $this->ready();
        $run = $this->api($token)->withHeader('Idempotency-Key', 'u1')->postJson("/api/v1/enterprise/deployments/{$id}/updates", ['version' => '3.2.1-3100'])->assertStatus(202)->json('data');
        $this->backups($agent);
        $this->tick();
        $this->rollout($agent);
        $this->tick();
        $this->tick();
        $this->assertSame('succeeded', EnterpriseUpdate::find($run['id'])->status);

        $sent = $this->summaries('Update');
        $this->assertCount(1, $sent);
        $this->assertSame(EnterpriseRunReportService::SUCCEEDED, $sent[0][0]);
        $this->assertStringContainsString('Deploy ent-acme', $sent[0][1]['steps']);
        $this->assertStringContainsString('Pre-update backup', $sent[0][1]['steps']);
        $this->assertNoPerItemEmails();
    }

    public function test_a_backup_set_from_the_deployment_sends_one_summary(): void
    {
        [$id, $token, $agent] = $this->ready();
        $this->api($token)->withHeader('Idempotency-Key', 'b1')->postJson("/api/v1/enterprise/deployments/{$id}/backups")->assertStatus(202);
        $this->backups($agent, 'restic is not installed on this server');
        $this->sync();
        $this->sync();

        $sent = $this->summaries('Backup');
        $this->assertCount(1, $sent, 'one email for the whole set, not one per job');
        $this->assertSame(EnterpriseRunReportService::FAILED, $sent[0][0]);
        $this->assertStringContainsString('restic is not installed on this server', $sent[0][1]['failures']);
        $this->assertNoPerItemEmails();
    }

    public function test_a_manual_restart_sends_one_summary_when_its_containers_finish(): void
    {
        [$id, $token, $agent] = $this->ready();
        $this->api($token)->withHeader('Idempotency-Key', 'r1')->postJson("/api/v1/enterprise/deployments/{$id}/actions/restart", ['reason' => 'stuck queue'])->assertStatus(202);
        $this->sync();
        $this->assertSame([], $this->summaries('Restart'), 'nothing while it runs');
        $this->rollout($agent);
        $this->sync();
        $this->sync();

        $sent = $this->summaries('Restart');
        $this->assertCount(1, $sent);
        $this->assertSame('stuck queue', $sent[0][1]['reason']);
    }

    public function test_normal_projects_keep_their_per_item_emails(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $app = $this->api($token)->postJson('/api/v1/applications', ['name' => 'web', 'source_type' => 'image', 'image' => 'nginx:1.27', 'server_id' => $server->id, 'port' => 80, 'environment' => 'staging'])
            ->assertCreated()->json('data.id');
        $this->assertFalse(EnterpriseRunReportService::ownsApplication($app));
        $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $app])->assertSuccessful();
        $this->commandResult($secret, $this->heartbeat($secret)['commands'][0]['id'], 'succeeded', ['output' => ['container_id' => 'c1']]);
        $this->passHealthChecks($secret);

        $this->assertCount(1, array_filter($this->calls('notifyOrganization'), fn ($c) => $c[1] === 'deploy_succeeded'));
        $this->assertSame([], $this->summaries());
    }

    public function test_opting_out_of_the_replaced_emails_stops_the_summary(): void
    {
        [$id] = $this->ready();
        $dep = EnterpriseDeployment::find($id);
        $svc = app(EnterpriseRunReportService::class);
        $users = $svc->recipients($dep->organization_id, EnterpriseRunReportService::SUCCEEDED);
        $this->assertCount(1, $users);
        $users[0]->update(['email_preferences' => ['opt_out' => ['deploy_succeeded']]]);
        $this->assertCount(0, $svc->recipients($dep->organization_id, EnterpriseRunReportService::SUCCEEDED));
        $this->assertCount(1, $svc->recipients($dep->organization_id, EnterpriseRunReportService::FAILED), 'failures still arrive while backup_failed is wanted');
    }
}
