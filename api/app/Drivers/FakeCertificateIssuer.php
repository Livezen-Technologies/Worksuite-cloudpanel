<?php

namespace App\Drivers;

use App\Contracts\CertificateIssuer;
use App\Models\Domain;

/** Stands in for Let's Encrypt (ACME) until real credentials are configured. */
class FakeCertificateIssuer implements CertificateIssuer
{
    public function issue(Domain $domain, array $names, string $challenge): array
    {
        return ['issuer' => 'fake-letsencrypt', 'issued_at' => now(), 'expires_at' => now()->addDays(90), 'names' => $names];
    }
}
