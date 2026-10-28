<?php

namespace App\Drivers;

use App\Contracts\CertificateIssuer;
use App\Models\Domain;
use App\Services\TraefikConfigService;

/**
 * CERT_DRIVER=traefik: Traefik requests the certificate (ACME) once its router exists. "Issuing"
 * means pushing the router with its certResolver; the real expiry arrives later from acme.json.
 */
class TraefikCertificateIssuer implements CertificateIssuer
{
    public function __construct(private TraefikConfigService $traefik) {}

    public function issue(Domain $domain, array $names, string $challenge): array
    {
        if ($challenge === 'dns-01' && ! $domain->dns_secret_id && ! config('platform.traefik.allow_static_dns_credentials', true)) {
            throw new \RuntimeException('DNS-01 needs a DNS provider token for '.$domain->name.'.');
        }
        $this->traefik->push();
        $this->traefik->syncCertificates();

        return ['issuer' => 'letsencrypt', 'pending' => true, 'issued_at' => null, 'expires_at' => null, 'names' => $names, 'resolver' => $this->traefik->resolverFor($domain)];
    }
}
