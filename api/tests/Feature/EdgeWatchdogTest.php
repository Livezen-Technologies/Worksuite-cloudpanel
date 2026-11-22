<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Models\Server;
use App\Services\AuditLogger;
use App\Services\DnsZoneService;
use App\Services\DomainService;
use App\Services\EdgeWatchdogService;
use App\Services\NotificationService;
use App\Support\Tenant;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** XNODE-4: the Cloudflare watchdog drops a failing edge's A records and restores them. */
class EdgeWatchdogTest extends TestCase
{
    private array $remote = [];

    private int $next = 1;

    /** edge name => probe result */
    public array $up = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(function (Request $request) {
            $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/client/v4'));
            $ok = fn ($r, $extra = []) => Http::response(['success' => true, 'errors' => [], 'result' => $r] + $extra);
            if (preg_match('#^/zones/z1/dns_records/(\w+)$#', $path, $m) && $request->method() === 'DELETE') {
                unset($this->remote[$m[1]]);

                return $ok(['id' => $m[1]]);
            }

            return match (true) {
                $path === '/user/tokens/verify' => $ok(['id' => 'abcdef1234567890', 'status' => 'active']),
                $path === '/zones' && $request->method() === 'GET' => $ok([]),
                $path === '/zones' => $ok(['id' => 'z1', 'status' => 'active', 'name_servers' => ['ada.ns.cloudflare.com']]),
                $path === '/zones/z1/dns_records' && $request->method() === 'GET' => $ok(array_values($this->remote), ['result_info' => ['total_pages' => 1]]),
                $path === '/zones/z1/dns_records' => $ok($this->remote[$id = 'r'.$this->next++] = ['id' => $id] + $request->data()),
                default => Http::response(['success' => false, 'errors' => [['message' => 'not found']]], 404),
            };
        });
        $test = $this;
        $this->app->instance(EdgeWatchdogService::class, new class(app(DnsZoneService::class), app(NotificationService::class), app(AuditLogger::class), app(DomainService::class), $test) extends EdgeWatchdogService
        {
            public function __construct($z, $n, $a, $d, private $test)
            {
                parent::__construct($z, $n, $a, $d);
            }

            public function probe(Server $edge, string $host): bool
            {
                return $this->test->up[$edge->name] ?? true;
            }
        });
    }

    /** [ips(name), edges] */
    private function setUpEdges(int $count = 2): array
    {
        config(['platform.edge.watchdog.enabled' => true]);
        [, $org, $token] = $this->org();
        $edges = [];
        for ($i = 1; $i <= $count; $i++) {
            $edges[] = $this->server($org, ['name' => "frontdoor-0{$i}", 'ip' => "198.51.100.{$i}", 'tags' => ['ingress', 'enterprise-edge']]);
            $this->up["frontdoor-0{$i}"] = true;
        }
        $node = $this->server($org, ['name' => 'node-05', 'ip' => '203.0.113.5']);
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'ent', 'kind' => 'admin', 'source_type' => 'image', 'image' => 'nginx', 'server_id' => $node->id, 'port' => 80, 'source' => ['enterprise_deployment_id' => 'dep-1']]));
        $provider = $this->api($token)->postJson('/api/v1/dns/providers', ['name' => 'CF', 'type' => 'cloudflare', 'token' => 't'])->assertCreated()->json('data');
        $zone = $this->api($token)->postJson('/api/v1/dns/zones', ['dns_provider_id' => $provider['id'], 'name' => 'example.com'])->assertCreated()->json('data');
        foreach ($edges as $e) {
            $this->api($token)->postJson("/api/v1/dns/zones/{$zone['id']}/records", ['type' => 'A', 'name' => 'ent', 'content' => $e->ip, 'proxied' => true])->assertCreated();
        }
        app(Tenant::class)->as($org->id, fn () => Domain::create(['name' => 'ent.example.com', 'application_id' => $app->id, 'dns_status' => 'verified']));
        app(Tenant::class)->set(null);
        $ips = fn () => DnsRecord::allTenants()->where('name', 'ent.example.com')->where('type', 'A')->orderBy('content')->pluck('content')->all();

        return [$ips, $edges];
    }

    public function test_disabled_by_default(): void
    {
        $this->assertFalse(EdgeWatchdogService::enabled());
        $this->artisan('edge:watchdog')->expectsOutputToContain('disabled')->assertSuccessful();
    }

    public function test_removes_after_n_failures_and_restores_after_m_successes(): void
    {
        [$ips] = $this->setUpEdges();
        $both = ['198.51.100.1', '198.51.100.2'];
        $this->assertSame($both, $ips());

        $this->up['frontdoor-01'] = false;
        $this->artisan('edge:watchdog')->assertSuccessful();
        $this->assertSame($both, $ips(), 'one failure is not enough (fail_after=2)');
        $this->artisan('edge:watchdog')->assertSuccessful();
        $this->assertSame(['198.51.100.2'], $ips(), 'removed after the second consecutive failure');
        $this->artisan('edge:watchdog')->assertSuccessful();
        $this->assertSame(1, Alert::allTenants()->where('title', 'Edge server frontdoor-01 removed from enterprise DNS')->count(), 'alerted once');
        $this->assertSame(1, AuditLog::query()->where('action', 'edge.watchdog.removed')->count());

        $this->up['frontdoor-01'] = true;
        $this->artisan('edge:watchdog')->assertSuccessful();
        $this->assertSame(['198.51.100.2'], $ips(), 'one success is not enough (restore_after=2)');
        $this->artisan('edge:watchdog')->assertSuccessful();
        $this->assertSame($both, $ips(), 'restored');
        $this->assertTrue((bool) DnsRecord::allTenants()->where('content', '198.51.100.1')->value('proxied'), 'restored proxied');
        $this->assertSame(0, Alert::allTenants()->where('title', 'Edge server frontdoor-01 removed from enterprise DNS')->where('status', 'open')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'edge.watchdog.restored')->count());
    }

    public function test_a_flapping_edge_never_reaches_the_threshold(): void
    {
        [$ips] = $this->setUpEdges();
        foreach ([false, true, false, true] as $up) {
            $this->up['frontdoor-01'] = $up;
            $this->artisan('edge:watchdog')->assertSuccessful();
        }
        $this->assertCount(2, $ips());
    }

    public function test_never_removes_the_last_record_and_alerts_when_all_fail(): void
    {
        [$ips] = $this->setUpEdges();
        $this->up = ['frontdoor-01' => false, 'frontdoor-02' => false];
        foreach (range(1, 4) as $_) {
            $this->artisan('edge:watchdog')->assertSuccessful();
        }
        $this->assertCount(2, $ips(), 'all edges failing: DNS is left alone');
        $this->assertSame(1, Alert::allTenants()->where('title', 'Every enterprise edge is failing its health probe')->count());

        // frontdoor-02 recovers: frontdoor-01 (already past its threshold) goes, 02 stays.
        $this->up['frontdoor-02'] = true;
        $this->artisan('edge:watchdog')->assertSuccessful();
        $this->assertSame(['198.51.100.2'], $ips());
        $this->assertSame(0, Alert::allTenants()->where('title', 'Every enterprise edge is failing its health probe')->where('status', 'open')->count());

        // Now 02 fails too: its record is the last one and is kept.
        $this->up['frontdoor-02'] = false;
        $this->up['frontdoor-01'] = false;
        foreach (range(1, 3) as $_) {
            $this->artisan('edge:watchdog')->assertSuccessful();
        }
        $this->assertSame(['198.51.100.2'], $ips());
    }

    public function test_with_three_edges_the_last_healthy_record_survives(): void
    {
        [$ips] = $this->setUpEdges(3);
        $this->up['frontdoor-01'] = false;
        $this->up['frontdoor-02'] = false;
        $this->artisan('edge:watchdog')->assertSuccessful();
        $this->artisan('edge:watchdog')->assertSuccessful();
        $this->assertSame(['198.51.100.3'], $ips());
    }

    public function test_the_probe_sends_sni_to_the_edge_ip_and_treats_5xx_as_down(): void
    {
        [, $org] = $this->org();
        $edge = $this->server($org, ['name' => 'e', 'ip' => '198.51.100.9']);
        $svc = new EdgeWatchdogService(app(DnsZoneService::class), app(NotificationService::class), app(AuditLogger::class), app(DomainService::class));
        Http::swap(new Factory);
        Http::fake(['https://ent.example.com/*' => Http::sequence()->push('nf', 404)->push('bad', 502)]);
        $this->assertTrue($svc->probe($edge, 'ent.example.com'), '404 = Traefik answered');
        $this->assertFalse($svc->probe($edge, 'ent.example.com'), '502 = down');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://ent.example.com/');
    }

    public function test_enterprise_edge_records_are_published_proxied(): void
    {
        [, $org, $token] = $this->org();
        $this->server($org, ['name' => 'frontdoor-01', 'ip' => '198.51.100.1', 'tags' => ['ingress', 'enterprise-edge']]);
        $node = $this->server($org, ['name' => 'node-05', 'ip' => '203.0.113.5']);
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'ent', 'kind' => 'admin', 'source_type' => 'image', 'image' => 'nginx', 'server_id' => $node->id, 'port' => 80, 'source' => ['enterprise_deployment_id' => 'dep-1']]));
        $plain = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'plain', 'kind' => 'admin', 'source_type' => 'image', 'image' => 'nginx', 'server_id' => $node->id, 'port' => 80]));
        $svc = app(DomainService::class);
        $ent = app(Tenant::class)->as($org->id, fn () => Domain::create(['name' => 'ent.example.com', 'application_id' => $app->id]));
        $p = app(Tenant::class)->as($org->id, fn () => Domain::create(['name' => 'plain.example.com', 'application_id' => $plain->id]));
        $this->assertTrue(collect($svc->expectedRecords($ent))->where('type', 'A')->every(fn ($r) => ! empty($r['proxied'])));
        $this->assertTrue(collect($svc->expectedRecords($p))->where('type', 'A')->every(fn ($r) => empty($r['proxied'])));
    }
}
