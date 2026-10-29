<?php

namespace Tests\Feature;

use App\Contracts\CertificateIssuer;
use App\Contracts\DnsResolver;
use App\Models\Application;
use App\Models\Domain;
use App\Models\FirewallRule;
use App\Support\Tenant;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/** FW-001: firewall rules render into the Traefik dynamic configuration. */
class FirewallRenderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.drivers.certificates' => 'traefik']);
        $this->app->forgetInstance(CertificateIssuer::class);
    }

    /** @return array{0: object, 1: string, 2: Domain} org, agent secret, verified domain */
    private function verifiedDomain(): array
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['ip' => '198.51.100.9']);
        [$secret] = $this->enrollAgent($server, $token);
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'shop', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'size' => 'small', 'server_id' => $server->id, 'port' => 80]));
        $created = $this->api($token)->postJson('/api/v1/app/domains', ['domain' => 'shop.example.com', 'app_id' => $app->id])->assertCreated()->json('data');
        app(DnsResolver::class)->set('shop.example.com', 'A', ['198.51.100.9']);
        $this->api($token)->postJson("/api/v1/app/domains/{$created['id']}/verify")->assertOk();

        return [$org, $secret, Domain::allTenants()->find($created['id'])];
    }

    /** Re-pushes before reading, the way a rule change does in the controller. */
    private function yamlFor(string $secret, ?string $org = null): array
    {
        if ($org) {
            app(Tenant::class)->as($org, fn () => app(\App\Services\TraefikConfigService::class)->push(true));
        }
        $commands = collect($this->heartbeat($secret)['commands'])->keyBy('type');

        return Yaml::parse($commands['proxy.config']['payload']['files']['dmp-domains.yml']);
    }

    public function test_no_rules_leaves_the_configuration_untouched(): void
    {
        [, $secret] = $this->verifiedDomain();
        $yaml = $this->yamlFor($secret);
        $this->assertArrayNotHasKey('middlewares', $yaml['http'], 'no rules must add no middlewares');
        $this->assertSame(['dmp-shop-example-com'], array_keys($yaml['http']['routers']));
    }

    public function test_site_wide_rule_attaches_a_rate_limit_to_the_domain_router(): void
    {
        [$org, $secret, $domain] = $this->verifiedDomain();
        app(Tenant::class)->as($org->id, fn () => FirewallRule::create([
            'name' => 'Global flood guard', 'paths' => ['*'], 'limit' => 3000,
            'window_seconds' => 60, 'counted_by' => 'ip', 'action' => 'block',
        ]));
        $yaml = $this->yamlFor($secret, $org->id);
        $router = $yaml['http']['routers']['dmp-shop-example-com'];
        $this->assertCount(1, $router['middlewares']);
        $limit = $yaml['http']['middlewares'][$router['middlewares'][0]]['rateLimit'];
        $this->assertSame(3000, $limit['average']);
        $this->assertSame('60s', $limit['period']);
        $this->assertSame(300, $limit['burst']);
        $this->assertArrayHasKey('ipStrategy', $limit['sourceCriterion']);
        // One router still: a site-wide rule needs no extra router.
        $this->assertSame(['dmp-shop-example-com'], array_keys($yaml['http']['routers']));
        unset($domain);
    }

    public function test_path_scoped_rule_gets_its_own_higher_priority_router(): void
    {
        [$org, $secret] = $this->verifiedDomain();
        app(Tenant::class)->as($org->id, fn () => FirewallRule::create([
            'name' => 'Login brute force', 'paths' => ['/login', '/wp-login.php'],
            'methods' => ['POST'], 'limit' => 5, 'window_seconds' => 60,
            'counted_by' => 'ip', 'action' => 'block', 'priority' => 10,
        ]));
        $yaml = $this->yamlFor($secret, $org->id);
        $routers = $yaml['http']['routers'];
        $this->assertCount(2, $routers, 'a path-scoped rule adds a router');
        $extra = collect($routers)->first(fn ($r, $k) => str_contains($k, '-fw-'));
        $this->assertStringContainsString('PathPrefix(`/login`)', $extra['rule']);
        $this->assertStringContainsString('PathPrefix(`/wp-login.php`)', $extra['rule']);
        $this->assertStringContainsString('Method(`POST`)', $extra['rule']);
        $this->assertStringContainsString('Host(`shop.example.com`)', $extra['rule']);
        $this->assertGreaterThan(1000, $extra['priority'], 'it must outrank the domain router');
        $this->assertSame($routers['dmp-shop-example-com']['service'], $extra['service']);
        $this->assertArrayNotHasKey('middlewares', $routers['dmp-shop-example-com'], 'the site router is unaffected');
        $this->assertSame(5, $yaml['http']['middlewares'][$extra['middlewares'][0]]['rateLimit']['average']);
    }

    public function test_token_counted_rule_uses_the_authorization_header(): void
    {
        [$org, $secret] = $this->verifiedDomain();
        app(Tenant::class)->as($org->id, fn () => FirewallRule::create([
            'name' => 'API burst', 'paths' => ['*'], 'limit' => 600, 'window_seconds' => 60,
            'counted_by' => 'token_else_ip', 'action' => 'throttle',
        ]));
        $yaml = $this->yamlFor($secret, $org->id);
        $limit = collect($yaml['http']['middlewares'])->first()['rateLimit'];
        $this->assertSame(['requestHeaderName' => 'Authorization'], $limit['sourceCriterion']);
    }

    public function test_challenge_actions_are_reported_unsupported_and_render_nothing(): void
    {
        [$org, $secret] = $this->verifiedDomain();
        app(Tenant::class)->as($org->id, fn () => FirewallRule::create([
            'name' => 'Checkout abuse', 'paths' => ['/checkout'], 'limit' => 20,
            'window_seconds' => 600, 'counted_by' => 'ip', 'action' => 'recaptcha',
        ]));
        $yaml = $this->yamlFor($secret, $org->id);
        $this->assertSame(['dmp-shop-example-com'], array_keys($yaml['http']['routers']), 'nothing is rendered for an action Traefik cannot enforce');
        $this->assertArrayNotHasKey('middlewares', $yaml['http']);

        $unsupported = app(Tenant::class)->as($org->id, fn () => app(\App\Services\FirewallConfigService::class)->unsupported());
        $this->assertSame([['id' => $unsupported[0]['id'], 'name' => 'Checkout abuse', 'action' => 'recaptcha']], $unsupported);
    }

    public function test_a_disabled_rule_renders_nothing(): void
    {
        [$org, $secret] = $this->verifiedDomain();
        app(Tenant::class)->as($org->id, fn () => FirewallRule::create([
            'name' => 'Off', 'paths' => ['*'], 'limit' => 10, 'window_seconds' => 60,
            'counted_by' => 'ip', 'action' => 'block', 'enabled' => false,
        ]));
        $this->assertArrayNotHasKey('middlewares', $this->yamlFor($secret, $org->id)['http']);
    }

    public function test_a_rule_for_another_organisation_does_not_leak(): void
    {
        [$org, $secret] = $this->verifiedDomain();
        [, $other] = $this->org();
        app(Tenant::class)->as($other->id, fn () => FirewallRule::create([
            'name' => 'Theirs', 'paths' => ['*'], 'limit' => 1, 'window_seconds' => 60,
            'counted_by' => 'ip', 'action' => 'block',
        ]));
        $this->assertArrayNotHasKey('middlewares', $this->yamlFor($secret, $org->id)['http'], 'another tenant\'s rule must not reach this domain');
    }
}
