<?php

namespace Tests\Feature;

use App\Contracts\CertificateIssuer;
use App\Contracts\DnsResolver;
use App\Models\Application;
use App\Models\Domain;
use App\Models\FirewallCountry;
use App\Models\FirewallEvent;
use App\Models\FirewallProtectedPath;
use App\Models\FirewallRule;
use App\Services\FirewallEventService;
use App\Services\TraefikConfigService;
use App\Support\Tenant;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/** FW-001 enforcement through CrowdSec and its Traefik bouncer. */
class CrowdSecTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.drivers.certificates' => 'traefik', 'platform.crowdsec.enabled' => true, 'platform.crowdsec.bouncer_key' => 'bouncer-key']);
        $this->app->forgetInstance(CertificateIssuer::class);
    }

    private function verifiedDomain(): array
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['ip' => '198.51.100.9']);
        [$secret] = $this->enrollAgent($server, $token);
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'shop', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'size' => 'small', 'server_id' => $server->id, 'port' => 80]));
        $created = $this->api($token)->postJson('/api/v1/app/domains', ['domain' => 'shop.example.com', 'app_id' => $app->id])->assertCreated()->json('data');
        app(DnsResolver::class)->set('shop.example.com', 'A', ['198.51.100.9']);
        $this->api($token)->postJson("/api/v1/app/domains/{$created['id']}/verify")->assertOk();

        return [$org, $secret, Domain::allTenants()->find($created['id']), $token];
    }

    private function push(string $orgId, string $secret): array
    {
        app(Tenant::class)->as($orgId, fn () => app(TraefikConfigService::class)->push(true));

        return collect($this->heartbeat($secret)['commands'])->keyBy('type')->all();
    }

    public function test_rules_countries_and_paths_become_scenarios_and_profiles(): void
    {
        [$org, $secret] = $this->verifiedDomain();
        $rule = app(Tenant::class)->as($org->id, function () {
            FirewallRule::create(['name' => 'Throttle API', 'paths' => ['/api/*'], 'limit' => 600, 'window_seconds' => 60, 'action' => 'throttle']);
            FirewallCountry::create(['code' => 'ru', 'state' => 'block']);
            FirewallProtectedPath::create(['path' => '/wp-admin/*']);

            return FirewallRule::create(['name' => 'Login brute force', 'paths' => ['/wp-login.php'], 'methods' => ['post'], 'limit' => 5, 'window_seconds' => 60, 'action' => 'challenge']);
        });

        $commands = $this->push($org->id, $secret);
        $this->assertSame(['proxy.config', 'crowdsec.apply'], array_values(array_intersect(array_keys($commands), ['proxy.config', 'crowdsec.apply'])), 'crowdsec.apply goes out with the proxy config');
        $scenarios = $commands['crowdsec.apply']['payload']['scenarios'];
        $this->assertCount(3, $scenarios, 'throttle stays a native rate limit; challenge, country and path become scenarios');

        $login = Yaml::parse($scenarios['dmp-fw-'.substr(str_replace('-', '', $rule->id), 0, 12).'.yaml']);
        $this->assertSame('leaky', $login['type']);
        $this->assertSame('dmp/fw-'.$rule->id, $login['name']);
        $this->assertSame(5, $login['capacity']);
        $this->assertSame('12s', $login['leakspeed']);
        $this->assertStringContainsString("evt.Meta.target_fqdn in ['shop.example.com']", $login['filter']);
        $this->assertStringContainsString("evt.Meta.http_path matches '^\\\\/wp\\\\-login\\\\.php'", $login['filter']);
        $this->assertStringContainsString("evt.Meta.http_verb in ['POST']", $login['filter']);

        $geo = collect($scenarios)->first(fn ($y) => str_contains($y, 'dmp/geo-'));
        $this->assertStringContainsString("evt.Enriched.IsoCode in ['RU']", $geo);

        $profiles = $commands['crowdsec.apply']['payload']['profiles'];
        $this->assertStringContainsString('name: dmp_captcha', $profiles);
        $this->assertStringContainsString('dmp/fw-'.$rule->id, $profiles);
        $this->assertStringContainsString('name: dmp_ban', $profiles);
        $this->assertStringContainsString('name: default_ip_remediation', $profiles, 'CrowdSec\'s own scenarios keep their default ban');
    }

    public function test_every_router_runs_the_bouncer_first_with_the_organisations_captcha(): void
    {
        [$org, $secret, , $token] = $this->verifiedDomain();
        $settings = $org->settings ?? [];
        $settings['firewall'] = ['provider' => 'turnstile', 'site_key' => 'site-key', 'secret_key_encrypted' => Crypt::encryptString('captcha-secret'), 'clearance_minutes' => 45];
        $org->forceFill(['settings' => $settings])->save();
        app(Tenant::class)->as($org->id, fn () => FirewallRule::create(['name' => 'Flood', 'paths' => ['*'], 'limit' => 3000, 'window_seconds' => 60, 'action' => 'block']));

        $yaml = Yaml::parse($this->push($org->id, $secret)['proxy.config']['payload']['files']['dmp-domains.yml']);
        $router = $yaml['http']['routers']['dmp-shop-example-com'];
        $this->assertStringStartsWith('dmp-crowdsec-', $router['middlewares'][0], 'the bouncer runs before the rate limit');
        $bouncer = $yaml['http']['middlewares'][$router['middlewares'][0]]['plugin']['bouncer'];
        $this->assertSame('bouncer-key', $bouncer['crowdsecLapiKey']);
        $this->assertSame('turnstile', $bouncer['captchaProvider']);
        $this->assertSame('captcha-secret', $bouncer['captchaSecretKey']);
        $this->assertSame(2700, $bouncer['captchaGracePeriodSeconds']);

        // Challenge rules are enforced now, so the screen must not call them unsupported.
        app(Tenant::class)->as($org->id, fn () => FirewallRule::create(['name' => 'Bots', 'paths' => ['*'], 'limit' => 100, 'window_seconds' => 60, 'action' => 'recaptcha']));
        $this->api($token)->getJson('/api/v1/firewall')->assertOk()
            ->assertJsonPath('data.unsupported', [])->assertJsonPath('data.country_enforcement', 'crowdsec');
    }

    public function test_disabled_crowdsec_changes_nothing(): void
    {
        config(['platform.crowdsec.enabled' => false]);
        [$org, $secret] = $this->verifiedDomain();
        $commands = $this->push($org->id, $secret);
        $this->assertArrayHasKey('proxy.config', $commands);
        $this->assertArrayNotHasKey('crowdsec.apply', $commands);
        $this->assertStringNotContainsString('crowdsec', $commands['proxy.config']['payload']['files']['dmp-domains.yml']);
    }

    public function test_alerts_become_firewall_events_once(): void
    {
        [$org, $secret, $domain] = $this->verifiedDomain();
        $rule = app(Tenant::class)->as($org->id, fn () => FirewallRule::create(['name' => 'Login brute force', 'paths' => ['/wp-login.php'], 'limit' => 5, 'window_seconds' => 60, 'action' => 'block']));
        [, $other] = $this->org();

        $this->assertSame(1, app(FirewallEventService::class)->sync());
        $command = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'crowdsec.alerts');
        $alerts = [
            ['id' => 1, 'scenario' => 'dmp/fw-'.$rule->id, 'ip' => '203.0.113.9', 'country' => 'ru', 'at' => '2026-10-30T01:00:00Z', 'decision' => 'ban', 'host' => 'shop.example.com', 'method' => 'POST', 'path' => '/wp-login.php'],
            ['id' => 2, 'scenario' => 'crowdsecurity/http-probing', 'ip' => '198.51.100.20', 'at' => '2026-10-30T01:01:00Z', 'decision' => 'ban', 'host' => 'www.shop.example.com', 'path' => '/.env'],
            ['id' => 3, 'scenario' => 'crowdsecurity/ssh-bf', 'ip' => '198.51.100.21', 'at' => '2026-10-30T01:02:00Z', 'decision' => 'ban'],
        ];
        $this->commandResult($secret, $command['id'], 'succeeded', ['output' => ['alerts' => $alerts]])->assertOk();

        $events = FirewallEvent::allTenants()->orderBy('occurred_at')->get();
        $this->assertCount(2, $events, 'an alert with no host or panel scenario belongs to no organisation');
        $this->assertSame([$org->id, $org->id], $events->pluck('organization_id')->all());
        $this->assertSame('Login brute force', $events[0]->rule_name);
        $this->assertSame('block', $events[0]->action);
        $this->assertSame('RU', $events[0]->country);
        $this->assertSame($domain->id, $events[1]->domain_id, 'CrowdSec\'s own scenarios are attributed by host');
        $this->assertSame(1, $rule->fresh()->hits);
        $this->assertSame(0, FirewallEvent::allTenants()->where('organization_id', $other->id)->count());

        // The next poll overlaps this one; the same alert is not recorded twice.
        app(FirewallEventService::class)->sync();
        $again = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'crowdsec.alerts');
        $this->commandResult($secret, $again['id'], 'succeeded', ['output' => ['alerts' => $alerts]])->assertOk();
        $this->assertSame(2, FirewallEvent::allTenants()->count());
    }
}
