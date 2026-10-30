<?php

namespace Tests\Feature;

use App\Contracts\CertificateIssuer;
use App\Contracts\DnsResolver;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Threat;
use App\Models\Volume;
use App\Services\ServerSecurityService;
use App\Services\TraefikConfigService;
use App\Support\Tenant;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/** SRS 4.25: real-time scanning (AV-2), the WAF (AV-9) and outbound abuse limits (AV-12). */
class AntivirusServerSecurityTest extends TestCase
{
    private function setUpServer(): array
    {
        // Server security is platform staff's to change.
        [, $org, $token] = $this->org('super_admin');
        $server = $this->server($org, ['ip' => '198.51.100.9', 'tags' => ['ingress']]);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $volume = Volume::allTenants()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'name' => 'site', 'type' => 'volume', 'status' => 'active']);

        return [$org, $token, $secret, $server, $volume];
    }

    public function test_server_security_is_pushed_to_the_agent_and_staff_only(): void
    {
        [$org, $token, $secret, $server] = $this->setUpServer();
        config(['platform.antivirus.pool_addresses' => ['203.0.113.50']]);
        $this->api($token)->getJson("/api/v1/antivirus/servers/{$server->id}")->assertOk()->assertJsonPath('data.realtime', false)->assertJsonPath('data.egress.smtp_per_minute', 30);

        $this->api($token)->patchJson("/api/v1/antivirus/servers/{$server->id}", ['realtime' => true, 'egress' => ['enabled' => true, 'smtp_per_minute' => 20]])->assertOk()
            ->assertJsonPath('data.egress.block_mining', true);
        $commands = collect($this->heartbeat($secret)['commands'])->keyBy('type');
        $this->assertSame(['enabled' => true, 'block_mining' => true, 'smtp_per_minute' => 20, 'pool_addresses' => ['203.0.113.50']], $commands['egress.apply']['payload']);
        $this->assertSame(['enabled' => true, 'volumes' => ['site']], $commands['av.realtime']['payload']);
        $this->assertTrue(AuditLog::where('action', 'servers.security_updated')->exists());
        $this->api($token)->patchJson("/api/v1/antivirus/servers/{$server->id}", ['egress' => ['smtp_per_minute' => -1]])->assertStatus(422);

        $this->assertSame(1, app(ServerSecurityService::class)->reapplyAll());
        [, $dev] = $this->member($org, 'developer');
        $this->api($dev)->patchJson("/api/v1/antivirus/servers/{$server->id}", ['realtime' => false])->assertForbidden();
    }

    public function test_realtime_detections_become_threats_once(): void
    {
        [, $token, $secret, $server] = $this->setUpServer();
        $this->api($token)->patchJson("/api/v1/antivirus/servers/{$server->id}", ['realtime' => true])->assertOk();
        $this->heartbeat($secret);
        config(['platform.antivirus.auto_quarantine' => false]);
        $hit = ['volume' => 'site', 'path' => 'wp-content/uploads/x.php', 'signature' => 'Php.Webshell-1', 'sha256' => str_repeat('c', 64), 'size' => 10];

        for ($i = 0; $i < 2; $i++) {
            $this->assertSame(1, app(ServerSecurityService::class)->collectEvents());
            $c = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'av.events');
            $this->assertSame(['site'], $c['payload']['volumes']);
            $this->commandResult($secret, $c['id'], 'succeeded', ['output' => ['threats' => [$hit, ['volume' => 'unknown'] + $hit]]])->assertOk();
        }
        $this->assertSame(1, Threat::allTenants()->count(), 'the same file seen twice is one threat; an unknown volume is skipped');
        $this->assertSame('infected', Threat::allTenants()->first()->status);
    }

    public function test_the_waf_runs_after_the_bouncer_in_the_chosen_mode(): void
    {
        config(['platform.drivers.certificates' => 'traefik', 'platform.waf.enabled' => true]);
        $this->app->forgetInstance(CertificateIssuer::class);
        [$org, $token, $secret, $server] = $this->setUpServer();
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'shop', 'kind' => 'platform', 'source_type' => 'image', 'image' => 'nginx', 'server_id' => $server->id, 'port' => 80]));
        $id = $this->api($token)->postJson('/api/v1/domains', ['name' => 'shop.example.com', 'application_id' => $app->id, 'options' => ['waf' => ['mode' => 'block']]])->assertCreated()->json('data.id');
        $this->assertArrayNotHasKey('waf', Domain::allTenants()->find($id)->options ?? [], 'the WAF is set only through its own endpoint');
        Domain::allTenants()->whereKey($id)->update(['dns_status' => 'verified']);

        $this->api($token)->putJson("/api/v1/domains/{$id}/waf", ['mode' => 'detect', 'exclusions' => [942100, 942100, 920350]])->assertOk()
            ->assertJsonPath('data.exclusions', [942100, 920350])->assertJsonPath('data.installed', true);
        app(TraefikConfigService::class)->push(true);
        $config = collect($this->heartbeat($secret)['commands'])->where('type', 'proxy.config')->last()['payload'];
        $yaml = Yaml::parse($config['files']['dmp-domains.yml']);
        $this->assertSame(['dmp-shop-example-com-waf'], $yaml['http']['routers']['dmp-shop-example-com']['middlewares']);
        $directives = $yaml['http']['middlewares']['dmp-shop-example-com-waf']['plugin']['coraza']['directives'];
        $this->assertContains('SecRuleEngine DetectionOnly', $directives);
        $this->assertContains('SecRuleRemoveById 942100', $directives);
        $this->assertContains('Include @owasp_crs/*.conf', $directives);

        // Editing other options keeps the WAF setting.
        $this->api($token)->patchJson("/api/v1/domains/{$id}", ['options' => ['websocket' => true, 'waf' => ['mode' => 'off']]])->assertOk();
        $this->assertSame('detect', Domain::allTenants()->find($id)->options['waf']['mode']);
        $this->api($token)->putJson("/api/v1/domains/{$id}/waf", ['mode' => 'paranoid'])->assertStatus(422);

        $this->api($token)->putJson("/api/v1/domains/{$id}/waf", ['mode' => 'off'])->assertOk();
        app(TraefikConfigService::class)->push(true);
        $yaml = Yaml::parse(collect($this->heartbeat($secret)['commands'])->where('type', 'proxy.config')->last()['payload']['files']['dmp-domains.yml']);
        $this->assertArrayNotHasKey('dmp-shop-example-com-waf', $yaml['http']['middlewares'] ?? []);
    }
}
