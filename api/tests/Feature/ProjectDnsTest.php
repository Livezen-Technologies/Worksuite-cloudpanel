<?php

namespace Tests\Feature;

use App\Contracts\DnsResolver;
use App\Contracts\DomainProbe;
use App\Models\Application;
use App\Models\DnsProviderAccount;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Project;
use App\Support\Tenant;
use Tests\TestCase;

/** PDNS: Project → DNS & Domains. */
class ProjectDnsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.ingress.hostname' => 'ingress.livezencloud.test', 'platform.ingress.ips' => ['203.0.113.50', '203.0.113.51'], 'platform.domain' => 'livezen.app']);
    }

    private function project($org, array $app = []): array
    {
        return app(Tenant::class)->as($org->id, function () use ($app, $org) {
            $p = Project::create(['name' => 'Shop']);
            $server = $this->server($org, ['ip' => '198.51.100.9']);
            $a = Application::create(array_merge(['name' => 'web', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'size' => 'small', 'port' => 80,
                'project_id' => $p->id, 'server_id' => $server->id, 'status' => 'running'], $app));

            return [$p, $a];
        });
    }

    public function test_custom_domain_gets_ingress_records_and_needs_ownership_before_it_goes_live(): void
    {
        [, $org, $token] = $this->org();
        [$p, $a] = $this->project($org);
        $base = "/api/v1/app/projects/{$p->id}/dns";

        $d = $this->api($token)->postJson("$base/domains", ['kind' => 'custom', 'name' => 'shop.example.com', 'app_id' => $a->id])->assertCreated()->json('data');
        $this->assertTrue($d['is_primary'], 'the first domain of a project is primary');
        $records = collect($d['records']);
        // A subdomain is a CNAME to the ingress host — never the node's address.
        $this->assertSame(['CNAME', 'ingress.livezencloud.test'], [$records[0]['type'], $records[0]['value']]);
        $this->assertFalse($records->contains(fn ($r) => str_contains($r['value'], '198.51.100.9')));
        $txt = $records->firstWhere('purpose', 'ownership');
        $this->assertSame('_livezen-verify.shop.example.com', $txt['name']);
        $this->assertSame('awaiting ownership', $d['status']);

        // Routing set up, ownership not proven: DNS is not verified and no certificate is issued.
        $dns = app(DnsResolver::class);
        $dns->set('shop.example.com', 'CNAME', ['ingress.livezencloud.test.']);
        $dns->set('shop.example.com', 'A', ['203.0.113.50']);
        $v = $this->api($token)->postJson("$base/domains/{$d['id']}/validate")->assertOk()->json('data');
        $checks = collect($v['checks'])->keyBy('key');
        $this->assertSame('fail', $checks['ownership']['status']);
        $this->assertSame('pass', $checks['cname_target']['status']);
        $this->assertSame('pass', $checks['ingress_ip']['status']);
        $this->assertSame('pass', $checks['propagation']['status']);
        $this->assertSame('pass', $checks['backend']['status']);
        $this->assertSame('fail', $checks['ssl']['status']);
        $this->assertFalse($v['ok']);
        $this->assertSame('pending', $v['dns_status']);

        $dns->set('_livezen-verify.shop.example.com', 'TXT', [$txt['value']]);
        $probe = app(DomainProbe::class);
        $probe->certificates['shop.example.com'] = ['ok' => true, 'names' => ['shop.example.com'], 'issuer' => "Let's Encrypt", 'expires_at' => now()->addDays(80)->toIso8601String()];
        $probe->responses['shop.example.com'] = ['ok' => true, 'status' => 200];
        $v = $this->api($token)->postJson("$base/domains/{$d['id']}/validate")->assertOk()->json('data');
        $this->assertTrue($v['ok'], json_encode($v['checks']));
        $this->assertSame('verified', $v['dns_status']);
        $this->assertSame('active', $v['https_status']);
        $this->assertSame('active', $this->api($token)->getJson($base)->json('data.domains.0.status'));

        $actions = collect($this->api($token)->getJson("$base/history")->assertOk()->json('data'))->pluck('action');
        $this->assertTrue($actions->contains('domain.added'));
        $this->assertTrue($actions->contains('domain.ownership_verified'));
    }

    public function test_validation_reports_a_record_pointing_at_the_node_and_partial_propagation(): void
    {
        [, $org, $token] = $this->org();
        [$p, $a] = $this->project($org);
        $base = "/api/v1/app/projects/{$p->id}/dns";
        $d = $this->api($token)->postJson("$base/domains", ['kind' => 'custom', 'name' => 'example.org', 'app_id' => $a->id])->assertCreated()->json('data');
        // An apex domain gets A records for every ingress address.
        $this->assertSame(['203.0.113.50', '203.0.113.51'], collect($d['records'])->where('type', 'A')->pluck('value')->values()->all());

        app(DnsResolver::class)->set('example.org', 'A', ['198.51.100.9']);
        app(DomainProbe::class)->resolvers['Google'] = ['example.org' => ['A' => ['203.0.113.50']]];
        $checks = collect($this->api($token)->postJson("$base/domains/{$d['id']}/validate")->json('data.checks'))->keyBy('key');
        $this->assertSame('fail', $checks['ingress_ip']['status']);
        $this->assertStringContainsString('server running the app', $checks['ingress_ip']['message']);
        $this->assertSame('198.51.100.9', $checks['ingress_ip']['actual']);
        $this->assertSame('warn', $checks['propagation']['status']);
        $this->assertSame('skip', $checks['cname_target']['status']);
    }

    public function test_platform_subdomain_one_owner_per_domain_and_primary_removal_needs_confirmation(): void
    {
        [, $org, $token] = $this->org();
        [$p, $a] = $this->project($org);
        $base = "/api/v1/app/projects/{$p->id}/dns";
        $sub = $this->api($token)->postJson("$base/domains", ['kind' => 'platform', 'name' => 'my-shop', 'app_id' => $a->id])->assertCreated()->json('data');
        $this->assertSame(['my-shop.livezen.app', 'verified', true], [$sub['name'], $sub['dns_status'], $sub['is_primary']]);
        $custom = $this->api($token)->postJson("$base/domains", ['kind' => 'custom', 'name' => 'shop.example.net', 'app_id' => $a->id])->assertCreated()->json('data');
        $this->assertFalse($custom['is_primary']);

        // Another customer cannot claim the same name.
        [, $org2, $token2] = $this->org();
        [$p2, $a2] = $this->project($org2);
        $this->api($token2)->postJson("/api/v1/app/projects/{$p2->id}/dns/domains", ['kind' => 'custom', 'name' => 'shop.example.net', 'app_id' => $a2->id])->assertStatus(409);
        // …nor see the first customer's project.
        $this->api($token2)->getJson($base)->assertNotFound();

        // A pending domain cannot become primary.
        $this->api($token)->patchJson("$base/domains/{$custom['id']}", ['is_primary' => true])->assertStatus(409);

        $this->api($token)->deleteJson("$base/domains/{$sub['id']}")->assertStatus(422);
        $this->api($token)->deleteJson("$base/domains/{$sub['id']}", ['confirm' => 'my-shop.livezen.app'])->assertNoContent();
        $this->assertSame(1, $this->api($token)->getJson($base)->json('data.counts.total'));
    }

    public function test_members_need_grants_from_the_owner(): void
    {
        [, $org, $ownerToken] = $this->org();
        [$p, $a] = $this->project($org);
        [$dev, $devToken] = $this->member($org, 'developer');
        $base = "/api/v1/app/projects/{$p->id}/dns";

        $this->api($devToken)->getJson($base)->assertOk()->assertJsonPath('data.permissions', ['view']);
        $this->api($devToken)->postJson("$base/domains", ['kind' => 'custom', 'name' => 'dev.example.com', 'app_id' => $a->id])->assertForbidden();
        $this->api($devToken)->patchJson("$base/permissions/{$dev->id}", ['permissions' => ['add']])->assertForbidden();

        $this->api($ownerToken)->patchJson("$base/permissions/{$dev->id}", ['permissions' => ['add']])->assertOk();
        $id = $this->api($devToken)->postJson("$base/domains", ['kind' => 'custom', 'name' => 'dev.example.com', 'app_id' => $a->id])->assertCreated()->json('data.id');
        $this->api($devToken)->deleteJson("$base/domains/{$id}")->assertForbidden();
        $this->assertTrue(collect($this->api($ownerToken)->getJson("$base/history")->json('data'))->pluck('action')->contains('domain.permissions_changed'));
    }

    public function test_provider_publishing_never_overwrites_existing_records(): void
    {
        $remote = [
            'r1' => ['id' => 'r1', 'type' => 'A', 'name' => 'www.example.com', 'content' => '192.0.2.1', 'ttl' => 300, 'proxied' => false],
            'r2' => ['id' => 'r2', 'type' => 'MX', 'name' => 'example.com', 'content' => 'mx.example.com', 'ttl' => 300, 'priority' => 10, 'proxied' => false],
            'r3' => ['id' => 'r3', 'type' => 'TXT', 'name' => 'example.com', 'content' => 'v=spf1 include:_spf.example.com -all', 'ttl' => 300, 'proxied' => false],
        ];
        $writes = [];
        \Illuminate\Support\Facades\Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$remote, &$writes) {
            $path = substr(parse_url($request->url(), PHP_URL_PATH), strlen('/client/v4'));
            $ok = fn ($r, $extra = []) => \Illuminate\Support\Facades\Http::response(['success' => true, 'errors' => [], 'result' => $r] + $extra);

            return match (true) {
                $path === '/user/tokens/verify' => $ok(['id' => 'abcdef1234567890', 'status' => 'active']),
                $path === '/zones' && $request->method() === 'GET' => $ok([]),
                $path === '/zones' => $ok(['id' => 'z1', 'status' => 'active', 'name_servers' => ['ada.ns.cloudflare.com']]),
                $path === '/zones/z1/dns_records' && $request->method() === 'GET' => $ok(array_values($remote), ['result_info' => ['total_pages' => 1]]),
                $path === '/zones/z1/dns_records' && $request->method() === 'POST' => $ok($remote[$id = 'n'.count($writes)] = ($writes[] = ['id' => 'n'.count($writes)] + $request->data())),
                default => (function () use (&$writes, $request) { $writes[] = [$request->method(), $request->url()]; return \Illuminate\Support\Facades\Http::response(['success' => false, 'errors' => [['message' => 'refused in test']]], 400); })(),
            };
        });
        [, $org, $token] = $this->org();
        [$p, $a] = $this->project($org);
        $provider = $this->api($token)->postJson('/api/v1/dns/providers', ['name' => 'CF', 'type' => 'cloudflare', 'token' => 'zone-token'])->assertCreated()->json('data');
        $this->api($token)->postJson('/api/v1/dns/zones', ['dns_provider_id' => $provider['id'], 'name' => 'example.com'])->assertCreated();

        $d = $this->api($token)->postJson("/api/v1/app/projects/{$p->id}/dns/domains", ['kind' => 'custom', 'name' => 'www.example.com', 'app_id' => $a->id, 'method' => 'provider'])->assertCreated()->json('data');
        $published = collect($d['published'])->keyBy('type');
        // The CNAME would replace the customer's own A record: refused, reported, left alone.
        $this->assertSame('conflict', $published['CNAME']['status']);
        $this->assertSame('created', $published['TXT']['status']);
        // Only new TXT records (ownership, ACME challenge) were written; nothing updated or deleted.
        $this->assertSame(['TXT', 'TXT'], array_column($writes, 'type'));
        $this->assertSame(['_livezen-verify.www.example.com', '_acme-challenge.www.example.com'], array_column($writes, 'name'));
        $this->assertSame(['192.0.2.1', 'mx.example.com'], [$remote['r1']['content'], $remote['r2']['content']]);
    }
}
