<?php

namespace Tests\Feature;

use App\Contracts\CertificateIssuer;
use App\Models\Certificate;
use App\Models\Domain;
use App\Support\Tenant;
use Tests\TestCase;

class CertificateRenewalTest extends TestCase
{
    private function domainWithCert($org, int $daysLeft, string $dns = 'verified'): Domain
    {
        return app(Tenant::class)->as($org->id, function () use ($daysLeft, $dns) {
            $d = Domain::create(['name' => 'r'.uniqid().'.example.com', 'dns_status' => $dns, 'https_status' => 'active', 'challenge' => 'http-01']);
            Certificate::create(['domain_id' => $d->id, 'issuer' => 'old', 'status' => 'active', 'names' => [$d->name], 'issued_at' => now()->subDays(80), 'expires_at' => now()->addDays($daysLeft)]);

            return $d;
        });
    }

    public function test_expiring_certificates_are_renewed_and_healthy_ones_left_alone(): void
    {
        [, $org] = $this->org();
        $expiring = $this->domainWithCert($org, 5);
        $healthy = $this->domainWithCert($org, 60);
        $notPointed = $this->domainWithCert($org, 5, 'pending');

        $this->artisan('certificates:renew')->expectsOutput('Certificates renewed: 1, failed: 0')->assertSuccessful();

        $active = fn (Domain $d) => Certificate::allTenants()->where('domain_id', $d->id)->where('status', 'active')->get();
        $this->assertCount(1, $active($expiring));
        $this->assertSame('fake-letsencrypt', $active($expiring)->first()->issuer);
        $this->assertSame('old', $active($healthy)->first()->issuer);
        $this->assertSame('old', $active($notPointed)->first()->issuer);
        $this->assertDatabaseHas('audit_logs', ['action' => 'certificate.renewed', 'resource_id' => $expiring->id]);
    }

    public function test_a_failed_renewal_keeps_https_active_while_the_old_certificate_is_valid(): void
    {
        [, $org] = $this->org();
        $this->app->instance(CertificateIssuer::class, new class implements CertificateIssuer {
            public function issue(Domain $domain, array $names, string $challenge): array { throw new \RuntimeException('rate limited'); }
        });
        $d = $this->domainWithCert($org, 3);

        $this->artisan('certificates:renew')->expectsOutput('Certificates renewed: 0, failed: 1')->assertSuccessful();

        $this->assertSame('active', Domain::allTenants()->find($d->id)->https_status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'certificate.renewal_failed', 'resource_id' => $d->id]);
    }
}
