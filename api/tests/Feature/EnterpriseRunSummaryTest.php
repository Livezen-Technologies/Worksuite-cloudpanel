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
use Illuminate\Support\Str;
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

    /** Awaiting setup on $from with $to loaded: [id, token, agent]. */
    private function ready(string $slug = 'acme', string $from = '3.2.0-3011', string $to = '3.2.1-3100'): array
    {
        [, $org, $ownerToken] = $this->org();
        $server = $this->server($org, ['ram_mb' => 65536, 'tags' => ['enterprise']]);
        [$agent] = $this->enrollAgent($server, $ownerToken);
        [$svc] = $this->member($org, 'enterprise_service');
        $token = $this->tokenFor($svc, ['enterprise.deploy']);
        config(['enterprise.require_loaded_image' => false]);
        $id = $this->api($token)->withHeader('Idempotency-Key', 'c-'.$slug)->postJson('/api/v1/enterprise/deployments', [
            'external_id' => $slug === 'acme' ? 'ent_s' : 'ent_'.$slug, 'customer_name' => ucfirst($slug), 'slug' => $slug, 'version' => $from, 'size' => 'small',
        ])->assertStatus(202)->json('data.deployment_id');
        $this->ack($agent, ['container.create']);
        $this->sync();
        $this->ack($agent, ['container.create']);
        $this->passHealthChecks($agent);
        $this->sync();
        $this->assertSame('awaiting_setup', EnterpriseDeployment::find($id)->status);
        foreach ([$from, $to] as $i => $tag) {
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

    // ------------------------------------------------------------------ 1.41.1: the email itself

    private function render(array $sent): array
    {
        [$key, $vars] = $sent;
        $mail = (new EmailTemplateService(app(\App\Services\AuditLogger::class)))->render($key, $vars);
        if ($dir = getenv('RUN_MAIL_RENDER_DIR')) {
            // Local review only: RUN_MAIL_RENDER_DIR=/path php vendor/bin/phpunit --filter EnterpriseRunSummaryTest
            @mkdir($dir, 0777, true);
            $name = Str::slug($mail['subject']);
            file_put_contents($dir.'/'.$name.'.html', $mail['html']);
            file_put_contents($dir.'/'.$name.'.txt', "Subject: {$mail['subject']}\nPreview: {$mail['preview']}\n\n".$mail['text']);
        }

        return $mail;
    }

    private function assertReadable(array $mail): void
    {
        $this->assertStringContainsString('max-width:600px', $mail['html']);
        $this->assertStringContainsString('prefers-color-scheme:dark', $mail['html']);
        $this->assertStringNotContainsString('<link', $mail['html'], 'no external stylesheet');
        $this->assertStringNotContainsString('{{', $mail['subject'].$mail['html'].$mail['text']);
        $this->assertStringNotContainsString('<', $mail['text'], 'the plain-text part has no markup');
        $this->assertStringContainsString('STEP', $mail['text'], 'the plain-text part carries the step table');
        $this->assertStringContainsString('class="st"', $mail['html'], 'the steps are a real table');
        $this->assertStringContainsString('UTC', $mail['text']);
        $this->assertStringContainsString('Open the deployment:', $mail['text']);
    }

    public function test_the_four_summaries_read_clearly(): void
    {
        config(['enterprise.admin_url' => 'https://account.worksuitecloud.com/admin/enterprise/deployments/?id={external_id}']);
        // A successful update.
        [$id, $token, $agent] = $this->ready('computerage', '3.0.9-4410', '3.1.0-4558');
        $host = EnterpriseDeployment::find($id)->hostname;
        $provisioned = $this->render($this->summaries('Provisioning')[0]);
        $this->assertSame("✓ {$host} is ready for the setup wizard", $provisioned['subject']);
        $this->assertReadable($provisioned);
        $this->assertStringNotContainsString('class="al"', $provisioned['html'], 'no failure box on a success');

        $this->api($token)->withHeader('Idempotency-Key', 'u1')->postJson("/api/v1/enterprise/deployments/{$id}/updates", ['version' => '3.1.0-4558', 'reason' => 'November release', 'requested_by' => 'Ops Person'])->assertStatus(202);
        $this->backups($agent);
        $this->tick();
        $this->rollout($agent);
        $this->tick();
        $this->tick();
        $ok = $this->render($this->summaries('Update')[0]);
        $this->assertSame("✓ {$host} updated to 3.1.0-4558", $ok['subject']);
        $this->assertReadable($ok);
        $this->assertStringContainsString('3.0.9-4410 → 3.1.0-4558', $ok['text']);
        $this->assertStringContainsString('Ops Person', $ok['text']);
        $this->assertStringContainsString('November release', $ok['text']);
        $this->assertStringContainsString('Run log (UTC)', $ok['text']);
        $this->assertStringContainsString('Open the deployment: https://account.worksuitecloud.com/admin/enterprise/deployments/?id=ent_computerage', $ok['text']);
        $this->assertStringContainsString('href="https://account.worksuitecloud.com/admin/enterprise/deployments/?id=ent_computerage"', $ok['html']);

        // An update whose pre-update backup fails: nothing changed, the failure and its fix lead.
        $this->mail->log = [];
        [$id2, $token2, $agent2] = $this->ready('acme', '3.0.9-4410', '3.1.0-4558');
        $host2 = EnterpriseDeployment::find($id2)->hostname;
        $run = $this->api($token2)->withHeader('Idempotency-Key', 'u2')->postJson("/api/v1/enterprise/deployments/{$id2}/updates", ['version' => '3.1.0-4558', 'requested_by' => 'Ops Person'])->assertStatus(202)->json('data');
        $this->backups($agent2, 'restic is not installed on this server');
        $this->tick();
        $this->tick();
        $this->sync();
        $failed = $this->render($this->summaries('Update')[0]);
        $this->assertSame("✗ {$host2} update to 3.1.0-4558 failed — nothing changed", $failed['subject']);
        $this->assertReadable($failed);
        $this->assertStringContainsString('restic is not installed on this server', $failed['preview']);
        $this->assertLessThan(strpos($failed['html'], 'class="st"'), strpos($failed['html'], 'class="al"'), 'the failure box comes before the step table');
        $this->assertLessThan(strpos($failed['text'], 'STEP'), strpos($failed['text'], 'How to fix: '.EnterpriseProvisioningService::HINTS['RESTIC_MISSING']), 'and in the text part');
        $this->assertStringContainsString('still runs 3.0.9-4410', $failed['text']);
        $this->assertStringContainsString($run['backup_id'], $failed['text']);

        // Rolled back, data restored.
        $dep = EnterpriseDeployment::find($id2);
        $dep->update(['version' => '3.0.9-4410']);
        \App\Models\User::whereIn('id', app(EnterpriseRunReportService::class)->recipients($dep->organization_id, EnterpriseRunReportService::FAILED)->pluck('id'))->update(['timezone' => 'Asia/Colombo']);
        $at = now()->subMinutes(9);
        $rb = EnterpriseUpdate::create([
            'organization_id' => $dep->organization_id, 'enterprise_deployment_id' => $dep->id, 'from_version' => '3.0.9-4410', 'to_version' => '3.1.0-4558',
            'trigger' => 'manual', 'dedupe_key' => 'rb', 'status' => 'rolled_back', 'requested_by_name' => 'Ops Person', 'reason' => 'retry after restic',
            'error' => 'The app container did not become healthy on 3.1.0-4558.', 'started_at' => $at, 'deploy_started_at' => $at->copy()->addMinutes(2),
            'rollback_started_at' => $at->copy()->addMinutes(6), 'finished_at' => now(),
            'data_restore' => ['state' => 'succeeded', 'backup_id' => 'b-1'],
            'log' => [['at' => $at->toIso8601ZuluString(), 'message' => 'Backing up'], ['at' => now()->toIso8601ZuluString(), 'message' => 'Rolled back, data restored']],
        ]);
        app(EnterpriseRunReportService::class)->updateFinished($rb);
        $sent = array_values(array_filter($this->summaries('Update'), fn ($x) => str_contains($x[1]['result'], 'Rolled back')));
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('Asia/Colombo', $sent[0][1]['started'], 'times in the recipient’s timezone');
        $this->assertStringContainsString('UTC)', $sent[0][1]['started'], 'with UTC beside them');
        $back = $this->render($sent[0]);
        $this->assertSame("✗ {$host2} update to 3.1.0-4558 failed — rolled back to 3.0.9-4410, data restored", $back['subject']);
        $this->assertStringContainsString('did not become healthy', $back['text']);
        $this->assertStringContainsString('its data was restored', $back['text']);
        $this->assertStringContainsString('Data restore', $back['text']);
    }

    public function test_no_secret_reaches_the_email(): void
    {
        [$id, $token, $agent] = $this->ready();
        $this->api($token)->withHeader('Idempotency-Key', 'b1')->postJson("/api/v1/enterprise/deployments/{$id}/backups")->assertStatus(202);
        $this->backups($agent, 'restic: repository wrong, RESTIC_PASSWORD=Sup3rS3cretValue token: abcdef123456');
        $this->sync();
        $this->sync();
        [$key, $vars] = $this->summaries('Backup')[0];
        $mail = $this->render([$key, $vars]);
        foreach (['Sup3rS3cretValue', 'abcdef123456'] as $secret) {
            $this->assertStringNotContainsString($secret, $mail['html'].$mail['text'].$mail['subject'].$mail['preview']);
        }
        $this->assertStringContainsString('[redacted]', $mail['text']);
        $this->assertSame('✗ '.EnterpriseDeployment::find($id)->hostname.' backup failed', $mail['subject']);
    }

    public function test_the_deployment_link_uses_the_static_exports_query_form(): void
    {
        [$id] = $this->ready();
        $dep = EnterpriseDeployment::find($id);
        $svc = app(EnterpriseRunReportService::class);
        $pathForm = '#/deployments/[0-9a-f]{8}-[0-9a-f]{4}-#i';

        // The default (no ENTERPRISE_ADMIN_URL): the panel project, never a Cloud Admin path-form link.
        config(['enterprise.admin_url' => '']);
        $this->assertSame(rtrim((string) (config('platform.panel_url') ?: config('app.url')), '/').'/admin/projects/'.$dep->project_id, $svc->link($dep));
        $this->assertDoesNotMatchRegularExpression($pathForm, $svc->link($dep));
        $this->assertDoesNotMatchRegularExpression('#/deployments/[^?]#', $svc->link($dep));

        // The live setting: ?id= with the Cloud Admin uid.
        config(['enterprise.admin_url' => 'https://account.worksuitecloud.com/admin/enterprise/deployments/?id={external_id}']);
        $this->assertSame('https://account.worksuitecloud.com/admin/enterprise/deployments/?id=ent_s', $svc->link($dep));
        config(['enterprise.admin_url' => 'https://account.worksuitecloud.com/admin/enterprise/deployments/?id={id}']);
        $this->assertSame('https://account.worksuitecloud.com/admin/enterprise/deployments/?id='.$dep->id, $svc->link($dep));

        // The old path-form setting is turned into the ?id= form (it was a 404), keeping a query.
        config(['enterprise.admin_url' => 'https://account.worksuitecloud.com/admin/enterprise/deployments/{id}']);
        $this->assertSame('https://account.worksuitecloud.com/admin/enterprise/deployments/?id='.$dep->id, $svc->link($dep));
        config(['enterprise.admin_url' => 'https://account.worksuitecloud.com/admin/enterprise/deployments/{external_id}/?tab=updates']);
        $this->assertSame('https://account.worksuitecloud.com/admin/enterprise/deployments/?id=ent_s&tab=updates', $svc->link($dep));

        // Values are URL-encoded; a placeholder with no value falls back to the panel.
        $dep->external_id = 'ent a&b/c';
        $this->assertSame('https://account.worksuitecloud.com/admin/enterprise/deployments/?id=ent%20a%26b%2Fc&tab=updates', $svc->link($dep));
        $dep->external_id = null;
        $this->assertStringContainsString('/admin/projects/'.$dep->project_id, $svc->link($dep));
        // A setting that is not a web address is ignored.
        config(['enterprise.admin_url' => 'javascript:alert(1)//{id}']);
        $this->assertStringContainsString('/admin/projects/', $svc->link($dep));
    }
}
