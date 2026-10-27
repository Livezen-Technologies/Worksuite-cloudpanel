<?php

namespace App\Contracts;

use App\Models\Domain;

interface CertificateIssuer
{
    /** @return array{issuer: string, issued_at: \DateTimeInterface, expires_at: \DateTimeInterface, names: string[]} */
    public function issue(Domain $domain, array $names, string $challenge): array;
}
