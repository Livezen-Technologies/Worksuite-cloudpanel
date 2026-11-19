<?php

namespace Tests\Feature;

use App\Contracts\DnsResolver;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\DnsProviderAccount;
use App\Models\Domain;
use App\Models\Project;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DomainService;
use App\Services\EmailTemplateService;
use App\Support\DomainName;
use App\Support\Tenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** PDNS slice 2: customer Cloudflare, PSL apex detection, state notifications, staff view, revoke all. */
class ProjectDns2Test extends TestCase
{
    /** @var array<string, array> remote Cloudflare records */
    private array $remote = [];

    private array $writes = [];

    public array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.ingress.hostname' => 'ingress.livezencloud.test', 'platform.ingress.ips' => ['203.0.113.50'], 'platform.domain' => 'livezen.app']);
        $test = $this;
        $this->app->instance(EmailTemplateService::class, new class(app(AuditLogger::class), $test) extends EmailTemplateService
        {
            public function __construct(AuditLogger $audit, private $test)
            {
                parent::__construct($audit);
            }

            public function notify(string $key, User|string $to, array $vars = []): bool
            {
                $this->test->sent[] = [$key, $to instanceof User ? $to->email : $to, $vars['change'] ?? null];

                return true;
            }
        });
    }

    private function fakeCloudflare(): void
    {
        $this->remote = [
            'r1' => ['id' => 'r1', 'type' => 'A', 'name' => 'example.co.uk', 'content' => '192.0.2.1', 'ttl' => 300, 'proxied' => false],
            'r2' => ['id' => 'r2', 'type' => 'MX', 'name' => 'example.co.uk', 'content' => 'mx.example.co.uk', 'ttl' => 300, 'priority' => 10, 'proxied' => false],
            'r3' => ['id' => 'r3', 'type' => 'TXT', 'name' => '_dmarc.example.co.uk', 'content' => 'v=DMARC1; p=none', 'ttl' => 300, 'proxied' => false],
        ];
        Http::fake(function (Request $request) {
            $path = substr(parse_url($request->url(), PHP_URL_PATH), strlen('/client/v4'));
            $ok = fn ($r, $extra = []) => Http::response(['success' => true, 'errors' => [], 'result' => $r] + $extra);
            if ($request->header('Authorization')[0] !== 'Bearer cf-customer-token-0123456789') {
                return Http::response(['success' => false, 'errors' => [['message' => 'Invalid API Token']]], 401);
            }

            return match (true) {
                $path === '/user/tokens/verify' => $ok(['id' => 'tok12345abcdef', 'status' => 'active']),
                $path === '/zones' && $request->method() === 'GET' => $ok([['id' => 'zuk', 'name' => 'example.co.uk', 'status' => 'active']], ['result_info' => ['total_pages' => 1]]),
                $path === '/zones/zuk/dns_records' && $request->method() === 'GET' => $ok(array_values($this->remote), ['result_info' => ['total_pages' => 1]]),
                $path === '/zones/zuk/dns_records' && $request->method() === 'POST' => $ok($this->remote[$id = 'n'.count($this->writes)] = ($this->writes[] = ['id' => 'n'.count($this->writes)] + $request->data() + ['ttl' => 1, 'proxied' => false])),
                default => (function () use ($request) {
                    $this->writes[] = [$request->method(), $request->url()];

                    return Http::response(['success' => false, 'errors' => [['message' => 'refused in test']]], 400);
                })(),
            };
        });
    }

    private function project($org): array
    {
        return app(Tenant::class)->as($org->id, function () use ($org) {
            $p = Project::create(['name' => 'Shop']);
            $server = $this->server($org);
            $a = Application::create(['name' => 'web', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'size' => 'small', 'port' => 80,
                'project_id' => $p->id, 'server_id' => $server->id, 'status' => 'running']);

            return [$p, $a];
        });
    }

    public function test_public_suffix_list_decides_apex_and_subdomain(): void
    {
        $this->assertFalse(DomainName::isSubdomain('example.co.uk'));
        $this->assertTrue(DomainName::isSubdomain('erp.example.co.uk'));
        $this->assertFalse(DomainName::isSubdomain('example.com'));
        $this->assertTrue(DomainName::isSubdomain('shop.example.com'));
        $this->assertSame('example.com.au', DomainName::registrable('a.b.example.com.au'));

        [, $org, $token] = $this->org();
        [$p, $a] = $this->project($org);
        $base = "/api/v1/app/projects/{$p->id}/dns";
        $apex = $this->api($token)->postJson("$base/domains", ['kind' => 'custom', 'name' => 'example.co.uk', 'app_id' => $a->id])->assertCreated()->json('data');
        $this->assertSame([['A', '203.0.113.50']], collect($apex['records'])->where('purpose', 'routing')->map(fn ($r) => [$r['type'], $r['value']])->values()->all());
        $this->assertFalse($apex['subdomain']);
        $sub = $this->api($token)->postJson("$base/domains", ['kind' => 'custom', 'name' => 'erp.example.co.uk', 'app_id' => $a->id])->assertCreated()->json('data');
        $this->assertSame([['CNAME', 'ingress.livezencloud.test']], collect($sub['records'])->where('purpose', 'routing')->map(fn ($r) => [$r['type'], $r['value']])->values()->all());
        $this->assertTrue($sub['subdomain']);
    }

    public function test_customer_connects_cloudflare_picks_a_zone_and_nothing_of_theirs_is_overwritten(): void
    {
        $this->fakeCloudflare();
        [, $org, $token] = $this->org();
        [$p, $a] = $this->project($org);
        [$dev, $devToken] = $this->member($org, 'developer');
        $base = "/api/v1/app/projects/{$p->id}/dns";

        // A member needs the "provider" permission.
        $this->api($devToken)->postJson("$base/providers", ['token' => 'cf-customer-token-0123456789'])->assertForbidden();
        // A bad token is refused by Cloudflare and nothing is stored.
        $this->api($token)->postJson("$base/providers", ['token' => 'cf-wrong-token-000000000000'])->assertStatus(422)->assertJsonPath('error.fields.token.0', 'Cloudflare: Invalid API Token');
        $this->assertSame(0, DnsProviderAccount::allTenants()->count());

        $acc = $this->api($token)->postJson("$base/providers", ['token' => 'cf-customer-token-0123456789'])->assertCreated()->json('data');
        $this->assertSame('example.co.uk', $acc['zones'][0]['name']);
        $row = DnsProviderAccount::allTenants()->find($acc['id']);
        $this->assertSame([$org->id, 'customer'], [$row->organization_id, $row->source]);
        $this->assertStringNotContainsString('cf-customer-token', (string) DB::table('dns_providers')->where('id', $acc['id'])->value('credentials'), 'stored encrypted');
        $list = $this->api($token)->getJson("$base/providers")->assertOk()->json('data');
        $this->assertStringNotContainsString('cf-customer-token', json_encode($list));

        // Another customer sees none of it.
        [, $org2, $token2] = $this->org();
        [$p2] = $this->project($org2);
        $this->assertSame([], $this->api($token2)->getJson("/api/v1/app/projects/{$p2->id}/dns/providers")->json('data'));
        $this->api($token2)->deleteJson("/api/v1/app/projects/{$p2->id}/dns/providers/{$acc['id']}")->assertNotFound();

        $d = $this->api($token)->postJson("$base/domains", ['kind' => 'custom', 'name' => 'example.co.uk', 'app_id' => $a->id])->assertCreated()->json('data');
        $this->api($token)->postJson("$base/domains/{$d['id']}/zone", ['provider_id' => $acc['id'], 'zone_id' => 'nope'])->assertStatus(422);
        $this->api($token)->postJson("$base/domains/{$d['id']}/zone", ['provider_id' => $acc['id'], 'zone_id' => 'zuk'])->assertOk();

        $published = collect($this->api($token)->postJson("$base/domains/{$d['id']}/publish")->assertOk()->json('data.records'))->keyBy('type');
        // The apex already serves a website (A 192.0.2.1): our A record is refused, not added beside it.
        $this->assertSame('conflict', $published['A']['status']);
        $this->assertSame('created', $published['TXT']['status']);
        $this->assertSame(['TXT'], array_values(array_unique(array_column($this->writes, 'type'))), 'only new TXT records were written');
        $this->assertSame(['192.0.2.1', 'mx.example.co.uk', 'v=DMARC1; p=none'], [$this->remote['r1']['content'], $this->remote['r2']['content'], $this->remote['r3']['content']]);

        $records = collect($this->api($token)->getJson("$base/domains/{$d['id']}/zone-records")->assertOk()->json('data.records'));
        $this->assertTrue($records->firstWhere('type', 'TXT')['managed']);
        $this->assertFalse($records->firstWhere('type', 'MX')['managed']);
        $this->assertFalse($records->firstWhere('type', 'A')['managed']);

        // Disconnect: the panel forgets the token and its zone copy; nothing is deleted at Cloudflare.
        $this->api($token)->deleteJson("$base/providers/{$acc['id']}")->assertNoContent();
        $this->assertSame(0, DnsProviderAccount::allTenants()->count());
        $this->assertCount(0, array_filter($this->writes, fn ($w) => ($w[0] ?? null) === 'DELETE'));
        $this->assertArrayHasKey('r1', $this->remote);
        $actions = collect($this->api($token)->getJson("$base/history")->json('data'))->pluck('action');
        $this->assertTrue($actions->contains('dns.provider_connected') && $actions->contains('dns.provider_disconnected') && $actions->contains('domain.zone_attached'));
    }

    public function test_staff_connected_provider_cannot_be_disconnected_by_the_customer(): void
    {
        [, $org, $token] = $this->org();
        [$p] = $this->project($org);
        $acc = app(Tenant::class)->as($org->id, fn () => DnsProviderAccount::create(['name' => 'Staff CF', 'type' => 'cloudflare', 'credentials' => ['token' => 'x']]));
        $this->api($token)->deleteJson("/api/v1/app/projects/{$p->id}/dns/providers/{$acc->id}")->assertForbidden();
    }

    public function test_scheduled_recheck_notifies_once_per_state_change(): void
    {
        [$owner, $org] = $this->org();
        [$p, $a] = $this->project($org);
        [$dev] = $this->member($org, 'developer');
        [$other] = $this->member($org, 'developer');
        app(Tenant::class)->as($org->id, fn () => \App\Models\ProjectDomainGrant::create(['project_id' => $p->id, 'user_id' => $dev->id, 'permissions' => ['add']]));
        $domain = app(Tenant::class)->as($org->id, fn () => app(DomainService::class)->create(['name' => 'shop.example.com', 'application_id' => $a->id, 'project_id' => $p->id, 'dns_method' => 'manual']));
        $txt = app(Tenant::class)->as($org->id, fn () => app(DomainService::class)->ownershipValue($domain));
        $dns = app(DnsResolver::class);
        $issuer = app(\App\Contracts\CertificateIssuer::class);

        Artisan::call('domains:check-dns');
        $this->assertSame([], $this->sent, 'the first check only records the state');

        $dns->set('_livezen-verify.shop.example.com', 'TXT', [$txt]);
        $dns->set('shop.example.com', 'CNAME', ['ingress.livezencloud.test.']);
        Artisan::call('domains:check-dns');
        $changes = collect($this->sent)->pluck(2)->unique()->values()->all();
        $this->assertContains('Ownership verified', $changes);
        $this->assertContains('DNS verified', $changes);
        $recipients = collect($this->sent)->pluck(1)->unique()->sort()->values()->all();
        $this->assertSame(collect([$owner->email, $dev->email])->sort()->values()->all(), $recipients, 'owner and granted members; not the member without a grant');
        $this->assertNotContains($other->email, $recipients);
        $this->assertNotContains('domain_connected', collect($this->sent)->pluck(0)->all());

        // Unchanged state: nothing more (a certificate issued in between notifies once, then stops).
        foreach ([1, 2] as $_) {
            $count = count($this->sent);
            Domain::allTenants()->whereKey($domain->id)->update(['dns_checked_at' => now()->subHour()]);
            Artisan::call('domains:check-dns');
        }
        $this->assertSame($count, count($this->sent));

        // Broke again: the CNAME is gone.
        $before = count($this->sent);
        $dns->set('shop.example.com', 'CNAME', []);
        Domain::allTenants()->whereKey($domain->id)->update(['dns_checked_at' => now()->subHour()]);
        Artisan::call('domains:check-dns');
        $this->assertContains('DNS broke again', collect($this->sent)->slice($before)->pluck(2)->all());
        $this->assertTrue(AuditLog::where('action', 'domain.state_changed')->where('meta->event', 'dns_broken')->exists());

        // And again unchanged: no duplicate.
        $after = count($this->sent);
        Domain::allTenants()->whereKey($domain->id)->update(['dns_checked_at' => now()->subHour()]);
        Artisan::call('domains:check-dns');
        $this->assertSame($after, count($this->sent));
    }

    public function test_staff_list_project_domains_across_customers_and_run_validation(): void
    {
        [, , $staff] = $this->org('super_admin');
        [, $org, $token] = $this->org();
        [$p, $a] = $this->project($org);
        $d = $this->api($token)->postJson("/api/v1/app/projects/{$p->id}/dns/domains", ['kind' => 'custom', 'name' => 'shop.example.com', 'app_id' => $a->id])->assertCreated()->json('data');

        $rows = $this->api($staff)->getJson('/api/v1/project-domains')->assertOk()->json('data');
        $row = collect($rows)->firstWhere('id', $d['id']);
        $this->assertSame(['Shop', 'web', 'awaiting ownership'], [$row['project_name'], $row['app_name'], $row['status']]);
        $this->assertSame($org->name, $row['organization_name']);
        $this->assertCount(0, $this->api($staff)->getJson('/api/v1/project-domains?status=active')->json('data'));
        $this->assertCount(1, $this->api($staff)->getJson('/api/v1/project-domains?status=ownership')->json('data'));

        $v = $this->api($staff)->postJson("/api/v1/project-domains/{$d['id']}/validate")->assertOk()->json('data');
        $this->assertArrayHasKey('checks', $v);

        // A customer cannot use the staff view.
        $this->api($token)->getJson('/api/v1/project-domains')->assertForbidden();
    }

    public function test_owner_revokes_every_domain_permission_of_a_member_at_once(): void
    {
        [, $org, $ownerToken] = $this->org();
        [$p] = $this->project($org);
        [$dev, $devToken] = $this->member($org, 'developer');
        $base = "/api/v1/app/projects/{$p->id}/dns";
        $this->api($ownerToken)->patchJson("$base/permissions/{$dev->id}", ['permissions' => ['add', 'records', 'provider']])->assertOk();
        $this->api($devToken)->deleteJson("$base/permissions/{$dev->id}")->assertForbidden();

        $rows = $this->api($ownerToken)->deleteJson("$base/permissions/{$dev->id}")->assertOk()->json('data');
        $this->assertSame(['view'], collect($rows)->firstWhere('user_id', $dev->id)['permissions']);
        $this->api($devToken)->getJson($base)->assertJsonPath('data.permissions', ['view']);
        $log = AuditLog::where('action', 'domain.permissions_revoked')->first();
        $this->assertSame(['add', 'records', 'provider'], $log->meta['revoked']);
    }
}
