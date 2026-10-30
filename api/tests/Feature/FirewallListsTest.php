<?php

namespace Tests\Feature;

use App\Contracts\CertificateIssuer;
use App\Contracts\DnsResolver;
use App\Models\Application;
use App\Models\FirewallEvent;
use App\Models\FirewallIpList;
use App\Services\TraefikConfigService;
use App\Support\Tenant;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/** FW-010 under attack, FW-011 IP lists, FW-012 export, FW-014 self-block guard. */
class FirewallListsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.drivers.certificates' => 'traefik', 'platform.crowdsec.enabled' => true, 'platform.crowdsec.bouncer_key' => 'bouncer-key']);
        $this->app->forgetInstance(CertificateIssuer::class);
    }

    private function site(): array
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['ip' => '198.51.100.9']);
        [$secret] = $this->enrollAgent($server, $token);
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'shop', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'size' => 'small', 'server_id' => $server->id, 'port' => 80]));
        $d = $this->api($token)->postJson('/api/v1/app/domains', ['domain' => 'shop.example.com', 'app_id' => $app->id])->assertCreated()->json('data');
        app(DnsResolver::class)->set('shop.example.com', 'A', ['198.51.100.9']);
        $this->api($token)->postJson("/api/v1/app/domains/{$d['id']}/verify")->assertOk();
        $this->heartbeat($secret);

        return [$org, $secret, $token, $d['id']];
    }

    private function apply(string $orgId, string $secret): array
    {
        app(Tenant::class)->as($orgId, fn () => app(TraefikConfigService::class)->push(true));

        return collect($this->heartbeat($secret)['commands'])->keyBy('type')->get('crowdsec.apply')['payload'];
    }

    public function test_block_and_allow_lists_render_as_a_ban_scenario_and_a_whitelist(): void
    {
        [$org, $secret, $token] = $this->site();
        $this->api($token)->postJson('/api/v1/firewall/ips', ['list' => 'block', 'cidr' => '203.0.113.0/24', 'note' => 'scanner'])
            ->assertCreated()->assertJsonPath('data.cidr', '203.0.113.0/24')->assertJsonPath('data.scope', 'all domains');
        $this->api($token)->postJson('/api/v1/firewall/ips', ['list' => 'allow', 'cidr' => '192.0.2.10'])
            ->assertCreated()->assertJsonPath('data.cidr', '192.0.2.10/32');
        $this->api($token)->postJson('/api/v1/firewall/ips', ['list' => 'allow', 'cidr' => '192.0.2.10/32'])->assertStatus(409);
        $this->api($token)->postJson('/api/v1/firewall/ips', ['list' => 'block', 'cidr' => 'not-an-ip'])->assertStatus(422);

        $p = $this->apply($org->id, $secret);
        $block = collect($p['scenarios'])->first(fn ($y) => str_contains($y, 'dmp/block-'));
        $this->assertStringContainsString("IpInRange(evt.Meta.source_ip, '203.0.113.0/24')", $block);
        $this->assertStringContainsString('dmp/block-'.$org->id, $p['profiles']);
        $wl = Yaml::parse($p['whitelist']);
        $this->assertSame("evt.Meta.target_fqdn in ['shop.example.com'] && (IpInRange(evt.Meta.source_ip, '192.0.2.10/32'))", $wl['whitelist']['expression'][0]);
    }

    public function test_expired_entries_are_ignored_and_then_removed(): void
    {
        [$org, $secret] = $this->site();
        app(Tenant::class)->as($org->id, fn () => FirewallIpList::create(['list' => 'block', 'cidr' => '203.0.113.5/32', 'expires_at' => now()->subMinute()]));

        $p = $this->apply($org->id, $secret);
        $this->assertNull(collect($p['scenarios'])->first(fn ($y) => str_contains($y, 'dmp/block-')));
        $this->assertSame('', $p['whitelist']);

        $this->artisan('firewall:expire-ips')->assertSuccessful();
        $this->assertSame(0, FirewallIpList::allTenants()->count());
    }

    public function test_under_attack_challenges_every_visitor_on_the_scope(): void
    {
        [$org, $secret, $token, $domainId] = $this->site();
        $this->api($token)->patchJson('/api/v1/firewall/under-attack', ['enabled' => true, 'domain_id' => $domainId])
            ->assertOk()->assertJsonPath('data.settings.under_attack', true);

        $p = $this->apply($org->id, $secret);
        $attack = Yaml::parse(collect($p['scenarios'])->first(fn ($y) => str_contains($y, 'dmp/attack-')));
        $this->assertSame('trigger', $attack['type']);
        $this->assertStringContainsString("['shop.example.com']", $attack['filter']);
        $this->assertMatchesRegularExpression('/name: dmp_captcha[\s\S]*dmp\/attack-/', $p['profiles']);

        $this->api($token)->patchJson('/api/v1/firewall/under-attack', ['enabled' => false])->assertOk();
        $p = $this->apply($org->id, $secret);
        $this->assertNull(collect($p['scenarios'])->first(fn ($y) => str_contains($y, 'dmp/attack-')));
    }

    public function test_blocking_your_own_address_needs_confirmation(): void
    {
        [, , $token] = $this->org();
        $this->api($token)->postJson('/api/v1/firewall/ips', ['list' => 'block', 'cidr' => '127.0.0.0/8'])
            ->assertStatus(409)->assertSee("your own address");
        $this->api($token)->postJson('/api/v1/firewall/ips', ['list' => 'block', 'cidr' => '127.0.0.0/8', 'confirm' => true])->assertCreated();
        $this->api($token)->postJson('/api/v1/firewall/ips', ['list' => 'allow', 'cidr' => '127.0.0.1'])->assertCreated();
    }

    public function test_events_export_as_csv_with_filters_and_no_formulas(): void
    {
        [, $org, $token] = $this->org();
        app(Tenant::class)->as($org->id, function () {
            FirewallEvent::create(['occurred_at' => now(), 'action' => 'block', 'ip' => '203.0.113.7', 'path' => '/wp-login.php', 'method' => 'POST', 'rule_name' => '=HYPERLINK("x")']);
            FirewallEvent::create(['occurred_at' => now()->subMinute(), 'action' => 'challenge', 'ip' => '198.51.100.1', 'path' => '/']);
        });

        $out = $this->api($token)->getJson('/api/v1/firewall/events/export?action=block')->assertOk()->json('data');
        $lines = explode("\n", trim($out['csv']));
        $this->assertSame('time,action,ip,country,request,rule,domain', $lines[0]);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('POST /wp-login.php', $lines[1]);
        $this->assertStringContainsString('"\'=HYPERLINK(""x"")"', $lines[1]);
        $this->assertStringEndsWith('.csv', $out['filename']);
    }

    public function test_another_organisation_cannot_touch_these_entries(): void
    {
        [, , $token] = $this->org();
        $id = $this->api($token)->postJson('/api/v1/firewall/ips', ['list' => 'block', 'cidr' => '203.0.113.9'])->json('data.id');
        [, , $other] = $this->org();
        $this->api($other)->deleteJson("/api/v1/firewall/ips/{$id}")->assertNotFound();
        $this->api($other)->getJson('/api/v1/firewall')->assertOk()->assertJsonCount(0, 'data.ip_lists');
    }
}
