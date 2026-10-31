<?php

namespace Tests\Feature;

use App\Contracts\CertificateIssuer;
use App\Models\Domain;
use App\Models\FirewallRule;
use App\Services\ProxyAdoptionService;
use App\Support\Tenant;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/** Live sites on hand-written Traefik files become managed domains, so the firewall reaches them. */
class ProxyAdoptionTest extends TestCase
{
    private const SITE = <<<'YAML'
http:
  routers:
    giantforests-http:
      rule: Host(`giantforests.lk`) || Host(`www.giantforests.lk`)
      entryPoints: [web]
      middlewares: [to-https]
      service: giantforests
    giantforests:
      rule: Host(`giantforests.lk`) || Host(`www.giantforests.lk`)
      entryPoints: [websecure]
      service: giantforests
      tls: { certResolver: le }
  middlewares:
    to-https: { redirectScheme: { scheme: https } }
  services:
    giantforests:
      loadBalancer:
        servers: [{ url: "http://giantforests:80" }]
YAML;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.drivers.certificates' => 'traefik']);
        $this->app->forgetInstance(CertificateIssuer::class);
    }

    private function scanned(): array
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['ip' => '198.51.100.9']);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $this->api($token)->postJson("/api/v1/servers/{$server->id}/proxy/scan")->assertStatus(202);
        $scan = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'proxy.scan');
        $this->commandResult($secret, $scan['id'], 'succeeded', ['output' => ['files' => [
            ['name' => 'giantforests.yml', 'content' => self::SITE],
            ['name' => 'security.yml', 'content' => "http:\n  middlewares:\n    sec: { headers: { frameDeny: true } }\n"],
            ['name' => 'docker.yml', 'content' => "http:\n  routers:\n    x: { rule: \"Host(`x.example.com`)\", service: \"x@docker\" }\n"],
        ], 'adopted' => []]])->assertOk();

        return [$org, $token, $server, $secret];
    }

    public function test_files_are_parsed_into_adoptable_sites(): void
    {
        [, $token, $server] = $this->scanned();
        $files = collect($this->api($token)->getJson("/api/v1/servers/{$server->id}/proxy/adoptable")->assertOk()->json('data.files'))->keyBy('name');
        $this->assertSame([true, ['giantforests.lk', 'www.giantforests.lk'], 'http://giantforests:80'], [$files['giantforests.yml']['adoptable'], $files['giantforests.yml']['hosts'], $files['giantforests.yml']['upstream']]);
        $this->assertSame('it has no HTTP routers', $files['security.yml']['reason']);
        $this->assertSame('its service is not a single URL in this file', $files['docker.yml']['reason']);
        $this->api($token)->postJson("/api/v1/servers/{$server->id}/proxy/adopt", ['file' => 'docker.yml'])->assertStatus(409);
    }

    public function test_adopting_routes_the_site_through_the_panel_with_its_firewall_then_retires_the_file(): void
    {
        [$org, $token, $server, $secret] = $this->scanned();
        app(Tenant::class)->as($org->id, fn () => FirewallRule::create(['name' => 'Flood', 'paths' => ['*'], 'limit' => 3000, 'window_seconds' => 60, 'action' => 'block']));
        Http::fake(['https://giantforests.lk/*' => Http::response('ok'), 'https://www.giantforests.lk/*' => Http::response('', 301)]);

        $this->api($token)->postJson("/api/v1/servers/{$server->id}/proxy/adopt", ['file' => 'giantforests.yml'])->assertStatus(202);
        $commands = collect($this->heartbeat($secret)['commands']);
        $this->assertSame(['proxy.config', 'proxy.retire'], $commands->pluck('type')->intersect(['proxy.config', 'proxy.retire'])->values()->all(), 'the managed route goes out before the file is retired');

        $yaml = Yaml::parse($commands->firstWhere('type', 'proxy.config')['payload']['files']['dmp-domains.yml']);
        $router = $yaml['http']['routers']['dmp-giantforests-lk'];
        $this->assertSame('dmp-giantforests-lk-upstream', $router['service']);
        $this->assertSame('http://giantforests:80', $yaml['http']['services']['dmp-giantforests-lk-upstream']['loadBalancer']['servers'][0]['url']);
        $this->assertNotEmpty($router['middlewares'] ?? [], 'the firewall rule applies to the adopted site');
        $this->assertArrayHasKey('dmp-www-giantforests-lk', $yaml['http']['routers']);

        $retire = $commands->firstWhere('type', 'proxy.retire');
        $this->assertSame('giantforests.yml', $retire['payload']['file']);
        $this->commandResult($secret, $retire['id'], 'succeeded', ['output' => ['retired' => true]])->assertOk();
        $this->assertSame(['active', 'active'], Domain::allTenants()->orderBy('name')->get()->map(fn ($d) => $d->options['upstream']['state'])->all());

        // A domain that is already adopted cannot be adopted twice.
        $this->api($token)->postJson("/api/v1/servers/{$server->id}/proxy/adopt", ['file' => 'giantforests.yml'])->assertStatus(409);
    }

    public function test_a_site_that_does_not_answer_gets_its_file_back(): void
    {
        [, $token, $server, $secret] = $this->scanned();
        Http::fake(['*' => Http::response('bad gateway', 502)]);
        $this->api($token)->postJson("/api/v1/servers/{$server->id}/proxy/adopt", ['file' => 'giantforests.yml'])->assertStatus(202);
        $retire = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'proxy.retire');
        $this->commandResult($secret, $retire['id'], 'succeeded', ['output' => ['retired' => true]])->assertOk();

        $commands = collect($this->heartbeat($secret)['commands']);
        $restore = $commands->firstWhere('type', 'proxy.restore');
        $this->assertSame('giantforests.yml', $restore['payload']['file']);
        $yaml = Yaml::parse($commands->firstWhere('type', 'proxy.config')['payload']['files']['dmp-domains.yml'] ?? 'x: 1') ?? [];
        $this->assertArrayNotHasKey('dmp-giantforests-lk', $yaml['http']['routers'] ?? [], 'the managed route is withdrawn');
        $this->commandResult($secret, $restore['id'], 'succeeded', ['output' => ['restored' => true]])->assertOk();
        $this->assertSame('restored', Domain::allTenants()->first()->options['upstream']['state']);
    }

    public function test_parse_handles_multiple_upstreams_and_bad_yaml(): void
    {
        $two = "http:\n  routers:\n    a: { rule: \"Host(`a.example.com`)\", service: a }\n    b: { rule: \"Host(`b.example.com`)\", service: b }\n  services:\n    a: { loadBalancer: { servers: [{ url: 'http://a:80' }] } }\n    b: { loadBalancer: { servers: [{ url: 'http://b:80' }] } }\n";
        $out = ProxyAdoptionService::parse([['name' => 'two.yml', 'content' => $two], ['name' => 'bad.yml', 'content' => "http: [\n"]]);
        $this->assertSame(['its routers send traffic to more than one place', 'it is not valid YAML'], array_column($out, 'reason'));
    }
}
