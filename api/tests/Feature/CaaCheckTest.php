<?php

namespace Tests\Feature;

use App\Contracts\DnsResolver;
use App\Models\Certificate;
use App\Models\Domain;
use App\Services\DomainService;
use App\Support\Tenant;
use Tests\TestCase;

/** A CAA record that leaves out Let's Encrypt stops issuance with a clear reason. */
class CaaCheckTest extends TestCase
{
    private function domain($org, string $name, bool $wildcard = false): Domain
    {
        return app(Tenant::class)->as($org->id, fn () => Domain::create(['name' => $name, 'dns_status' => 'verified', 'https_status' => 'pending', 'challenge' => 'http-01', 'wildcard' => $wildcard]));
    }

    public function test_caa_on_a_parent_that_excludes_letsencrypt_blocks_issuance_without_piling_up_rows(): void
    {
        [, $org] = $this->org();
        $dns = app(DnsResolver::class);
        $dns->set('example.com', 'CAA', ['0 issue "digicert.com"', '0 iodef "mailto:sec@example.com"']);
        $d = $this->domain($org, 'shop.example.com');
        $svc = app(DomainService::class);

        $cert = app(Tenant::class)->as($org->id, fn () => $svc->issueCertificate($d));
        $this->assertSame('failed', $cert->status);
        $this->assertStringContainsString('only allows digicert.com', $cert->last_error);
        $this->assertStringContainsString('example.com CAA 0 issue "letsencrypt.org"', $cert->last_error);
        $this->assertSame('failed', $d->fresh()->https_status);
        app(Tenant::class)->as($org->id, fn () => $svc->issueCertificate($d->fresh()));
        $this->assertSame(1, Certificate::allTenants()->where('domain_id', $d->id)->count(), 'repeat checks reuse the failed row');

        // Allowing Let's Encrypt (closest record set wins) lets it through.
        $dns->set('shop.example.com', 'CAA', ['0 issue "letsencrypt.org"']);
        $ok = app(Tenant::class)->as($org->id, fn () => $svc->issueCertificate($d->fresh()));
        $this->assertSame('active', $ok->status);
    }

    public function test_no_caa_or_caa_without_issue_tags_allows_any_ca_and_wildcards_use_issuewild(): void
    {
        [, $org] = $this->org();
        $dns = app(DnsResolver::class);
        $svc = app(DomainService::class);
        $this->assertNull($svc->caaBlocks($this->domain($org, 'a.free.test')));
        $dns->set('iodef.test', 'CAA', ['0 iodef "mailto:x@iodef.test"']);
        $this->assertNull($svc->caaBlocks($this->domain($org, 'a.iodef.test')));

        $dns->set('wild.test', 'CAA', ['0 issue "letsencrypt.org"', '0 issuewild ";"']);
        $this->assertNull($svc->caaBlocks($this->domain($org, 'www.wild.test')));
        $this->assertStringContainsString('only allows no one', $svc->caaBlocks($this->domain($org, '*.wild.test', true)));
    }
}
