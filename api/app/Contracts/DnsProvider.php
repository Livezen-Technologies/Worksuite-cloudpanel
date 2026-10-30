<?php

namespace App\Contracts;

/**
 * An authoritative DNS provider (SRS 4.18). Records are plain arrays:
 * ['id' => remote id, 'type', 'name' (FQDN), 'content', 'ttl' (1 = auto),
 *  'priority' (MX/SRV or null), 'proxied' (Cloudflare only)].
 */
interface DnsProvider
{
    /** Throws when the credentials cannot manage DNS. Returns a label for the account. */
    public function verify(): string;

    /** @return array{id: string, status: string, nameservers: string[]} */
    public function createZone(string $name): array;

    /** @return array{id: string, status: string, nameservers: string[]}|null */
    public function findZone(string $name): ?array;

    /** @return array{status: string, nameservers: string[]} */
    public function zoneStatus(string $zoneId): array;

    public function deleteZone(string $zoneId): void;

    /** @return list<array<string, mixed>> */
    public function records(string $zoneId): array;

    /** @return array<string, mixed> the created record */
    public function createRecord(string $zoneId, array $record): array;

    /** @return array<string, mixed> the updated record */
    public function updateRecord(string $zoneId, string $recordId, array $record): array;

    public function deleteRecord(string $zoneId, string $recordId): void;
}
