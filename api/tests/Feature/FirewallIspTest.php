<?php

namespace Tests\Feature;

use App\Contracts\CertificateIssuer;
use App\Contracts\DnsResolver;
use App\Models\Application;
use App\Services\TraefikConfigService;
use App\Support\Tenant;
use Tests\TestCase;

/** ISP (ASN) rules and "always allow" countries render into CrowdSec. */
class FirewallIspTest extends TestCase
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

        return [$org, $secret, $token, $app];
    }

    private function apply(string $orgId, string $secret): array
    {
        app(Tenant::class)->as($orgId, fn () => app(TraefikConfigService::class)->push(true));

        return collect($this->heartbeat($secret)['commands'])->keyBy('type')->get('crowdsec.apply')['payload'];
    }

    public function test_isp_block_challenge_allow_and_country_allow(): void
    {
        [$org, $secret, $token, $app] = $this->site();
        $this->api($token)->patchJson('/api/v1/firewall/isps', ['isps' => [
            ['asn' => 64500, 'name' => 'Bad hosting', 'state' => 'block'],
            ['asn' => 64501, 'state' => 'challenge', 'application_id' => $app->id],
            ['asn' => 9329, 'name' => 'SLT', 'state' => 'allow'],
        ]])->assertOk()->assertJsonCount(3, 'data.isps');
        $this->api($token)->patchJson('/api/v1/firewall/countries', ['countries' => [['code' => 'LK', 'state' => 'allow'], ['code' => 'KP', 'state' => 'block']]])->assertOk();

        $p = $this->apply($org->id, $secret);
        $scen = collect($p['scenarios']);
        $block = $scen->first(fn ($y) => str_contains($y, 'ISP policy (block)'));
        $this->assertStringContainsString("evt.Enriched.ASNNumber in ['64500']", $block);
        $this->assertNotNull($scen->first(fn ($y) => str_contains($y, 'ISP policy (challenge)') && str_contains($y, "'64501'")));
        $this->assertStringContainsString("evt.Enriched.ASNNumber in ['9329']", $p['whitelist']);
        $this->assertStringContainsString("evt.Enriched.IsoCode in ['LK']", $p['whitelist']);
        $this->assertMatchesRegularExpression('/dmp_ban[\s\S]*dmp\/isp-/', $p['profiles']);

        $this->api($token)->patchJson('/api/v1/firewall/isps', ['isps' => [['asn' => 1, 'state' => 'nope']]])->assertStatus(422);
        $this->api($token)->patchJson('/api/v1/firewall/isps', ['isps' => []])->assertOk()->assertJsonCount(0, 'data.isps');
    }
}
