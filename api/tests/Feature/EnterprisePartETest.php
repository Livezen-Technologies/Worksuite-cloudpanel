<?php

namespace Tests\Feature;

use App\Models\AgentCommand;
use App\Models\Application;
use App\Models\Domain;
use App\Models\EnterpriseDeployment;
use App\Models\EnvVar;
use App\Models\Image;
use App\Services\EnterpriseProvisioningService;
use App\Support\Tenant;
use Tests\TestCase;

/** Enterprise part E: versions, nodes, resize, metrics, domains, licence refresh, step retry. */
class EnterprisePartETest extends TestCase
{
    private function setUpPanel(): array
    {
        [, $org, $ownerToken] = $this->org();
        $server = $this->server($org, ['ram_mb' => 65536, 'tags' => ['enterprise']]);
        [$agentSecret] = $this->enrollAgent($server, $ownerToken);
        [$svc] = $this->member($org, 'enterprise_service');

        return [$org, $this->tokenFor($svc, ['enterprise.deploy']), $server, $agentSecret, $ownerToken];
    }

    private function sync(): void
    {
        app(Tenant::class)->set(null);
        app(EnterpriseProvisioningService::class)->sync();
    }

    private function ack(string $agent, string $type, array $output = []): array
    {
        $cmds = array_values(array_filter($this->heartbeat($agent)['commands'], fn ($c) => $c['type'] === $type));
        foreach ($cmds as $c) {
            $this->commandResult($agent, $c['id'], 'succeeded', ['output' => $output ?: ['container_id' => 'c-'.$c['id']]])->assertOk();
        }

        return $cmds;
    }

    /** A deployment that is awaiting setup; returns [id, token, agent, server, app container.create payloads]. */
    private function ready(): array
    {
        [, $token, $server, $agent] = $this->setUpPanel();
        config(['enterprise.require_loaded_image' => false]);
        $id = $this->api($token)->withHeader('Idempotency-Key', 'c1')->postJson('/api/v1/enterprise/deployments', [
            'external_id' => 'ent_e', 'customer_name' => 'Acme', 'slug' => 'acme', 'version' => '3.2.0-3011', 'size' => 'small',
        ])->assertStatus(202)->json('data.deployment_id');
        $this->ack($agent, 'container.create');
        $this->sync();
        $apps = $this->ack($agent, 'container.create');
        $this->passHealthChecks($agent);
        $this->sync();
        $this->assertSame('awaiting_setup', EnterpriseDeployment::find($id)->status);

        return [$id, $token, $agent, $server, $apps];
    }

    public function test_web_and_worker_get_the_capabilities_the_entrypoint_needs(): void
    {
        [$id, $token, $agent, , $apps] = $this->ready();
        $this->assertCount(2, $apps);
        foreach ($apps as $c) {
            $this->assertEqualsCanonicalizing(['CHOWN', 'DAC_OVERRIDE', 'FOWNER', 'SETGID', 'SETUID'], $c['payload']['cap_add'] ?? [], 'cap_add on '.json_encode($c['payload']['cmd'] ?? null));
        }
        // ...and they stay on a redeploy.
        $this->api($token)->withHeader('Idempotency-Key', 'r1')->postJson("/api/v1/enterprise/deployments/{$id}/actions/redeploy")->assertStatus(202);
        $again = array_values(array_filter($this->heartbeat($agent)['commands'], fn ($c) => $c['type'] === 'container.create'));
        $this->assertNotEmpty($again);
        foreach ($again as $c) {
            $this->assertContains('DAC_OVERRIDE', $c['payload']['cap_add'] ?? []);
        }
    }

    public function test_versions_merge_inventory_and_a_direct_scan_and_nodes_show_capacity(): void
    {
        [, $token, $server, $agent] = $this->setUpPanel();
        Image::allTenants()->create(['organization_id' => $server->organization_id, 'server_id' => $server->id, 'docker_id' => 'sha256:a', 'repository' => 'worksuite-server', 'tags' => ['worksuite-server:3.2.0-3011'], 'size_mb' => 900]);
        Image::allTenants()->create(['organization_id' => $server->organization_id, 'server_id' => $server->id, 'docker_id' => 'sha256:b', 'repository' => 'nginx', 'tags' => ['nginx:1']]);

        $res = $this->api($token)->getJson('/api/v1/enterprise/versions')->assertOk();
        $res->assertJsonPath('data.image', 'worksuite-server')->assertJsonPath('data.versions.0.version', '3.2.0-3011')
            ->assertJsonPath('data.versions.0.nodes.0', $server->name)->assertJsonCount(1, 'data.versions');

        // The scan went to the node as a host-mode exec; its answer adds a tag the inventory missed.
        $scan = collect($this->heartbeat($agent)['commands'])->firstWhere('type', 'cron.exec');
        $this->assertSame('host', $scan['payload']['mode']);
        $this->assertStringContainsString('docker images', $scan['payload']['command']);
        $this->commandResult($agent, $scan['id'], 'succeeded', ['output' => ['exit_code' => 0, 'output' => "worksuite-server:3.2.0-3011\nworksuite-server:3.2.1-3050\nworksuite-server:<none>\n"]])->assertOk();
        $versions = $this->api($token)->getJson('/api/v1/enterprise/versions')->assertOk()->json('data.versions');
        $this->assertEqualsCanonicalizing(['3.2.0-3011', '3.2.1-3050'], array_column($versions, 'version'));
        $this->assertNotNull($this->api($token)->getJson('/api/v1/enterprise/versions')->json('data.nodes.0.scanned_at'));

        // Provisioning trusts the scan when the inventory lags.
        $this->assertContains('worksuite-server:3.2.1-3050', app(EnterpriseProvisioningService::class)->scannedTags($server));

        $node = $this->api($token)->getJson('/api/v1/enterprise/nodes')->assertOk()->json('data.nodes.0');
        $this->assertSame($server->id, $node['id']);
        $this->assertTrue($node['eligible']);
        $this->assertGreaterThan(0, $node['ram_mb']['free']);
        $this->assertArrayHasKey('free', $node['disk_gb']);
    }

    public function test_resize_metrics_and_limits(): void
    {
        [$id, $token] = $this->ready();
        $this->api($token)->withHeader('Idempotency-Key', 'z1')->postJson("/api/v1/enterprise/deployments/{$id}/resize", [])->assertStatus(422);
        $res = $this->api($token)->withHeader('Idempotency-Key', 'z2')->postJson("/api/v1/enterprise/deployments/{$id}/resize", ['cpus' => 2, 'ram_mb' => 2048, 'disk_gb' => 40])->assertStatus(202);
        $res->assertJsonPath('data.limits.size', 'custom')->assertJsonPath('data.limits.ram_mb', 2048)->assertJsonPath('data.limits.disk_gb', 40)
            ->assertJsonPath('data.limits.fpm_max_children', 25);
        $dep = EnterpriseDeployment::find($id);
        $app = Application::allTenants()->find($dep->app_application_id);
        $this->assertSame(2048, (int) $app->memory_limit_mb);
        $this->assertSame('25', EnvVar::where('application_id', $app->id)->where('key', 'FPM_MAX_CHILDREN')->value('value'));
        $this->assertSame('running', collect($dep->steps)->firstWhere('name', 'resize')['status']);

        $this->api($token)->withHeader('Idempotency-Key', 'z3')->postJson("/api/v1/enterprise/deployments/{$id}/resize", ['size' => 'medium'])->assertStatus(202)
            ->assertJsonPath('data.limits.size', 'medium')->assertJsonPath('data.limits.ram_mb', 2048)->assertJsonPath('data.size', 'medium');

        $m = $this->api($token)->getJson("/api/v1/enterprise/deployments/{$id}/metrics?range=24h")->assertOk();
        $m->assertJsonPath('data.range', '24h')->assertJsonCount(4, 'data.containers')->assertJsonPath('data.disk.limit_gb', 50);
        $this->api($token)->getJson("/api/v1/enterprise/deployments/{$id}/metrics?range=9y")->assertStatus(422);
    }

    public function test_domains_add_verify_primary_remove(): void
    {
        [$id, $token] = $this->ready();
        $this->api($token)->withHeader('Idempotency-Key', 'd1')->postJson("/api/v1/enterprise/deployments/{$id}/domains", ['domain' => 'ERP.acme.test'])->assertStatus(201)
            ->assertJsonPath('data.name', 'erp.acme.test')->assertJsonPath('data.kind', 'custom')->assertJsonPath('data.primary', false);
        $this->api($token)->withHeader('Idempotency-Key', 'd2')->postJson("/api/v1/enterprise/deployments/{$id}/domains/erp.acme.test/verify")->assertOk()->assertJsonPath('data.name', 'erp.acme.test');
        $this->api($token)->withHeader('Idempotency-Key', 'd3')->postJson("/api/v1/enterprise/deployments/{$id}/domains/erp.acme.test/primary")->assertStatus(409);

        Domain::allTenants()->where('name', 'erp.acme.test')->update(['dns_status' => 'verified', 'https_status' => 'active']);
        $res = $this->api($token)->withHeader('Idempotency-Key', 'd4')->postJson("/api/v1/enterprise/deployments/{$id}/domains/erp.acme.test/primary")->assertStatus(202);
        $res->assertJsonPath('data.urls.custom', 'https://erp.acme.test');
        $this->assertCount(2, $res->json('data.domains'));
        $dep = EnterpriseDeployment::find($id);
        $this->assertSame('https://erp.acme.test', EnvVar::where('application_id', $dep->app_application_id)->where('key', 'APP_URL')->value('value'));

        $this->api($token)->deleteJson("/api/v1/enterprise/deployments/{$id}/domains/acme.worksuite.lk")->assertStatus(409);
        $this->api($token)->deleteJson("/api/v1/enterprise/deployments/{$id}/domains/erp.acme.test")->assertOk()->assertJsonPath('data.custom_domain', null);
        $this->assertSame('https://acme.worksuite.lk', EnvVar::where('application_id', $dep->app_application_id)->where('key', 'APP_URL')->value('value'));
        $this->api($token)->withHeader('Idempotency-Key', 'd5')->postJson("/api/v1/enterprise/deployments/{$id}/domains/nope.test/verify")->assertNotFound();
    }

    public function test_licence_refresh_runs_in_the_web_container(): void
    {
        [$id, $token, $agent] = $this->ready();
        $this->api($token)->withHeader('Idempotency-Key', 'l0')->postJson("/api/v1/enterprise/deployments/{$id}/licence-refresh")->assertStatus(409);
        EnterpriseDeployment::find($id)->update(['status' => 'active', 'activated_at' => now(), 'status_checked_at' => now()]);
        $this->api($token)->withHeader('Idempotency-Key', 'l1')->postJson("/api/v1/enterprise/deployments/{$id}/licence-refresh")->assertStatus(202)->assertJsonStructure(['data' => ['job_id', 'status']]);
        $exec = collect($this->heartbeat($agent)['commands'])->firstWhere('type', 'cron.exec');
        $this->assertSame(['host', '0:0'], [$exec['payload']['mode'], $exec['payload']['user']], 'root exec: docker exec -u 0:0 from the host');
        $this->assertStringContainsString('spark license:refresh', $exec['payload']['command']);
        $this->commandResult($agent, $exec['id'], 'succeeded', ['output' => ['exit_code' => 0, 'output' => '{"ok":true}']])->assertOk();
        $this->assertSame('succeeded', collect(EnterpriseDeployment::find($id)->steps)->firstWhere('name', 'licence_refresh')['status']);
    }

    public function test_licence_activate_sends_the_key_on_stdin_and_keeps_the_result(): void
    {
        [$id, $token, $agent] = $this->ready();
        EnterpriseDeployment::find($id)->update(['status' => 'active', 'activated_at' => now(), 'status_checked_at' => now()]);
        $key = 'WS-AB23-CD45-EF67-GH89-JK23';
        $this->api($token)->withHeader('Idempotency-Key', 'a0')->postJson("/api/v1/enterprise/deployments/{$id}/licence-activate", ['key' => 'WSB-AB23-CD45-EF67-GH89'])->assertStatus(422);
        $this->api($token)->withHeader('Idempotency-Key', 'a1')->postJson("/api/v1/enterprise/deployments/{$id}/licence-activate", ['key' => strtolower($key)])->assertStatus(202)->assertJsonStructure(['data' => ['job_id', 'status']]);
        $exec = collect($this->heartbeat($agent)['commands'])->firstWhere('type', 'cron.exec');
        $this->assertSame(['host', '0:0'], [$exec['payload']['mode'], $exec['payload']['user']]);
        $this->assertStringContainsString('WORKSUITE_LICENSE_KEY="$(printf %s ', $exec['payload']['command']);
        $this->assertStringContainsString('spark license:refresh', $exec['payload']['command']);
        $this->assertStringNotContainsString('--key -', $exec['payload']['command']);
        $this->assertStringContainsString(base64_encode($key), $exec['payload']['command']);
        $this->assertStringNotContainsString($key, $exec['payload']['command'], 'the key is never in the command line');
        $this->commandResult($agent, $exec['id'], 'succeeded', ['output' => ['exit_code' => 0, 'output' => '{"ok":true,"action":"activated","status":"active","key_masked":"WS-****-****-****-****-JK23","edition":"enterprise","expires_at":"2027-11-21T00:00:00Z","max_users":50}']])->assertOk();
        $step = collect(EnterpriseDeployment::find($id)->steps)->firstWhere('name', 'licence_refresh');
        $this->assertSame(['succeeded', 'active', 'enterprise', 50], [$step['status'], $step['result']['status'], $step['result']['edition'], $step['result']['max_users']]);
        $this->assertStringNotContainsString(base64_encode($key), json_encode(\App\Models\AgentCommand::allTenants()->find($exec['id'])->payload));
        $this->assertFalse(\Illuminate\Support\Str::contains(json_encode(\App\Models\AuditLog::query()->get()->toArray()), $key));
    }

    public function test_redeploy_and_retry_bring_a_failed_deployment_back(): void
    {
        [$id, $token] = $this->ready();
        $svc = app(EnterpriseProvisioningService::class);
        $dep = EnterpriseDeployment::find($id);
        Application::allTenants()->whereKey($dep->app_application_id)->update(['status' => 'failed']);
        $svc->step($dep, 'app', 'failed', 'unhealthy');
        $svc->setStatus($dep, 'failed');

        $this->api($token)->withHeader('Idempotency-Key', 'x1')->postJson("/api/v1/enterprise/deployments/{$id}/actions/redeploy")->assertStatus(202)->assertJsonPath('data.status', 'provisioning');
        $dep->refresh();
        $this->assertSame('running', collect($dep->steps)->firstWhere('name', 'app')['status']);
        $this->assertSame('deploying', Application::allTenants()->find($dep->app_application_id)->status);

        // Retry of one failed step.
        $svc->step($dep, 'worker', 'failed', 'unhealthy');
        $svc->setStatus($dep, 'failed');
        $this->api($token)->withHeader('Idempotency-Key', 'x2')->postJson("/api/v1/enterprise/deployments/{$id}/steps/app/retry")->assertStatus(409); // app has not failed
        $this->api($token)->withHeader('Idempotency-Key', 'x3')->postJson("/api/v1/enterprise/deployments/{$id}/steps/worker/retry")->assertStatus(202)
            ->assertJsonPath('data.status', 'provisioning');
        $this->api($token)->withHeader('Idempotency-Key', 'x4')->postJson("/api/v1/enterprise/deployments/{$id}/steps/placement/retry")->assertStatus(422);
    }

    public function test_an_empty_image_list_from_a_busy_node_keeps_the_inventory(): void
    {
        [, , $server, $agent] = $this->setUpPanel();
        $img = ['id' => 'sha256:aa', 'tags' => ['worksuite-server:1'], 'size_mb' => 50, 'created' => '2026-01-01T00:00:00Z'];
        $running = [['docker_id' => 'c1', 'name' => 'web', 'image' => 'worksuite-server:1', 'state' => 'running', 'health' => 'none']];
        $this->heartbeat($agent, ['containers' => $running, 'images' => [$img]]);
        $this->assertSame(1, Image::allTenants()->where('server_id', $server->id)->count());
        $this->heartbeat($agent, ['containers' => $running, 'images' => []]);
        $this->assertSame(1, Image::allTenants()->where('server_id', $server->id)->count(), 'running containers imply images: [] means the list timed out');
        $this->heartbeat($agent, ['containers' => [], 'images' => []]);
        $this->assertSame(0, Image::allTenants()->where('server_id', $server->id)->count());
    }

    public function test_the_managed_zone_refuses_taken_names_and_the_domain_step_shows_the_publish(): void
    {
        $remote = [];
        $n = 1;
        \Illuminate\Support\Facades\Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$remote, &$n) {
            $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/client/v4'));
            $ok = fn ($r, $extra = []) => \Illuminate\Support\Facades\Http::response(['success' => true, 'errors' => [], 'result' => $r] + $extra);

            return match (true) {
                $path === '/user/tokens/verify' => $ok(['id' => 'abcdef1234567890', 'status' => 'active']),
                $path === '/zones' && $request->method() === 'GET' => $ok([]),
                $path === '/zones' => $ok(['id' => 'z1', 'status' => 'active', 'name_servers' => ['ada.ns.cloudflare.com']]),
                $path === '/zones/z1/dns_records' && $request->method() === 'GET' => $ok(array_values($remote), ['result_info' => ['total_pages' => 1]]),
                $path === '/zones/z1/dns_records' => $ok($remote[$id = 'r'.$n++] = ['id' => $id, 'ttl' => 1] + $request->data()),
                default => \Illuminate\Support\Facades\Http::response(['success' => false, 'errors' => [['message' => 'not found']]], 404),
            };
        });
        [, $token, , $agent, $ownerToken] = $this->setUpPanel();
        $provider = $this->api($ownerToken)->postJson('/api/v1/dns/providers', ['name' => 'CF', 'type' => 'cloudflare', 'token' => 'zone-token'])->assertCreated()->json('data');
        $this->api($ownerToken)->postJson('/api/v1/dns/zones', ['dns_provider_id' => $provider['id'], 'name' => 'worksuite.lk'])->assertCreated();
        $remote['rx'] = ['id' => 'rx', 'type' => 'A', 'name' => 'demo.worksuite.lk', 'content' => '203.0.113.7', 'ttl' => 1];
        $remote['ry'] = ['id' => 'ry', 'type' => 'CNAME', 'name' => 'eu.desk.worksuite.lk', 'content' => 'x.example', 'ttl' => 1];
        $zone = \App\Models\DnsZone::allTenants()->where('name', 'worksuite.lk')->first();
        app(\App\Services\DnsZoneService::class)->sync($zone);

        $this->api($token)->getJson('/api/v1/enterprise/slug-availability?slug=demo')->assertJsonPath('data.available', false);
        $this->api($token)->getJson('/api/v1/enterprise/slug-availability?slug=desk')->assertJsonPath('data.available', false);
        $this->api($token)->getJson('/api/v1/enterprise/slug-availability?slug=acme')->assertJsonPath('data.available', true);

        config(['enterprise.require_loaded_image' => false]);
        $id = $this->api($token)->withHeader('Idempotency-Key', 'z1')->postJson('/api/v1/enterprise/deployments', [
            'external_id' => 'ent_z', 'customer_name' => 'Acme', 'slug' => 'acme', 'version' => '3.2.0-3011', 'size' => 'small',
        ])->assertStatus(202)->json('data.deployment_id');
        $this->ack($agent, 'container.create');
        $this->sync();
        $step = collect(EnterpriseDeployment::find($id)->steps)->firstWhere('name', 'domain');
        $this->assertStringContainsString('DNS: A acme.worksuite.lk created', (string) $step['message']);
        $this->assertSame('created', $step['dns'][0]['status']);
        $this->assertContains('acme.worksuite.lk', array_column($remote, 'name'));
    }

    public function test_view_carries_health_urls_and_cloud_admin_shapes(): void
    {
        [$id, $token] = $this->ready();
        $v = $this->api($token)->getJson("/api/v1/enterprise/deployments/{$id}")->assertOk();
        $this->assertContains($v->json('data.health'), ['ok', 'degraded', 'down']);
        $this->assertContains($v->json('data.domain.dns_status'), ['ok', 'pending', 'failed']);
        $this->assertContains($v->json('data.domain.https_status'), ['ok', 'pending', 'failed']);
        $this->assertStringEndsWith('/admin/projects/'.$v->json('data.project_id'), $v->json('data.urls.panel'));
        $this->assertSame('https://acme.worksuite.lk/login', $v->json('data.urls.login'));
        $v->assertJsonPath('data.domains.0.domain', 'acme.worksuite.lk')->assertJsonPath('data.domains.0.kind', 'platform')->assertJsonPath('data.domains.0.primary', true)
            ->assertJsonStructure(['data' => ['limits' => ['size', 'cpus', 'ram_mb', 'disk_gb'], 'domains' => [['domain', 'status', 'primary', 'tls_status', 'records', 'checked_at', 'error']]]]);

        $m = $this->api($token)->getJson("/api/v1/enterprise/deployments/{$id}/metrics?range=1h")->assertOk();
        $this->assertIsArray($m->json('data.series'));

        // Restart of one container only.
        $this->api($token)->withHeader('Idempotency-Key', 'rc')->postJson("/api/v1/enterprise/deployments/{$id}/actions/restart", ['container' => 'worker'])->assertStatus(202)->assertJsonCount(1, 'data.job_ids');
        $this->api($token)->withHeader('Idempotency-Key', 'rc2')->postJson("/api/v1/enterprise/deployments/{$id}/actions/stop", ['container' => 'worker'])->assertStatus(422);

        // Real not-founds carry their own codes.
        $this->api($token)->getJson('/api/v1/enterprise/deployments/nope')->assertNotFound()->assertJsonPath('error.code', 'deployment_not_found');
        $this->api($token)->withHeader('Idempotency-Key', 'nf')->postJson("/api/v1/enterprise/deployments/{$id}/domains/x.test/verify")->assertNotFound()->assertJsonPath('error.code', 'domain_not_found');
    }
}
