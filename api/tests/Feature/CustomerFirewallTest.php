<?php

namespace Tests\Feature;

use App\Contracts\CertificateIssuer;
use App\Contracts\DnsResolver;
use App\Models\Application;
use App\Models\FirewallCountry;
use App\Models\FirewallProtectedPath;
use App\Services\TraefikConfigService;
use App\Support\Tenant;
use Tests\TestCase;

/** FW-015: customers protect their own domains, within the plan's limits. */
class CustomerFirewallTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.drivers.certificates' => 'traefik', 'platform.crowdsec.enabled' => true, 'platform.crowdsec.bouncer_key' => 'bouncer-key']);
        $this->app->forgetInstance(CertificateIssuer::class);
    }

    private function customer(): array
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['ip' => '198.51.100.9']);
        [$secret] = $this->enrollAgent($server, $token);
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'shop', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'size' => 'small', 'server_id' => $server->id, 'port' => 80]));
        $d = $this->api($token)->postJson('/api/v1/app/domains', ['domain' => 'shop.example.com', 'app_id' => $app->id])->assertCreated()->json('data');
        app(DnsResolver::class)->set('shop.example.com', 'A', ['198.51.100.9']);
        $this->api($token)->postJson("/api/v1/app/domains/{$d['id']}/verify")->assertOk();
        $this->heartbeat($secret);

        return [$org, $token, $secret, $d['id']];
    }

    public function test_customers_protect_paths_and_block_countries_on_their_own_domains(): void
    {
        [$org, $token, $secret, $domain] = $this->customer();
        $page = $this->api($token)->getJson('/api/v1/app/firewall')->assertOk()->json('data');
        $this->assertSame(['paths' => 5, 'countries' => 10], $page['limits']);
        $this->assertSame('shop.example.com', $page['domains'][0]['name']);

        $p = $this->api($token)->postJson("/api/v1/app/firewall/domains/{$domain}/paths", ['path' => '/wp-login.php'])->assertCreated()->json('data');
        $this->api($token)->postJson("/api/v1/app/firewall/domains/{$domain}/paths", ['path' => '/wp-login.php'])->assertStatus(409);
        $this->api($token)->postJson("/api/v1/app/firewall/domains/{$domain}/paths", ['path' => 'no-slash'])->assertStatus(422);
        $this->api($token)->patchJson("/api/v1/app/firewall/domains/{$domain}/countries", ['countries' => [['code' => 'ru', 'state' => 'block'], ['code' => 'CN', 'state' => 'challenge']]])
            ->assertOk()->assertJsonPath('data.used.countries', 2);
        $this->assertSame($domain, FirewallProtectedPath::allTenants()->sole()->domain_id, 'scoped to the domain, never organisation-wide');

        app(Tenant::class)->as($org->id, fn () => app(TraefikConfigService::class)->push(true));
        $cs = collect($this->heartbeat($secret)['commands'])->where('type', 'crowdsec.apply')->last()['payload'];
        $this->assertNotNull(collect($cs['scenarios'])->first(fn ($y) => str_contains($y, "dmp/path-{$p['id']}") && str_contains($y, 'shop.example.com')));
        $this->assertNotNull(collect($cs['scenarios'])->first(fn ($y) => str_contains($y, "evt.Enriched.IsoCode in ['RU']") && str_contains($y, 'shop.example.com')));

        $this->api($token)->patchJson("/api/v1/app/firewall/domains/{$domain}/paths/{$p['id']}", ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);
        $this->api($token)->deleteJson("/api/v1/app/firewall/domains/{$domain}/paths/{$p['id']}")->assertNoContent();
    }

    public function test_the_plan_sets_the_limits_and_zero_turns_it_off(): void
    {
        [$org, $token, , $domain] = $this->customer();
        app(Tenant::class)->as($org->id, function () {
            $plan = app(\App\Services\BillingService::class)->plan();
            $plan->update(['limits' => array_merge($plan->limits ?? [], ['firewall_paths' => 1, 'firewall_countries' => 0])]);
        });
        $this->api($token)->postJson("/api/v1/app/firewall/domains/{$domain}/paths", ['path' => '/login'])->assertCreated();
        $this->api($token)->postJson("/api/v1/app/firewall/domains/{$domain}/paths", ['path' => '/admin'])->assertStatus(402);
        $this->api($token)->patchJson("/api/v1/app/firewall/domains/{$domain}/countries", ['countries' => [['code' => 'RU', 'state' => 'block']]])->assertStatus(402);
        $this->api($token)->patchJson("/api/v1/app/firewall/domains/{$domain}/countries", ['countries' => []])->assertOk();
    }

    public function test_another_customer_cannot_touch_this_domain(): void
    {
        [, , , $domain] = $this->customer();
        [, , $other] = $this->org();
        $this->api($other)->postJson("/api/v1/app/firewall/domains/{$domain}/paths", ['path' => '/login'])->assertNotFound();
        $this->api($other)->patchJson("/api/v1/app/firewall/domains/{$domain}/countries", ['countries' => []])->assertNotFound();
        $this->assertSame(0, FirewallProtectedPath::allTenants()->count() + FirewallCountry::allTenants()->count());
    }
}
