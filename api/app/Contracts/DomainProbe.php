<?php

namespace App\Contracts;

/** PDNS: the live checks behind Validate DNS that go out to the network. */
interface DomainProbe
{
    /**
     * Asks each configured public resolver. Returns [resolver name => values|null]; null means
     * that resolver could not be asked (timeout, error), which is reported, not counted as missing.
     *
     * @return array<string, string[]|null>
     */
    public function propagation(string $name, string $type): array;

    /**
     * The certificate served for $host on port 443 (SNI = $host).
     *
     * @return array{ok: bool, names?: string[], issuer?: ?string, valid_from?: ?string, expires_at?: ?string, error?: string}
     */
    public function certificate(string $host): array;

    /**
     * GET https://$host/ without following redirects.
     *
     * @return array{ok: bool, status?: int, server?: ?string, error?: string}
     */
    public function https(string $host): array;
}
