<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\BackupJob;
use App\Models\Domain;
use App\Models\EnterpriseDeployment;
use App\Models\EnvVar;
use App\Models\FirewallIpList;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Services\EnterpriseProvisioningService;
use App\Support\ProjectGrant;
use App\Support\Tenant;
use Tests\TestCase;

/** Enterprise part F: the deployment page's CloudPanel tabs, delegated to the project features. */
class EnterprisePartFTest extends TestCase
{
    private function sync(): void
    {
        app(Tenant::class)->set(null);
        app(EnterpriseProvisioningService::class)->sync();
    }

    private function ack(string $agent, string $type): void
    {
        foreach (array_filter($this->heartbeat($agent)['commands'], fn ($c) => $c['type'] === $type) as $c) {
            $this->commandResult($agent, $c['id'], 'succeeded', ['output' => ['container_id' => 'c-'.$c['id']]])->assertOk();
        }
    }

    /** A deployment awaiting setup: [id, service token, org, owner token]. */
    private function ready(string $external = 'ent_f', string $slug = 'acme'): array
    {
        [, $org, $ownerToken] = $this->org();
        $server = $this->server($org, ['ram_mb' => 65536, 'tags' => ['enterprise']]);
        [$agent] = $this->enrollAgent($server, $ownerToken);
        [$svc] = $this->member($org, 'enterprise_service');
        $token = $this->tokenFor($svc, ['enterprise.deploy']);
        config(['enterprise.require_loaded_image' => false]);
        $id = $this->api($token)->withHeader('Idempotency-Key', 'c-'.$external)->postJson('/api/v1/enterprise/deployments', [
            'external_id' => $external, 'customer_name' => 'Acme', 'slug' => $slug, 'version' => '3.2.0-3011', 'size' => 'small',
        ])->assertStatus(202)->json('data.deployment_id');
        $this->ack($agent, 'container.create');
        $this->sync();
        $this->ack($agent, 'container.create');
        $this->passHealthChecks($agent);
        $this->sync();
        $this->assertSame('awaiting_setup', EnterpriseDeployment::find($id)->status);

        return [$id, $token, $org, $ownerToken];
    }

    public function test_a_custom_domain_on_an_edge_served_deployment_lists_the_edges_a_records_not_a_cname(): void
    {
        config(['platform.ingress.hostname' => 'ingress.example.test']);
        [$id, $token, $org] = $this->ready('ent_edge', 'edgeco');
        $this->server($org, ['name' => 'frontdoor-01', 'ip' => '51.254.220.203', 'tags' => ['ingress', 'enterprise-edge']]);
        $this->server($org, ['name' => 'frontdoor-02', 'ip' => '51.254.213.183', 'tags' => ['ingress', 'enterprise-edge']]);
        $base = "/api/v1/enterprise/deployments/{$id}";

        $custom = $this->api($token)->withHeader('Idempotency-Key', 'e1')->postJson("{$base}/dns/domains", ['name' => 'onesource.edgeco.test'])->assertStatus(201)->json('data.id');
        $routing = collect(app(\App\Services\DomainService::class)->projectRecords(Domain::allTenants()->find($custom)))->where('purpose', 'routing')->values();

        $this->assertSame(['A'], $routing->pluck('type')->unique()->values()->all(), 'no CNAME to the cluster ingress');
        $this->assertEqualsCanonicalizing(['51.254.220.203', '51.254.213.183'], $routing->pluck('value')->all());
        $dns = $this->api($token)->getJson("{$base}/dns")->assertOk();
        $this->assertNull($dns->json('data.ingress.hostname'));
        $this->assertEqualsCanonicalizing(['51.254.220.203', '51.254.213.183'], $dns->json('data.ingress.ips'));
    }

    public function test_every_record_of_an_edge_custom_domain_shows_in_the_dns_lists_with_its_status(): void
    {
        // ACME-DLG: the platform zone the certificate challenge is delegated to.
        [, $platformOrg] = $this->org();
        $cf = \App\Models\DnsProviderAccount::allTenants()->create(['organization_id' => $platformOrg->id, 'name' => 'CF', 'type' => 'cloudflare', 'credentials' => ['token' => 't']]);
        \App\Models\DnsZone::allTenants()->create(['organization_id' => $platformOrg->id, 'dns_provider_id' => $cf->id, 'name' => 'worksuite.lk', 'remote_id' => 'z1', 'status' => 'active']);
        config(['platform.acme_delegation_zone' => 'worksuite.lk']);
        [$id, $token, $org] = $this->ready('ent_dlg', 'dlgco');
        $edges = ['51.254.220.203', '51.254.213.183'];
        $this->server($org, ['name' => 'frontdoor-01', 'ip' => $edges[0], 'tags' => ['ingress', 'enterprise-edge']]);
        $this->server($org, ['name' => 'frontdoor-02', 'ip' => $edges[1], 'tags' => ['ingress', 'enterprise-edge']]);
        $base = "/api/v1/enterprise/deployments/{$id}";
        $custom = $this->api($token)->withHeader('Idempotency-Key', 'd1')->postJson("{$base}/dns/domains", ['name' => 'onesource.dlgco.test'])->assertStatus(201)->json('data.id');
        $d = Domain::allTenants()->find($custom);
        $target = \App\Services\AcmeDelegation::target($d);
        $cname = fn (array $records) => collect($records)->first(fn ($r) => $r['type'] === 'CNAME' && $r['name'] === '_acme-challenge.onesource.dlgco.test');

        // Connected domains / DNS records (the overview the enterprise tab passes through).
        $row = collect($this->api($token)->getJson("{$base}/dns")->assertOk()->json('data.domains'))->firstWhere('id', $custom);
        $this->assertSame(['value' => $target, 'purpose' => 'certificate', 'status' => 'unchecked'], array_intersect_key($cname($row['records']), array_flip(['value', 'purpose', 'status'])));
        $this->assertEqualsCanonicalizing(['routing', 'routing', 'ownership', 'certificate'], collect($row['records'])->pluck('purpose')->all());

        // After a validation: A and TXT verified, the CNAME missing until the customer adds it.
        $dns = app(\App\Contracts\DnsResolver::class);
        $dns->set('onesource.dlgco.test', 'A', $edges);
        $dns->set('_livezen-verify.onesource.dlgco.test', 'TXT', ['livezen-verify='.$d->ownership_token]);
        $v = $this->api($token)->withHeader('Idempotency-Key', 'd2')->postJson("{$base}/dns/domains/{$custom}/validate")->assertOk();
        $this->assertSame('fail', $cname($v->json('data.records'))['status']);
        $this->assertSame('fail', collect($v->json('data.checks'))->firstWhere('key', 'acme_delegation')['status']);
        $show = $this->api($token)->getJson("{$base}/dns/domains/{$custom}")->assertOk()->json('data.records');
        $this->assertSame('missing', $cname($show)['status']);
        $this->assertSame(['verified'], collect($show)->where('purpose', '!=', 'certificate')->pluck('status')->unique()->values()->all());

        $dns->set('_acme-challenge.onesource.dlgco.test', 'CNAME', [$target.'.']);
        $this->api($token)->withHeader('Idempotency-Key', 'd3')->postJson("{$base}/dns/domains/{$custom}/validate")->assertOk();
        $this->assertSame('verified', $cname($this->api($token)->getJson("{$base}/dns/domains/{$custom}")->json('data.records'))['status']);

        // Cloud Admin's deployment view (domains[].records) carries the same record, purpose and status.
        $view = collect($this->api($token)->getJson($base)->assertOk()->json('data.domains'))->firstWhere('domain', 'onesource.dlgco.test');
        $this->assertSame(['type' => 'CNAME', 'name' => '_acme-challenge.onesource.dlgco.test', 'value' => $target, 'ttl' => 300, 'purpose' => 'certificate', 'status' => 'verified'], $cname($view['records']));
    }

    public function test_firewall_is_the_project_firewall_and_the_grant_ends_with_the_request(): void
    {
        [$id, $token] = $this->ready();
        $dep = EnterpriseDeployment::find($id);
        $base = "/api/v1/enterprise/deployments/{$id}";

        $this->api($token)->getJson("{$base}/firewall")->assertOk()->assertJsonPath('data.can_manage', true)
            ->assertJsonStructure(['data' => ['allow', 'block', 'rate_limits', 'apps', 'inherited']]);
        $entry = $this->api($token)->withHeader('Idempotency-Key', 'f1')->postJson("{$base}/firewall/ips", ['list' => 'block', 'cidr' => '203.0.113.7', 'note' => 'abuse'])
            ->assertStatus(201)->assertJsonPath('data.cidr', '203.0.113.7/32')->json('data.id');
        $this->assertSame($dep->project_id, FirewallIpList::allTenants()->find($entry)->project_id);
        $this->api($token)->withHeader('Idempotency-Key', 'f2')->postJson("{$base}/firewall/rate-limits", ['name' => 'login', 'paths' => ['/login'], 'limit' => 20])->assertStatus(201);
        $this->api($token)->withHeader('Idempotency-Key', 'f3')->postJson("{$base}/firewall/lock", ['reason' => 'maintenance'])->assertOk()
            ->assertJsonPath('data.apps.0.locked', true);
        $this->assertFalse(Application::allTenants()->find($dep->app_application_id)->public_access);
        $this->api($token)->withHeader('Idempotency-Key', 'f4')->postJson("{$base}/firewall/unlock")->assertOk();
        $this->api($token)->deleteJson("{$base}/firewall/ips/{$entry}")->assertNoContent();
        $this->assertTrue(AuditLog::query()->withoutGlobalScopes()->where('action', 'project.firewall.ip.add')->where('resource_id', $dep->project_id)->exists());

        // The grant is per call: nothing is left on the project afterwards.
        $this->assertNull(app(ProjectGrant::class)->role($dep->project_id));
        // And it is the deploy ability that opens these endpoints, not membership.
        [, $viewerToken] = $this->member(\App\Models\Organization::find($dep->organization_id), 'viewer');
        $this->api($viewerToken)->getJson("{$base}/firewall")->assertForbidden();
    }

    public function test_another_organisations_deployment_is_not_found(): void
    {
        [$id] = $this->ready('ent_a', 'acme');
        [, $other] = $this->ready('ent_b', 'beta');
        $this->api($other)->getJson("/api/v1/enterprise/deployments/{$id}/firewall")->assertNotFound()->assertJsonPath('error.code', 'deployment_not_found');
        $this->api($other)->getJson("/api/v1/enterprise/deployments/{$id}/settings")->assertNotFound();
    }

    public function test_dns_tab_uses_project_dns_and_keeps_the_platform_address(): void
    {
        [$id, $token] = $this->ready();
        $base = "/api/v1/enterprise/deployments/{$id}";
        $dns = $this->api($token)->getJson("{$base}/dns")->assertOk()->assertJsonPath('data.is_owner', true);
        $this->assertContains('add', $dns->json('data.permissions'));
        $platform = Domain::allTenants()->where('name', 'acme.worksuite.lk')->first();

        $custom = $this->api($token)->withHeader('Idempotency-Key', 'n1')->postJson("{$base}/dns/domains", ['name' => 'erp.acme.test'])->assertStatus(201)->json('data.id');
        $this->assertSame(EnterpriseDeployment::find($id)->app_application_id, Domain::allTenants()->find($custom)->application_id);
        $this->api($token)->getJson("{$base}/dns/domains/{$custom}")->assertOk()->assertJsonPath('data.name', 'erp.acme.test');
        $this->api($token)->withHeader('Idempotency-Key', 'n2')->postJson("{$base}/dns/domains/{$custom}/validate")->assertOk();
        $this->api($token)->getJson("{$base}/dns/history")->assertOk();
        $this->api($token)->deleteJson("{$base}/dns/domains/{$platform->id}")->assertStatus(409);
    }

    public function test_settings_mask_secrets_protect_reserved_keys_and_edit_the_rest(): void
    {
        [$id, $token] = $this->ready();
        $dep = EnterpriseDeployment::find($id);
        $base = "/api/v1/enterprise/deployments/{$id}";

        $s = $this->api($token)->getJson("{$base}/settings")->assertOk();
        $env = collect($s->json('data.env'));
        $this->assertNotEmpty($env);
        $this->assertTrue($env->where('secret', true)->every(fn ($v) => $v['value'] === null && $v['read_only']));
        $this->assertTrue($env->firstWhere('key', 'APP_URL')['read_only']);
        $s->assertJsonPath('data.app_url', 'https://acme.worksuite.lk')->assertJsonPath('data.restart_policy.editable', false)->assertJsonPath('data.version', '3.2.0-3011');

        $this->api($token)->patchJson("{$base}/settings", ['env' => [['key' => 'APP_URL', 'value' => 'https://x.test']]])->assertStatus(422);
        $this->api($token)->patchJson("{$base}/settings", ['env' => [['key' => 'DB_PASSWORD', 'value' => 'x']]])->assertStatus(422);

        $res = $this->api($token)->patchJson("{$base}/settings", ['env' => [['key' => 'MAIL_FROM', 'value' => 'it@acme.test']], 'timezone' => 'Asia/Colombo', 'maintenance' => true, 'maintenance_reason' => 'upgrade', 'maintenance_until' => now()->addHour()->toIso8601String()])
            ->assertOk()->assertJsonPath('data.timezone', 'Asia/Colombo')->assertJsonPath('data.maintenance.enabled', true)->assertJsonPath('data.maintenance.reason', 'upgrade')->assertJsonPath('data.needs_redeploy', true);
        $this->assertNotNull($res->json('data.maintenance.until'));
        $this->assertCount(4, $res->json('data.changed')); // two keys on web + worker
        $this->assertSame('it@acme.test', EnvVar::where('application_id', $dep->worker_application_id)->where('key', 'MAIL_FROM')->value('value'));
        $this->api($token)->patchJson("{$base}/settings", ['env' => [['key' => 'MAIL_FROM', 'delete' => true, 'application' => 'worker']], 'maintenance' => false])->assertOk()
            ->assertJsonPath('data.maintenance.enabled', false);
        $this->assertNull(EnvVar::where('application_id', $dep->worker_application_id)->where('key', 'MAIL_FROM')->first());
        $this->assertNotNull(EnvVar::where('application_id', $dep->app_application_id)->where('key', 'MAIL_FROM')->first());
        $this->api($token)->patchJson("{$base}/settings", ['timezone' => 'Mars/Base'])->assertStatus(422);
    }

    public function test_a_scheduled_window_locks_and_unlocks_the_app_and_feeds_the_notice(): void
    {
        [$id, $token] = $this->ready();
        $dep = EnterpriseDeployment::find($id);
        $base = "/api/v1/enterprise/deployments/{$id}";
        $web = $dep->app_application_id;
        $notice = '/api/v1/hooks/maintenance-notice/'.$web;

        // New deployments carry the notice URL for the web app only.
        $url = EnvVar::where('application_id', $web)->where('key', 'WS_MAINTENANCE_NOTICE_URL')->value('value');
        $this->assertStringEndsWith('/api/v1/hooks/maintenance-notice/'.$web, (string) $url);
        $this->assertNull(EnvVar::where('application_id', $dep->worker_application_id)->where('key', 'WS_MAINTENANCE_NOTICE_URL')->first());
        $this->getJson($notice)->assertOk()->assertJsonPath('data.scheduled', null)->assertJsonPath('data.active', false);

        $start = now()->addDay()->startOfMinute();
        $end = $start->copy()->addHours(2);
        $this->api($token)->patchJson("{$base}/settings", ['maintenance_schedule' => ['start' => $end->toIso8601String(), 'end' => $start->toIso8601String()]])->assertStatus(422);
        $res = $this->api($token)->patchJson("{$base}/settings", ['maintenance_schedule' => ['start' => $start->toIso8601String(), 'end' => $end->toIso8601String(), 'message' => 'Database upgrade']])
            ->assertOk()->assertJsonPath('data.maintenance.enabled', false)->assertJsonPath('data.maintenance.schedule.message', 'Database upgrade')
            ->assertJsonPath('data.needs_redeploy', false);
        $this->assertTrue($start->equalTo($res->json('data.maintenance.schedule.start')));
        $this->assertTrue(AuditLog::where('action', 'app.maintenance.schedule')->exists());
        $this->getJson($notice)->assertOk()->assertJsonPath('data.scheduled.message', 'Database upgrade')->assertJsonPath('data.active', false)->assertHeader('Cache-Control', 'max-age=60, public');

        // Nothing happens before the start; the scheduler is idempotent.
        $this->artisan('maintenance:apply-schedules')->assertSuccessful();
        $this->assertTrue(Application::allTenants()->find($web)->public_access !== false);

        $this->travelTo($start->copy()->addMinute());
        $this->artisan('maintenance:apply-schedules')->expectsOutputToContain('1 locked')->assertSuccessful();
        $this->artisan('maintenance:apply-schedules')->expectsOutputToContain('0 locked, 0 unlocked')->assertSuccessful();
        $app = Application::allTenants()->find($web);
        $this->assertFalse($app->public_access);
        $this->assertTrue($app->maintenance_auto_locked);
        $this->assertSame('Database upgrade', $app->lock_reason);
        $this->assertTrue($app->maintenance_until->equalTo($end));
        $this->getJson($notice)->assertOk()->assertJsonPath('data.active', true);
        $this->get('/api/v1/hooks/maintenance/'.$web)->assertStatus(503)->assertSee('Database upgrade');
        $this->assertTrue(AuditLog::where('action', 'app.maintenance.auto_lock')->exists());

        $this->travelTo($end->copy()->addMinute());
        $this->artisan('maintenance:apply-schedules')->expectsOutputToContain('1 unlocked')->assertSuccessful();
        $app = Application::allTenants()->find($web);
        $this->assertTrue($app->public_access);
        $this->assertNull($app->maintenance_scheduled_start);
        $this->assertFalse($app->maintenance_auto_locked);
        $this->assertTrue(AuditLog::where('action', 'app.maintenance.auto_unlock')->exists());
        $this->api($token)->getJson("{$base}/settings")->assertOk()->assertJsonPath('data.maintenance.schedule', null);
    }

    public function test_a_window_never_unlocks_an_app_locked_by_hand_and_can_be_cleared(): void
    {
        [$id, $token] = $this->ready();
        $dep = EnterpriseDeployment::find($id);
        $base = "/api/v1/enterprise/deployments/{$id}";
        $start = now()->addHour()->startOfMinute();
        $end = $start->copy()->addHour();
        $this->api($token)->patchJson("{$base}/settings", ['maintenance_schedule' => ['start' => $start->toIso8601String(), 'end' => $end->toIso8601String()]])->assertOk();
        $this->api($token)->patchJson("{$base}/settings", ['maintenance' => true, 'maintenance_reason' => 'by hand'])->assertOk();

        $this->travelTo($start->copy()->addMinute());
        $this->artisan('maintenance:apply-schedules')->expectsOutputToContain('0 locked')->assertSuccessful();
        $this->travelTo($end->copy()->addMinute());
        $this->artisan('maintenance:apply-schedules')->expectsOutputToContain('0 unlocked')->assertSuccessful();
        $app = Application::allTenants()->find($dep->app_application_id);
        $this->assertFalse($app->public_access, 'a lock the scheduler did not make is left alone');
        $this->assertSame('by hand', $app->lock_reason);
        $this->assertNull($app->maintenance_scheduled_start);

        // Clearing with null.
        $this->api($token)->patchJson("{$base}/settings", ['maintenance_schedule' => ['start' => now()->addDay()->toIso8601String(), 'end' => now()->addDays(2)->toIso8601String()]])->assertOk();
        $this->api($token)->patchJson("{$base}/settings", ['maintenance_schedule' => null])->assertOk()->assertJsonPath('data.maintenance.schedule', null);
        $this->assertTrue(AuditLog::where('action', 'app.maintenance.schedule.clear')->exists());
    }

    public function test_the_notice_allows_only_the_apps_own_domains_and_unknown_apps_get_nothing(): void
    {
        [$id] = $this->ready();
        $web = EnterpriseDeployment::find($id)->app_application_id;
        $d = Domain::allTenants()->where('application_id', $web)->firstOrFail();
        $d->forceFill(['dns_status' => 'verified'])->save();
        $own = $d->name;
        $this->getJson('/api/v1/hooks/maintenance-notice/'.$web, ['Origin' => 'https://'.$own])->assertOk()->assertHeader('Access-Control-Allow-Origin', 'https://'.$own);
        $other = $this->getJson('/api/v1/hooks/maintenance-notice/'.$web, ['Origin' => 'https://evil.example']);
        $this->assertNotSame('https://evil.example', $other->headers->get('Access-Control-Allow-Origin'));
        $this->getJson('/api/v1/hooks/maintenance-notice/00000000-0000-4000-8000-000000000000')->assertOk()->assertJsonPath('data', ['scheduled' => null, 'active' => false]);
    }

    public function test_backups_list_run_schedule_and_restore_need_confirmation(): void
    {
        [$id, $token] = $this->ready();
        $dep = EnterpriseDeployment::find($id);
        $base = "/api/v1/enterprise/deployments/{$id}";

        $this->api($token)->getJson("{$base}/backups")->assertOk()->assertJsonPath('data.schedule.enabled', false)->assertJsonPath('data.backups', []);
        $run = $this->api($token)->withHeader('Idempotency-Key', 'b1')->postJson("{$base}/backups")->assertStatus(202)->json('data.id');
        $this->assertSame($dep->project_id, BackupJob::allTenants()->find($run)->project_id);
        $this->api($token)->putJson("{$base}/backups/schedule", ['enabled' => true, 'frequency' => 'daily', 'retention_days' => 30])->assertOk()
            ->assertJsonPath('data.enabled', true)->assertJsonPath('data.retention_days', 30);
        $this->api($token)->getJson("{$base}/backups/{$run}")->assertOk()->assertJsonPath('data.id', $run);
        $this->api($token)->withHeader('Idempotency-Key', 'b2')->postJson("{$base}/backups/{$run}/restore", [])->assertStatus(422);
        $this->api($token)->getJson("{$base}/backups/".\Illuminate\Support\Str::uuid())->assertNotFound()->assertJsonPath('error.code', 'backup_not_found');
        $this->api($token)->withHeader('Idempotency-Key', 'b3')->postJson("{$base}/backups/{$run}/artifacts/".\Illuminate\Support\Str::uuid().'/download')->assertNotFound();
    }

    public function test_monitor_resources_and_security_views(): void
    {
        [$id, $token, $org] = $this->ready();
        $base = "/api/v1/enterprise/deployments/{$id}";

        $this->api($token)->getJson("{$base}/monitor?range=24h")->assertOk()->assertJsonPath('data.range', '24h')
            ->assertJsonStructure(['data' => ['health', 'containers', 'series', 'metrics', 'network', 'disk', 'uptime', 'alerts']]);
        $this->api($token)->getJson("{$base}/monitor?range=2y")->assertStatus(422);
        $r = $this->api($token)->getJson("{$base}/resources")->assertOk()
            ->assertJsonStructure(['data' => ['health', 'counts', 'applications', 'containers', 'databases', 'volumes', 'deployment_volumes', 'disk', 'nodes']]);
        $this->assertCount(2, $r->json('data.applications'));
        $this->assertCount(2, $r->json('data.databases'));

        $sec = $this->api($token)->getJson("{$base}/security")->assertOk()->assertJsonPath('data.access.locked', false)
            ->assertJsonPath('data.tls.0.domain', 'acme.worksuite.lk')
            ->assertJsonStructure(['data' => ['tls', 'access', 'blocked_ips', 'events', 'members', 'member_roles', 'audit']]);
        $this->assertNotEmpty($sec->json('data.audit'));

        [$person] = $this->member($org, 'member');
        $this->api($token)->putJson("{$base}/security/members/{$person->id}", ['role' => 'viewer'])->assertOk()->assertJsonPath('data.0.role', 'viewer');
        $this->assertSame('viewer', ProjectMember::where('project_id', EnterpriseDeployment::find($id)->project_id)->where('user_id', $person->id)->value('role'));
        $this->api($token)->deleteJson("{$base}/security/members/{$person->id}")->assertOk()->assertJsonPath('data', []);
    }

    public function test_scale_resizes_sets_quotas_and_refuses_worker_copies(): void
    {
        [$id, $token] = $this->ready();
        $dep = EnterpriseDeployment::find($id);
        $base = "/api/v1/enterprise/deployments/{$id}";

        $this->api($token)->getJson("{$base}/scale")->assertOk()->assertJsonPath('data.limits.size', 'small')
            ->assertJsonPath('data.replicas.worker.supported', false)->assertJsonPath('data.quotas.can_edit', true)
            ->assertJsonStructure(['data' => ['sizes', 'custom_limits', 'replicas' => ['web' => ['replicas', 'supported', 'max']], 'quotas' => ['quotas']]]);
        $this->api($token)->withHeader('Idempotency-Key', 's1')->postJson("{$base}/scale", [])->assertStatus(422);
        $this->api($token)->withHeader('Idempotency-Key', 's2')->postJson("{$base}/scale", ['worker_replicas' => 2])->assertStatus(501)->assertJsonPath('error.code', 'PANEL_UNSUPPORTED');
        $this->api($token)->withHeader('Idempotency-Key', 's3')->postJson("{$base}/scale", ['size' => 'medium'])->assertStatus(202)->assertJsonPath('data.limits.size', 'medium');
        $this->assertSame(2048, (int) Application::allTenants()->find($dep->app_application_id)->memory_limit_mb);
        $this->api($token)->withHeader('Idempotency-Key', 's3p')->postJson("{$base}/scale", ['package' => ['code' => 'ULTIMATE', 'label' => 'Ultimate', 'app_cpus' => 3, 'app_ram_mb' => 3072, 'worker_cpus' => 1, 'worker_ram_mb' => 1024, 'db_ram_mb' => 2048, 'redis_ram_mb' => 512, 'fpm_max_children' => 36, 'disk_gb' => 120]])
            ->assertStatus(202)->assertJsonPath('data.limits.size', 'package')->assertJsonPath('data.limits.package', 'ULTIMATE')->assertJsonPath('data.limits.ram_mb', 3072);

        $this->api($token)->putJson("{$base}/scale/quotas", ['apps' => 4])->assertOk()->assertJsonPath('data.can_edit', true);
        $this->assertNotNull(Project::allTenants()->find($dep->project_id)->quotas);
    }

    public function test_a_purged_deployment_has_no_project_tabs(): void
    {
        [$id, $token] = $this->ready();
        $dep = EnterpriseDeployment::find($id);
        Project::whereKey($dep->project_id)->delete();
        $this->api($token)->getJson("/api/v1/enterprise/deployments/{$id}/firewall")->assertStatus(409)->assertJsonPath('error.code', 'project_unavailable');
    }
}
