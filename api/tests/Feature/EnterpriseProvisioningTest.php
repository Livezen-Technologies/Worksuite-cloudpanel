<?php

namespace Tests\Feature;

use App\Models\AgentCommand;
use App\Models\Application;
use App\Models\EnterpriseDeployment;
use App\Models\EnterpriseWebhook;
use App\Models\EnvVar;
use App\Models\Image;
use App\Models\Membership;
use App\Models\Project;
use App\Services\EnterpriseProvisioningService;
use App\Services\EnterpriseWebhookService;
use App\Support\Tenant;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnterpriseProvisioningTest extends TestCase
{
    private function body(array $extra = []): array
    {
        return array_merge([
            'external_id' => 'ent_1', 'customer_name' => 'Acme Holdings', 'slug' => 'acme', 'version' => '3.2.0-3011',
            'size' => 'small', 'callback_url' => 'https://account.worksuitecloud.test/api/enterprise/webhook',
        ], $extra);
    }

    /** The service account: role enterprise_service, token scoped to enterprise.deploy. Returns [org, token, server, agentSecret]. */
    private function setUpPanel(): array
    {
        [$owner, $org, $ownerToken] = $this->org();
        $server = $this->server($org, ['ram_mb' => 65536, 'tags' => ['enterprise']]);
        $this->server($org, ['ram_mb' => 65536, 'name' => 'untagged']);
        [$agentSecret] = $this->enrollAgent($server, $ownerToken);
        [$svc] = $this->member($org, 'enterprise_service');

        return [$org, $this->tokenFor($svc, ['enterprise.deploy']), $server, $agentSecret, $ownerToken];
    }

    private function sync(): void
    {
        app(Tenant::class)->set(null);
        app(EnterpriseProvisioningService::class)->sync();
    }

    private function ack(string $agentSecret, string $type, array $output = []): array
    {
        $cmds = array_values(array_filter($this->heartbeat($agentSecret)['commands'], fn ($c) => $c['type'] === $type));
        foreach ($cmds as $c) {
            $this->commandResult($agentSecret, $c['id'], 'succeeded', ['output' => $output ?: ['container_id' => 'c-'.$c['id']]])->assertOk();
        }

        return $cmds;
    }

    public function test_requires_the_enterprise_deploy_ability(): void
    {
        [, , , , $ownerToken] = $this->setUpPanel();
        [$u] = $this->member(\App\Models\Organization::first(), 'enterprise_service');
        $this->api($this->tokenFor($u, ['read']))->withHeader('Idempotency-Key', 'r')->postJson('/api/v1/enterprise/deployments', $this->body())->assertForbidden();
        [$dev, $devToken] = $this->member(\App\Models\Organization::first(), 'developer');
        $this->api($devToken)->getJson('/api/v1/enterprise/deployments')->assertForbidden();
        $this->api($ownerToken)->getJson('/api/v1/enterprise/deployments')->assertForbidden(); // owners lack the permission
    }

    public function test_full_lifecycle_provision_configure_active_and_webhooks(): void
    {
        [$org, $token, $server, $agent] = $this->setUpPanel();

        $this->api($token)->getJson('/api/v1/enterprise/options')->assertOk()->assertJsonPath('data.sizes.0.code', 'small');
        $this->api($token)->getJson('/api/v1/enterprise/slug-availability?slug=acme')->assertOk()->assertJsonPath('data.available', true);
        $this->api($token)->getJson('/api/v1/enterprise/slug-availability?slug=admin')->assertJsonPath('data.available', false);

        $res = $this->api($token)->withHeader('Idempotency-Key', 'c1')->postJson('/api/v1/enterprise/deployments', $this->body())->assertStatus(202);
        $res->assertJsonPath('data.status', 'provisioning')->assertJsonPath('data.node.id', $server->id)
            ->assertJsonPath('data.urls.platform', 'https://acme.worksuite.lk');
        $secret = $res->json('data.callback_secret');
        $this->assertGreaterThanOrEqual(32, strlen((string) $secret));
        $id = $res->json('data.deployment_id');

        // Same external id → the same deployment; taken slug → 422.
        $this->api($token)->withHeader('Idempotency-Key', 'c2')->postJson('/api/v1/enterprise/deployments', $this->body())->assertOk()->assertJsonMissingPath('data.callback_secret');
        $this->api($token)->withHeader('Idempotency-Key', 'c3')->postJson('/api/v1/enterprise/deployments', $this->body(['external_id' => 'ent_2']))->assertStatus(422);

        $dep = EnterpriseDeployment::findOrFail($id);
        $project = Project::allTenants()->find($dep->project_id);
        $this->assertSame(['enterprise'], $project->tags);
        $this->assertSame('Enterprise · Acme Holdings', $project->name);

        // MariaDB 10.11 + Redis first.
        $created = $this->ack($agent, 'container.create');
        $this->assertCount(2, $created);
        $this->assertEqualsCanonicalizing(['mariadb:10.11', 'redis:7-alpine'], array_column(array_column($created, 'payload'), 'image'));

        // No registry: waits for the image to be docker-loaded on the node.
        $this->sync();
        $dep->refresh();
        $this->assertSame('running', $this->stepOf($dep, 'image'));
        $this->assertNull($dep->app_application_id);
        Image::allTenants()->create(['organization_id' => $server->organization_id, 'server_id' => $server->id, 'docker_id' => 'sha256:abc', 'repository' => 'worksuite-server', 'tags' => ['worksuite-server:3.2.0-3011']]);
        $this->sync();
        $dep->refresh();
        $this->assertNotNull($dep->app_application_id);

        $apps = $this->ack($agent, 'container.create');
        $this->assertCount(2, $apps);
        $web = collect($apps)->first(fn ($c) => ($c['payload']['cmd'] ?? null) === ['web']);
        $cron = collect($apps)->first(fn ($c) => ($c['payload']['cmd'] ?? null) === ['cron']);
        $this->assertNotNull($web);
        $this->assertNotNull($cron);
        $env = (array) $web['payload']['env'];
        $this->assertSame('https://acme.worksuite.lk', $env['APP_URL']);
        $this->assertSame('db-ent-acme-db', $env['DB_HOST']);
        $this->assertSame('10', $env['FPM_MAX_CHILDREN']);
        $this->assertNotEmpty($env['DB_PASSWORD']);
        $sources = array_column($web['payload']['mounts'], 'source');
        $this->assertContains('ent-acme-writable', $sources);
        $this->assertSame($sources, array_column($cron['payload']['mounts'], 'source'), 'web and cron share their volumes');
        $this->passHealthChecks($agent);

        $this->sync();
        $show = $this->api($token)->getJson("/api/v1/enterprise/deployments/{$id}")->assertOk();
        $show->assertJsonPath('data.status', 'awaiting_setup');
        $this->api($token)->getJson('/api/v1/enterprise/deployments/ent_1')->assertOk()->assertJsonPath('data.id', $id);

        // Configure: the bootstrap JSON goes through stdin of spark in the web container.
        $this->api($token)->withHeader('Idempotency-Key', 'cfg')->postJson("/api/v1/enterprise/deployments/{$id}/configure", [
            'bootstrap' => ['organisation' => ['name' => 'Acme'], 'administrator' => ['email' => 'it@acme.test', 'password' => 'S3cure-Passw0rd!']],
        ])->assertStatus(202)->assertJsonPath('data.status', 'configuring');
        $exec = collect($this->heartbeat($agent)['commands'])->firstWhere('type', 'cron.exec');
        $this->assertStringContainsString('worksuite-entrypoint spark enterprise:bootstrap --json -', $exec['payload']['command']);
        $this->assertStringNotContainsString('S3cure', $exec['payload']['command'], 'only base64 on the wire');
        $this->commandResult($agent, $exec['id'], 'succeeded', ['output' => ['exit_code' => 0, 'output' => "warming up\n{\"ok\":true,\"company_id\":1}"]])->assertOk();
        $this->assertSame('[redacted]', AgentCommand::allTenants()->find($exec['id'])->payload['command']);

        $dep->refresh();
        $this->assertSame('active', $dep->status);
        $this->assertSame(['ok' => true, 'company_id' => 1], $dep->bootstrap_result);

        // Webhooks: one per status change, signed with the deployment's secret.
        $events = EnterpriseWebhook::where('enterprise_deployment_id', $id)->orderBy('created_at')->get();
        $this->assertSame(['provisioning', 'awaiting_setup', 'configuring', 'active'], $events->pluck('payload.deployment.status')->all());
        Http::fake(['*' => Http::response('', 204)]);
        $stats = app(EnterpriseWebhookService::class)->deliverDue();
        $this->assertSame(4, $stats['delivered']);
        Http::assertSent(function ($req) use ($secret) {
            preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $req->header('X-Enterprise-Signature')[0], $m);

            return $m && hash_equals(hash_hmac('sha256', $m[1].'.'.$req->body(), $secret), $m[2]);
        });
    }

    public function test_bootstrap_failure_is_reported_redacted_and_can_be_retried(): void
    {
        [, $token, $server, $agent] = $this->setUpPanel();
        config(['enterprise.require_loaded_image' => false]);
        $id = $this->api($token)->withHeader('Idempotency-Key', 'c1')->postJson('/api/v1/enterprise/deployments', $this->body())->json('data.deployment_id');
        $this->ack($agent, 'container.create');
        $this->sync();
        $this->ack($agent, 'container.create');
        $this->passHealthChecks($agent);
        $this->sync();

        $this->api($token)->withHeader('Idempotency-Key', 'cfg1')->postJson("/api/v1/enterprise/deployments/{$id}/configure", ['bootstrap' => ['x' => 1]])->assertStatus(202);
        $exec = collect($this->heartbeat($agent)['commands'])->firstWhere('type', 'cron.exec');
        $this->commandResult($agent, $exec['id'], 'succeeded', ['output' => ['exit_code' => 2, 'output' => '{"ok":false,"error":"invalid: password=hunter22 too weak"}']]);
        $dep = EnterpriseDeployment::find($id);
        $this->assertSame('failed', $dep->status);
        $this->assertStringContainsString('password=[redacted]', $dep->status_reason);

        $this->api($token)->withHeader('Idempotency-Key', 'cfg2')->postJson("/api/v1/enterprise/deployments/{$id}/configure", ['bootstrap' => ['x' => 2], 'custom_domain' => 'erp.acme.test'])->assertStatus(202);
        $this->assertSame('https://erp.acme.test', EnvVar::where('application_id', $dep->app_application_id)->where('key', 'APP_URL')->value('value'));
        $exec = collect($this->heartbeat($agent)['commands'])->firstWhere('type', 'cron.exec');
        $this->commandResult($agent, $exec['id'], 'succeeded', ['output' => ['exit_code' => 5, 'output' => '{"ok":false,"error":"licence server unreachable"}']]);
        $dep->refresh();
        $this->assertSame('active', $dep->status, 'exit 5: set up, licence to be retried');
        $this->assertSame('failed', $this->stepOf($dep, 'licence'));
        $this->api($token)->getJson("/api/v1/enterprise/deployments/{$id}")->assertJsonPath('data.custom_domain.name', 'erp.acme.test');
    }

    public function test_actions_logs_delete_and_purge(): void
    {
        [, $token, , $agent] = $this->setUpPanel();
        config(['enterprise.require_loaded_image' => false]);
        $id = $this->api($token)->withHeader('Idempotency-Key', 'c1')->postJson('/api/v1/enterprise/deployments', $this->body())->json('data.deployment_id');
        $this->ack($agent, 'container.create');
        $this->sync();
        $this->ack($agent, 'container.create');
        $this->passHealthChecks($agent);
        $this->sync();

        $this->api($token)->withHeader('Idempotency-Key', 'a1')->postJson("/api/v1/enterprise/deployments/{$id}/actions/restart")->assertStatus(202)->assertJsonCount(2, 'data.job_ids');
        $this->api($token)->withHeader('Idempotency-Key', 'a2')->postJson("/api/v1/enterprise/deployments/{$id}/actions/stop")->assertStatus(202)->assertJsonPath('data.status', 'stopped');
        $this->api($token)->withHeader('Idempotency-Key', 'a3')->postJson("/api/v1/enterprise/deployments/{$id}/actions/start")->assertJsonPath('data.status', 'awaiting_setup');
        $this->api($token)->withHeader('Idempotency-Key', 'a4')->postJson("/api/v1/enterprise/deployments/{$id}/actions/redeploy", ['version' => '3.2.1-3050'])->assertStatus(202);
        $this->assertStringEndsWith(':3.2.1-3050', Application::allTenants()->find(EnterpriseDeployment::find($id)->app_application_id)->image);
        $this->api($token)->withHeader('Idempotency-Key', 'a5')->postJson("/api/v1/enterprise/deployments/{$id}/actions/explode")->assertNotFound();

        $this->api($token)->getJson("/api/v1/enterprise/deployments/{$id}/logs?container=worker&tail=50")->assertOk()->assertJsonPath('data.container', 'worker');

        $this->api($token)->withHeader('Idempotency-Key', 'p0')->postJson("/api/v1/enterprise/deployments/{$id}/purge", ['confirm' => 'acme'])->assertStatus(409);
        $this->api($token)->deleteJson("/api/v1/enterprise/deployments/{$id}")->assertOk()->assertJsonPath('data.status', 'deleted');
        $this->assertNotNull(Application::allTenants()->find(EnterpriseDeployment::find($id)->app_application_id), 'delete keeps everything');
        $this->api($token)->withHeader('Idempotency-Key', 'p1')->postJson("/api/v1/enterprise/deployments/{$id}/purge", ['confirm' => 'nope'])->assertStatus(422);
        $this->api($token)->withHeader('Idempotency-Key', 'p2')->postJson("/api/v1/enterprise/deployments/{$id}/purge", ['confirm' => 'acme'])->assertOk()->assertJsonPath('data.status', 'purged');
        $this->assertContains('ent-acme-writable', AgentCommand::allTenants()->where('type', 'volume.remove')->get()->pluck('payload.name')->all());
        $this->assertTrue(app(EnterpriseProvisioningService::class)->slugAvailable('acme'), 'a purged slug is free again');
    }

    public function test_service_account_command_issues_a_scoped_token(): void
    {
        [, $org] = $this->org();
        config(['enterprise.organization_id' => $org->id]);
        $this->artisan('enterprise:service-account')->assertExitCode(0);
        $user = \App\Models\User::where('email', 'cloud-admin-enterprise@service.invalid')->first();
        $this->assertSame('enterprise_service', Membership::allTenants()->where('user_id', $user->id)->value('role'));
        $this->assertSame(['enterprise.deploy'], $user->tokens()->first()->abilities);
    }

    private function stepOf(EnterpriseDeployment $dep, string $name): ?string
    {
        return collect($dep->steps)->firstWhere('name', $name)['status'] ?? null;
    }
}
