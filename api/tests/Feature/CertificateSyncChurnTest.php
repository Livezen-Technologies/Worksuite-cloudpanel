<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Certificate;
use App\Models\Domain;
use App\Models\Server;
use App\Services\TraefikConfigService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The two enterprise edges each hold their own Let's Encrypt certificate for a domain, seconds
 * apart in expiry. proxy:sync-certs used to replace the active row whenever the reporting edge's
 * expiry differed, which wrote a new row every sync (≈460 rows for pmm.worksuite.lk). One row now
 * covers both edges until one of them actually renews, and proxy:prune-certs clears the backlog.
 */
class CertificateSyncChurnTest extends TestCase
{
    private Server $panel;

    private Server $e1;

    private Server $e2;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.drivers.certificates' => 'traefik']);
        [, $org, $token] = $this->org();
        $this->panel = $this->server($org, ['name' => 'panel-01', 'tags' => ['ingress']]);
        $node = $this->server($org, ['name' => 'node-05', 'ip' => '203.0.113.5']);
        $this->e1 = $this->server($org, ['name' => 'frontdoor-01', 'tags' => ['ingress', 'enterprise-edge']]);
        $this->e2 = $this->server($org, ['name' => 'frontdoor-02', 'tags' => ['ingress', 'enterprise-edge']]);
        $id = $this->api($token)->postJson('/api/v1/applications', ['name' => 'ent-pmm', 'source_type' => 'image', 'image' => 'ghcr.io/acme/x:1', 'server_id' => $node->id, 'port' => 8080])->assertCreated()->json('data.id');
        $app = Application::allTenants()->findOrFail($id);
        $app->update(['source' => array_merge((array) $app->source, ['enterprise_deployment_id' => 'dep-pmm'])]);
        $this->api($token)->postJson('/api/v1/domains', ['name' => 'pmm.acme.test', 'application_id' => $id, 'target_port' => 8080])->assertCreated();
        Domain::allTenants()->where('name', 'pmm.acme.test')->update(['dns_status' => 'verified']);
        $this->domain = Domain::allTenants()->where('name', 'pmm.acme.test')->firstOrFail();
        Certificate::allTenants()->where('domain_id', $this->domain->id)->delete();
    }

    private function cert(Carbon $notAfter): array
    {
        return ['resolver' => 'le-dns-cloudflare', 'domain' => 'pmm.acme.test', 'sans' => [], 'issuer' => 'R11',
            'not_before' => $notAfter->copy()->subDays(90)->toIso8601ZuluString(), 'not_after' => $notAfter->toIso8601ZuluString()];
    }

    private function report(Server $s, Carbon $notAfter): void
    {
        app(TraefikConfigService::class)->applyCertificates([$this->cert($notAfter)], $s->id);
    }

    private function rows()
    {
        return Certificate::allTenants()->where('domain_id', $this->domain->id)->orderBy('created_at')->get();
    }

    public function test_two_edges_with_different_certificates_keep_one_active_row(): void
    {
        $a = now()->addDays(85)->startOfSecond();
        $b = $a->copy()->addSeconds(19);
        Certificate::allTenants()->create(['organization_id' => $this->domain->organization_id, 'domain_id' => $this->domain->id, 'status' => 'issuing', 'names' => ['pmm.acme.test']]);
        for ($i = 0; $i < 6; $i++) {
            $this->report($this->e1, $a);
            $this->report($this->e2, $b);
        }
        $rows = $this->rows();
        $this->assertCount(1, $rows, 'no row per sync');
        $active = $rows->first();
        $this->assertSame('active', $active->status);
        $this->assertTrue($active->expires_at->equalTo($a), 'the earliest expiry of the two');
        $this->assertEqualsCanonicalizing([$this->e1->id, $this->e2->id], array_keys($active->observations));
        $this->assertSame($b->toIso8601ZuluString(), $active->observations[$this->e2->id]['not_after']);
        $this->assertNotSame($active->observations[$this->e1->id]['fingerprint'], $active->observations[$this->e2->id]['fingerprint']);
        $this->assertSame('active', $this->domain->fresh()->https_status);

        // frontdoor-01 renews: one new row. Its expiry stays frontdoor-02's until that renews too.
        $a2 = $a->copy()->addDays(60);
        for ($i = 0; $i < 4; $i++) {
            $this->report($this->e1, $a2);
            $this->report($this->e2, $b);
        }
        $rows = $this->rows();
        $this->assertSame(['active' => 1, 'replaced' => 1], $rows->countBy('status')->sortKeys()->all());
        $this->assertTrue($rows->firstWhere('status', 'active')->expires_at->equalTo($b));

        $b2 = $a2->copy()->addMinutes(3);
        $this->report($this->e2, $b2);
        $this->report($this->e1, $a2);
        $this->report($this->e2, $b2);
        $rows = $this->rows();
        $this->assertSame(['active' => 1, 'replaced' => 2], $rows->countBy('status')->sortKeys()->all());
        $this->assertTrue($rows->firstWhere('status', 'active')->expires_at->equalTo($a2));
    }

    public function test_an_old_copy_on_an_ingress_that_no_longer_routes_the_domain_is_ignored(): void
    {
        $a = now()->addDays(85)->startOfSecond();
        $this->report($this->e1, $a);
        // panel-01 still has the certificate it held before the domain moved to the edges.
        $this->report($this->panel, $a->copy()->subDays(20));
        $this->report($this->panel, $a->copy()->subDays(20));
        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertTrue($rows->first()->expires_at->equalTo($a));
        $this->assertSame([$this->e1->id], array_keys($rows->first()->observations));
    }

    public function test_a_row_from_before_the_upgrade_is_adopted_not_replaced(): void
    {
        $a = now()->addDays(85)->startOfSecond();
        Certificate::allTenants()->create(['organization_id' => $this->domain->organization_id, 'domain_id' => $this->domain->id, 'status' => 'active',
            'names' => ['pmm.acme.test'], 'expires_at' => $a->copy()->addSeconds(19), 'issued_at' => $a->copy()->subDays(90)]);
        $this->report($this->e1, $a);
        $this->report($this->e2, $a->copy()->addSeconds(19));
        $this->assertCount(1, $this->rows());
        $this->assertTrue($this->rows()->first()->expires_at->equalTo($a));

        // A quiet edge stops counting once its observation is stale.
        $this->travel(4)->days();
        $this->report($this->e2, $a->copy()->addSeconds(19));
        $row = $this->rows()->first();
        $this->assertCount(1, $this->rows());
        $this->assertSame([$this->e2->id], array_keys($row->observations));
    }

    public function test_prune_certs_keeps_one_row_per_renewal_and_is_idempotent(): void
    {
        $org = $this->domain->organization_id;
        $old = now()->addDays(20)->startOfSecond();
        $cur = now()->addDays(85)->startOfSecond();
        $at = now()->subDays(30);
        $make = function (string $status, Carbon $exp) use ($org, &$at) {
            $at = $at->copy()->addMinutes(7);
            $c = Certificate::allTenants()->create(['organization_id' => $org, 'domain_id' => $this->domain->id, 'status' => $status, 'names' => ['pmm.acme.test'], 'expires_at' => $exp]);
            $c->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

            return $c;
        };
        $firstOld = $make('replaced', $old);
        $make('replaced', $old->copy()->addSeconds(30));
        $firstCur = $make('replaced', $cur);
        for ($i = 0; $i < 40; $i++) {
            $make('replaced', $i % 2 ? $cur : $cur->copy()->addSeconds(19));
        }
        $active = $make('active', $cur);

        $this->artisan('proxy:prune-certs', ['--dry-run' => true])
            ->expectsOutput('pmm.acme.test: 44 rows, keep 3, would delete 41')
            ->expectsOutput('Dry run: would delete 41 superseded certificate rows across 1 domains.')
            ->assertSuccessful();
        $this->assertCount(44, $this->rows(), 'a dry run changes nothing');

        $this->artisan('proxy:prune-certs')->expectsOutput('pmm.acme.test: 44 rows, keep 3, deleted 41')->assertSuccessful();
        $this->assertEqualsCanonicalizing([$firstOld->id, $firstCur->id, $active->id], $this->rows()->pluck('id')->all(), 'the first sighting of each renewal and the active row');
        $this->artisan('proxy:prune-certs')->expectsOutput('Deleted 0 superseded certificate rows across 0 domains.')->assertSuccessful();

        $this->artisan('proxy:prune-certs', ['--keep' => 1])->expectsOutput('pmm.acme.test: 3 rows, keep 2, deleted 1')->assertSuccessful();
        $this->assertEqualsCanonicalizing([$firstCur->id, $active->id], $this->rows()->pluck('id')->all());
    }
}
