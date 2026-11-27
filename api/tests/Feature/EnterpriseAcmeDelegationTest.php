<?php

namespace Tests\Feature;

use App\Contracts\DnsResolver;
use App\Models\Application;
use App\Models\Certificate;
use App\Models\DnsProviderAccount;
use App\Models\DnsZone;
use App\Models\Domain;
use App\Models\Organization;
use App\Services\AcmeDelegation;
use App\Services\DnsValidationService;
use App\Services\DomainService;
use App\Services\TraefikConfigService;
use App\Support\Tenant;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * ACME-DLG: each edge orders its own certificate, so HTTP-01 fails for a customer-owned Enterprise
 * domain (the CA lands on the edge without the token). Such domains get DNS-01 through a CNAME
 * _acme-challenge.<name> → <label>.acme.<zone> in a zone the panel writes via Cloudflare.
 */
class EnterpriseAcmeDelegationTest extends TestCase
{
    private const EDGES = ['51.254.220.203', '51.254.213.183'];

    private Organization $org;

    private string $token;

    private $node;

    protected function setUp(): void
    {
        parent::setUp();
        // The platform's own zone, owned by another organisation than the customer's.
        [, $platform] = $this->org();
        $provider = DnsProviderAccount::allTenants()->create(['organization_id' => $platform->id, 'name' => 'CF', 'type' => 'cloudflare', 'credentials' => ['token' => 'platform-zone-token']]);
        DnsZone::allTenants()->create(['organization_id' => $platform->id, 'dns_provider_id' => $provider->id, 'name' => 'worksuite.lk', 'remote_id' => 'z1', 'status' => 'active']);
        config(['platform.acme_delegation_zone' => 'worksuite.lk']);
        [, $this->org, $this->token] = $this->org();
        $this->node = $this->server($this->org, ['name' => 'node-05', 'ip' => '203.0.113.5']);
    }

    private function edges(): void
    {
        $this->server($this->org, ['name' => 'frontdoor-01', 'ip' => self::EDGES[0], 'tags' => ['ingress', 'enterprise-edge']]);
        $this->server($this->org, ['name' => 'frontdoor-02', 'ip' => self::EDGES[1], 'tags' => ['ingress', 'enterprise-edge']]);
    }

    /** A project domain of an app (Enterprise or not), added by hand like onesource.pmm.lk. */
    private function domain(string $name, bool $enterprise, array $extra = []): Domain
    {
        $app = app(Tenant::class)->as($this->org->id, fn () => Application::create(['name' => 'a'.substr(md5($name), 0, 8), 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx',
            'server_id' => $this->node->id, 'port' => 8080, 'source' => $enterprise ? ['enterprise_deployment_id' => 'dep-1'] : []]));
        $id = $this->api($this->token)->postJson('/api/v1/domains', ['name' => $name, 'application_id' => $app->id, 'target_port' => 8080] + $extra)->assertCreated()->json('data.id');
        Domain::allTenants()->whereKey($id)->update(['dns_method' => 'manual', 'ownership_token' => 'tok'.substr(md5($name), 0, 8)]);

        return Domain::allTenants()->findOrFail($id);
    }

    /** Puts the routing A records and the ownership TXT in (fake) public DNS. */
    private function publicDns(Domain $d): void
    {
        $dns = app(DnsResolver::class);
        $dns->set($d->name, 'A', self::EDGES);
        $dns->set('_livezen-verify.'.$d->name, 'TXT', ['livezen-verify='.$d->ownership_token]);
    }

    public function test_an_enterprise_custom_domain_on_the_edges_gets_a_delegation_cname_and_the_dns_resolver(): void
    {
        config(['platform.drivers.certificates' => 'traefik']);
        $this->edges();
        $d = $this->domain('onesource.pmm.test', true);
        $this->assertSame('dns-01', $d->challenge, 'HTTP-01 cannot work across two edges');

        $records = collect(app(DomainService::class)->projectRecords($d));
        $target = AcmeDelegation::target($d);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}\.acme\.worksuite\.lk$/', $target);
        $this->assertStringNotContainsString('onesource', $target);
        $this->assertEquals(['type' => 'CNAME', 'name' => '_acme-challenge.onesource.pmm.test', 'value' => $target, 'purpose' => 'certificate'],
            $records->firstWhere('purpose', 'certificate'));
        $this->assertCount(0, $records->where('type', 'TXT')->where('name', '_acme-challenge.onesource.pmm.test'), 'the CNAME replaces the TXT');
        $this->assertSame('_livezen-verify.onesource.pmm.test', $records->firstWhere('purpose', 'ownership')['name'], 'ownership TXT stays');
        $this->assertSame(self::EDGES, $records->where('type', 'A')->pluck('value')->all());

        $svc = app(TraefikConfigService::class);
        $this->assertSame('le-dns-cloudflare', $svc->resolverFor($d));
        $this->assertSame(['CF_DNS_API_TOKEN' => 'platform-zone-token'], $svc->dnsSecrets(), 'the delegation zone token goes to the edges');
        $d->update(['dns_status' => 'verified']);
        $edge = \App\Models\Server::allTenants()->where('name', 'frontdoor-01')->first();
        $router = Yaml::parse($svc->yaml($edge))['http']['routers']['dmp-onesource-pmm-test'];
        $this->assertSame('le-dns-cloudflare', $router['tls']['certResolver']);
        $this->assertSame(['name' => '_acme-challenge.onesource.pmm.test', 'type' => 'CNAME', 'target' => $target],
            app(DomainService::class)->toApi($d->fresh())['acme_delegation']);
    }

    public function test_verification_waits_for_the_cname_and_validation_says_what_to_add(): void
    {
        $this->edges();
        $d = $this->domain('onesource.pmm.test', true);
        $this->publicDns($d);
        $svc = app(DomainService::class);

        $this->assertSame('pending', $svc->verify($d)['dns_status']);
        $this->assertNotNull($d->fresh()->ownership_verified_at, 'ownership still verifies on its own');
        $check = collect(app(DnsValidationService::class)->validate($d->fresh())['checks'])->firstWhere('key', 'acme_delegation');
        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('Add _acme-challenge.onesource.pmm.test CNAME '.AcmeDelegation::target($d), $check['message']);

        app(DnsResolver::class)->set('_acme-challenge.onesource.pmm.test', 'CNAME', ['elsewhere.example.']);
        $check = collect(app(DnsValidationService::class)->validate($d->fresh())['checks'])->firstWhere('key', 'acme_delegation');
        $this->assertStringContainsString('points at elsewhere.example instead', $check['message']);

        app(DnsResolver::class)->set('_acme-challenge.onesource.pmm.test', 'CNAME', [AcmeDelegation::target($d).'.']);
        $this->assertSame('verified', $svc->verify($d->fresh())['dns_status']);
        $check = collect(app(DnsValidationService::class)->validate($d->fresh())['checks'])->firstWhere('key', 'acme_delegation');
        $this->assertSame('pass', $check['status']);
    }

    public function test_a_missing_cname_never_takes_a_domain_with_a_live_certificate_offline(): void
    {
        $this->edges();
        $d = $this->domain('live.pmm.test', true);
        $this->publicDns($d);
        $d->update(['dns_status' => 'verified', 'https_status' => 'active']);

        $this->assertSame('verified', app(DomainService::class)->verify($d->fresh())['dns_status']);
    }

    public function test_other_domains_are_unchanged(): void
    {
        $svc = app(TraefikConfigService::class);
        // No edges yet: an Enterprise domain keeps HTTP-01 on the ingress.
        $early = $this->domain('early.pmm.test', true);
        $this->assertSame('http-01', $early->challenge);
        $this->assertSame('le', $svc->resolverFor($early));

        $this->edges();
        // Not Enterprise: HTTP-01, no CNAME, exactly as before.
        $plain = $this->domain('shop.example.test', false);
        $this->assertSame('http-01', $plain->challenge);
        $this->assertSame('le', $svc->resolverFor($plain));
        $this->assertNull(collect(app(DomainService::class)->projectRecords($plain))->firstWhere('purpose', 'certificate'));

        // A token given for the domain: its own provider, no delegation.
        $own = $this->domain('own.pmm.test', true, ['challenge' => 'dns-01', 'dns_token' => 'customer-token']);
        $this->assertNull(AcmeDelegation::zoneFor($own));
        $this->assertSame('TXT', collect(app(DomainService::class)->projectRecords($own))->firstWhere('purpose', 'certificate')['type']);

        // Delegation off in config: nothing delegates.
        config(['platform.acme_delegation_zone' => '']);
        $this->assertSame('http-01', $this->domain('off.pmm.test', true)->challenge);
    }

    public function test_the_switch_command_moves_only_domains_that_never_had_a_certificate(): void
    {
        // Added before the edges existed, so still on HTTP-01.
        $stuck = $this->domain('onesource.pmm.test', true);
        $live = $this->domain('admin.salon.test', true);
        $live->update(['https_status' => 'active']);
        Certificate::allTenants()->create(['organization_id' => $this->org->id, 'domain_id' => $live->id, 'status' => 'active', 'names' => [$live->name], 'expires_at' => now()->addDays(60)]);
        $plain = $this->domain('shop.example.test', false);
        $this->edges();

        $this->artisan('domains:acme-delegate', ['--dry-run' => true])->expectsOutputToContain('[dry run] onesource.pmm.test')->assertSuccessful();
        $this->assertSame('http-01', $stuck->fresh()->challenge);

        $this->artisan('domains:acme-delegate')
            ->expectsOutputToContain('onesource.pmm.test: http-01 → dns-01. Customer adds: _acme-challenge.onesource.pmm.test CNAME '.AcmeDelegation::target($stuck))
            ->assertSuccessful();
        $this->assertSame('dns-01', $stuck->fresh()->challenge);
        $this->assertSame('le-dns-cloudflare', app(TraefikConfigService::class)->resolverFor($stuck->fresh()));
        $this->assertSame('http-01', $live->fresh()->challenge, 'an active certificate is never touched');
        $this->assertSame('http-01', $plain->fresh()->challenge);

        $this->artisan('domains:acme-delegate')->expectsOutputToContain('Nothing to switch.')->assertSuccessful();
    }
}
