<?php

namespace Tests\Feature;

use App\Contracts\CertificateIssuer;
use App\Contracts\DnsResolver;
use App\Models\Application;
use App\Models\Domain;
use App\Services\TraefikLabels;
use App\Support\Tenant;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/** DOM-002 / UDOM-004 with CERT_DRIVER=traefik: file-provider routers + ACME status from acme.json. */
class TraefikCertificateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.drivers.certificates' => 'traefik']);
        $this->app->forgetInstance(CertificateIssuer::class);
    }

    public function test_verified_domain_pushes_router_and_acme_status_activates_https(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['ip' => '198.51.100.7']);
        [$secret] = $this->enrollAgent($server, $token);
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'site', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'size' => 'small', 'server_id' => $server->id, 'port' => 80]));
        $labels = TraefikLabels::for($app);
        $domain = $this->api($token)->postJson('/api/v1/app/domains', ['domain' => 'shop.example.com', 'app_id' => $app->id, 'redirect_www' => true])->assertCreated()->json('data');
        $this->assertSame(['traefik.enable' => 'true', 'traefik.http.services.app-site.loadbalancer.server.port' => '80'], TraefikLabels::for(Application::allTenants()->find($app->id)), 'routers come from the file provider');
        unset($labels);

        $dns = app(DnsResolver::class);
        $dns->set('shop.example.com', 'A', ['198.51.100.7']);
        $dns->set('www.shop.example.com', 'CNAME', ['shop.example.com.']);
        $this->api($token)->postJson("/api/v1/app/domains/{$domain['id']}/verify")->assertOk()->assertJsonPath('data.https_status', 'issuing');

        $commands = collect($this->heartbeat($secret)['commands'])->keyBy('type');
        $config = $commands['proxy.config']['payload'];
        $this->assertSame('dmp-', $config['prune_prefix']);
        $yaml = Yaml::parse($config['files']['dmp-domains.yml']);
        $router = $yaml['http']['routers']['dmp-shop-example-com'];
        $this->assertSame('Host(`shop.example.com`) || Host(`www.shop.example.com`)', $router['rule']);
        $this->assertSame('le', $router['tls']['certResolver']);
        $this->assertSame([['main' => 'shop.example.com', 'sans' => ['www.shop.example.com']]], $router['tls']['domains']);
        $this->assertSame('app-site@docker', $router['service']);
        $this->assertSame(['websecure'], $router['entryPoints']);

        // proxy.certs: names + notAfter only.
        $this->commandResult($secret, $commands['proxy.certs']['id'], 'succeeded', ['output' => ['certificates' => [
            ['resolver' => 'le', 'domain' => 'shop.example.com', 'sans' => ['www.shop.example.com'], 'not_after' => now()->addDays(80)->startOfSecond()->toIso8601ZuluString(), 'not_before' => now()->subDays(10)->toIso8601ZuluString(), 'issuer' => 'R11'],
        ]]])->assertOk();
        $d = $this->api($token)->getJson("/api/v1/app/domains/{$domain['id']}")->assertOk()->json('data');
        $this->assertSame('active', $d['https_status']);
        $this->assertSame('R11', $d['certificate']['issuer']);
        $this->assertSame('le', $d['certificate']['resolver']);
        $this->assertContains($d['certificate']['days_left'], [79, 80]);

        // Unchanged config is not pushed again; a delete re-pushes without the router.
        app(Tenant::class)->set(null);
        $this->artisan('proxy:push')->expectsOutput('proxy.config commands queued: 0')->assertSuccessful();
        $this->api($token)->deleteJson("/api/v1/app/domains/{$domain['id']}")->assertSuccessful();
        $push = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'proxy.config');
        $this->assertSame([], (array) (Yaml::parse($push['payload']['files']['dmp-domains.yml'])['http']['routers'] ?? []));
    }

    public function test_wildcard_uses_dns01_resolver_with_token_from_secrets(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['ip' => '198.51.100.9', 'tags' => ['ingress']]);
        [$secret] = $this->enrollAgent($server, $token);
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'tenant-app', 'kind' => 'platform', 'source_type' => 'image', 'image' => 'nginx', 'server_id' => $server->id, 'port' => 8080]));
        $domain = $this->api($token)->postJson('/api/v1/domains', ['name' => '*.example.org', 'application_id' => $app->id, 'dns_provider' => 'cloudflare', 'dns_token' => 'cf-token-123'])->assertCreated();
        $this->assertStringNotContainsString('cf-token-123', $domain->getContent());
        $domain->assertJsonPath('data.challenge', 'dns-01')->assertJsonPath('data.has_dns_token', true);
        $id = $domain->json('data.id');

        $dns = app(DnsResolver::class);
        foreach ($this->api($token)->getJson("/api/v1/domains/{$id}")->json('data.records') as $r) {
            $dns->set(ltrim($r['name'], '*.'), $r['type'], [$r['value']]);
        }
        $this->api($token)->postJson("/api/v1/domains/{$id}/verify")->assertOk()->assertJsonPath('data.https_status', 'issuing');
        $config = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'proxy.config')['payload'];
        $this->assertSame(['CF_DNS_API_TOKEN' => 'cf-token-123'], $config['secrets']);
        $router = Yaml::parse($config['files']['dmp-domains.yml'])['http']['routers']['dmp-wildcard-example-org'];
        $this->assertSame('le-dns-cloudflare', $router['tls']['certResolver']);
        $this->assertSame([['main' => 'example.org', 'sans' => ['*.example.org']]], $router['tls']['domains']);
        $this->assertStringContainsString('HostRegexp(', $router['rule']);
        $this->assertSame('dns-01', Domain::allTenants()->find($id)->challenge);
    }
}
