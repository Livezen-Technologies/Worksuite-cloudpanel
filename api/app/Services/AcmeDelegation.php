<?php

namespace App\Services;

use App\Models\DnsZone;
use App\Models\Domain;

/**
 * ACME-DLG: DNS-01 by CNAME delegation for customer-owned Enterprise domains on the edges.
 *
 * Every edge runs its own Traefik and orders its own certificate, so HTTP-01 fails there: Let's
 * Encrypt validates on whichever edge DNS sends it to, and the other edge holds the token. DNS-01
 * works from every edge, but the panel cannot write the customer's zone. Instead the customer adds
 * one CNAME, _acme-challenge.<name> → <label>.<subdomain>.<zone>, and lego (which follows
 * _acme-challenge CNAMEs by default) writes the TXT in a zone the panel manages.
 */
class AcmeDelegation
{
    /** The managed zone the challenges are delegated to, or null when it is not set up here. */
    public static function zone(): ?DnsZone
    {
        $name = strtolower(trim((string) config('platform.acme_delegation_zone'), '. '));
        if ($name === '') {
            return null;
        }
        $zone = DnsZone::allTenants()->with(['provider' => fn ($q) => $q->withoutGlobalScope('organization')])->where('name', $name)->first();

        return $zone && isset(TraefikConfigService::ZONE_PROVIDERS[$zone->provider?->type]) ? $zone : null;
    }

    /**
     * Whether the domain needs delegation: an Enterprise domain served by the edges, whose zone the
     * panel does not manage and for which no provider token was given. Says nothing of its challenge.
     */
    public static function eligible(Domain $domain): bool
    {
        if ($domain->platform_subdomain || $domain->dns_secret_id || ! TraefikConfigService::isEnterpriseDomain($domain)
            || TraefikConfigService::edgeServers()->pluck('ip')->filter()->isEmpty()) {
            return false;
        }
        $zone = self::zone();
        $name = ltrim($domain->name, '*.');

        return $zone && $name !== $zone->name && ! str_ends_with($name, '.'.$zone->name)
            && ! DnsZone::forDomain($domain->name, $domain->organization_id);
    }

    /** The delegation zone when the domain's DNS-01 challenge goes through it; null otherwise. */
    public static function zoneFor(Domain $domain): ?DnsZone
    {
        return $domain->challenge === 'dns-01' && self::eligible($domain) ? self::zone() : null;
    }

    /** _acme-challenge.<name>: where the customer puts the CNAME. */
    public static function recordName(Domain $domain): string
    {
        return '_acme-challenge.'.ltrim($domain->name, '*.');
    }

    /** <label>.<subdomain>.<zone>: stable per domain, and does not reveal the customer's name. */
    public static function target(Domain $domain): string
    {
        $sub = trim((string) config('platform.acme_delegation_subdomain', 'acme'), '. ');
        $zone = strtolower(trim((string) config('platform.acme_delegation_zone'), '. '));

        return substr(hash('sha256', 'acme-delegation|'.$domain->id), 0, 16).($sub !== '' ? '.'.$sub : '').'.'.$zone;
    }

    /** The record the customer must add, in the shape of DomainService::projectRecords(). */
    public static function record(Domain $domain): array
    {
        return ['type' => 'CNAME', 'name' => self::recordName($domain), 'value' => self::target($domain), 'purpose' => 'certificate'];
    }

    public static function isDelegationRecord(Domain $domain, array $record): bool
    {
        return $record['type'] === 'CNAME' && $record['name'] === self::recordName($domain) && self::zoneFor($domain) !== null;
    }
}
