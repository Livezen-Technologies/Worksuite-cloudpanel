<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Domain;
use App\Models\Server;
use App\Services\DomainService;
use App\Services\TraefikConfigService;
use Tests\TestCase;

/**
 * XNODE-2: enterprise edge ingress nodes (tagged `ingress` + `enterprise-edge`) serve the
 * Enterprise deployments' domains only; the existing ingress (panel-01) keeps every other
 * domain and does not change at all. Edges get DNS-01 certificates and both edge IPs in DNS.
 */
class EnterpriseEdgeIngressTest extends TestCase
{
    private function app(string $token, Server $server, string $name, bool $enterprise): Application
    {
        $id = $this->api($token)->postJson('/api/v1/applications', ['name' => $name, 'source_type' => 'image', 'image' => 'ghcr.io/acme/x:1', 'server_id' => $server->id, 'port' => 8080])->assertCreated()->json('data.id');
        $app = Application::allTenants()->findOrFail($id);
        if ($enterprise) {
            $app->update(['source' => array_merge((array) $app->source, ['enterprise_deployment_id' => 'dep-1'])]);
        }
        $this->api($token)->postJson('/api/v1/domains', ['name' => "{$name}.acme.test", 'application_id' => $id, 'target_port' => 8080])->assertCreated();
        Domain::allTenants()->where('name', "{$name}.acme.test")->update(['dns_status' => 'verified']);

        return $app->fresh();
    }

    private function yaml(Server $s): string
    {
        return app(TraefikConfigService::class)->yaml($s);
    }

    public function test_panel_01_is_unchanged_and_edges_serve_only_enterprise_domains(): void
    {
        config(['platform.drivers.certificates' => 'traefik']);
        [, $org, $token] = $this->org();
        $panel = $this->server($org, ['name' => 'panel-01', 'tags' => ['ingress']]);
        $node = $this->server($org, ['name' => 'node-05', 'ip' => '203.0.113.5']);
        $this->app($token, $panel, 'shop', false);
        $ent = $this->app($token, $node, 'ent-acme', true);

        // Without edges panel-01 serves both; the remote app at node-05's public IP (XNODE-3).
        $before = $this->yaml($panel);
        $this->assertStringContainsString('shop.acme.test', $before);
        $this->assertStringNotContainsString('http://app-ent-acme:', $before);
        $this->assertStringContainsString('http://203.0.113.5:', $before);

        $e7 = $this->server($org, ['name' => 'frontdoor-01', 'ip' => '51.254.220.203', 'tags' => ['ingress', 'enterprise-edge']]);
        $e8 = $this->server($org, ['name' => 'frontdoor-02', 'ip' => '51.254.213.183', 'tags' => ['ingress', 'enterprise-edge']]);
        $this->assertEqualsCanonicalizing([$panel->id, $e7->id, $e8->id], app(TraefikConfigService::class)->ingressServers()->pluck('id')->all());

        $after = $this->yaml($panel);
        $this->assertStringNotContainsString('ent-acme', $after, 'enterprise domains leave panel-01');
        foreach ([$e7, $e8] as $edge) {
            $y = $this->yaml($edge);
            $this->assertStringContainsString('ent-acme.acme.test', $y);
            $this->assertStringNotContainsString('shop.acme.test', $y);
            $this->assertStringContainsString('http://203.0.113.5:'.app(\App\Services\EdgeRoutingService::class)->port($ent->fresh()), $y, 'proxied to node-05 public IP');
        }
        $this->assertFalse((bool) $ent->fresh()->mesh_network, 'no mesh');
    }

    public function test_panel_01_render_is_byte_identical_when_edges_carry_only_enterprise_domains(): void
    {
        config(['platform.drivers.certificates' => 'traefik']);
        [, $org, $token] = $this->org();
        $panel = $this->server($org, ['name' => 'panel-01', 'tags' => ['ingress']]);
        $this->app($token, $panel, 'shop', false);
        $this->app($token, $panel, 'blog', false);
        $before = $this->yaml($panel);

        $this->server($org, ['name' => 'frontdoor-01', 'tags' => ['ingress', 'enterprise-edge']]);
        $this->assertSame($before, $this->yaml($panel));
    }

    public function test_enterprise_domains_name_both_edges_in_dns(): void
    {
        [, $org, $token] = $this->org();
        $panel = $this->server($org, ['name' => 'panel-01', 'ip' => '51.89.7.73', 'tags' => ['ingress']]);
        $node = $this->server($org, ['name' => 'node-05', 'ip' => '203.0.113.5']);
        $ent = $this->app($token, $node, 'ent-acme', true);
        $plain = $this->app($token, $node, 'plain', false);
        $svc = app(DomainService::class);
        $d = fn (string $n) => Domain::allTenants()->where('name', $n)->first();

        $this->assertSame(['203.0.113.5'], array_column($svc->expectedRecords($d('ent-acme.acme.test')), 'value'), 'no edges: unchanged');

        $this->server($org, ['name' => 'frontdoor-01', 'ip' => '51.254.220.203', 'tags' => ['ingress', 'enterprise-edge']]);
        $this->server($org, ['name' => 'frontdoor-02', 'ip' => '51.254.213.183', 'tags' => ['ingress', 'enterprise-edge']]);
        $a = collect($svc->expectedRecords($d('ent-acme.acme.test')))->where('type', 'A')->pluck('value')->all();
        $this->assertSame(['51.254.220.203', '51.254.213.183'], $a);
        $this->assertSame(['203.0.113.5'], collect($svc->expectedRecords($d('plain.acme.test')))->where('type', 'A')->pluck('value')->all());
    }

    public function test_an_enterprise_domain_with_dns01_uses_the_dns_resolver(): void
    {
        [, $org, $token] = $this->org();
        $node = $this->server($org, ['name' => 'node-05']);
        $this->app($token, $node, 'ent-acme', true);
        $domain = Domain::allTenants()->where('name', 'ent-acme.acme.test')->first();
        $svc = app(TraefikConfigService::class);
        $this->assertSame('le', $svc->resolverFor($domain));
        // What provisioning creates on an edge setup (challenge dns-01, provider from config).
        $domain->update(['challenge' => 'dns-01', 'dns_provider' => config('enterprise.edge_dns_provider')]);
        $this->assertSame('le-dns-cloudflare', $svc->resolverFor($domain->fresh()));
    }
}
