<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Domain;
use App\Models\NotificationChannel;
use App\Models\Server;
use App\Support\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * XNODE-4: Cloudflare DNS watchdog for the enterprise edges (free plan, no Load Balancing).
 *
 * Every enterprise domain is published with one proxied A record per edge. Each run probes
 * every edge: the agent must be heartbeating AND an HTTPS request to the edge's own IP, with
 * a real enterprise hostname as SNI/Host, must get an answer below max_ok_status. The
 * heartbeat alone misses a dead Traefik; the probe alone misses nothing, but the heartbeat
 * spares a 5-second timeout when the whole box is gone. A 404 still counts as up (Traefik
 * answered); a 5xx does not.
 *
 * After fail_after consecutive failures the edge's A records are deleted from the managed
 * zones (DnsZoneService, i.e. the Cloudflare API); after restore_after consecutive passes
 * they are recreated. The last edge record of a domain is never deleted, and when every
 * edge fails nothing changes and one alert is raised (the backends are the likelier cause).
 */
class EdgeWatchdogService
{
    public function __construct(private DnsZoneService $zones, private NotificationService $notifications, private AuditLogger $audit, private DomainService $domains) {}

    public static function enabled(): bool
    {
        return (bool) config('platform.edge.watchdog.enabled');
    }

    /** @return array{probed: array<string,bool>, changes: list<string>, skipped?: string} */
    public function run(): array
    {
        if (! self::enabled()) {
            return ['probed' => [], 'changes' => [], 'skipped' => 'disabled (EDGE_WATCHDOG_ENABLED=false)'];
        }
        $edges = TraefikConfigService::edgeServers()->filter(fn (Server $s) => (bool) $s->ip)->values();
        $domains = $this->domains();
        if ($edges->count() < 2 || $domains->isEmpty()) {
            return ['probed' => [], 'changes' => [], 'skipped' => 'needs two edges and an enterprise domain in a managed zone'];
        }
        $host = (string) (config('platform.edge.watchdog.host') ?: $domains->first()['domain']->name);
        $failAfter = max(1, (int) config('platform.edge.watchdog.fail_after', 2));
        $restoreAfter = max(1, (int) config('platform.edge.watchdog.restore_after', 2));

        $probed = [];
        foreach ($edges as $e) {
            $probed[$e->id] = $e->isOnline() && $this->probe($e, $host);
            $st = $this->state($e);
            $st = $probed[$e->id] ? ['fail' => 0, 'ok' => $st['ok'] + 1, 'out' => $st['out']] : ['fail' => $st['fail'] + 1, 'ok' => 0, 'out' => $st['out']];
            Cache::forever($this->key($e), $st);
        }
        $changes = [];
        $anyUp = in_array(true, $probed, true);
        $this->allDownAlert($edges, ! $anyUp);
        foreach ($edges as $e) {
            $st = $this->state($e);
            if (! $probed[$e->id] && $st['fail'] >= $failAfter && ! $st['out'] && $anyUp) {
                $changes = array_merge($changes, $this->takeOut($e, $edges, $domains, $probed));
                Cache::forever($this->key($e), ['out' => true] + $st);
                $this->alert($e, true);
            } elseif ($probed[$e->id] && $st['ok'] >= $restoreAfter && $st['out']) {
                $changes = array_merge($changes, $this->putBack($e, $domains));
                Cache::forever($this->key($e), ['out' => false] + $st);
                $this->alert($e, false);
            }
        }

        return ['probed' => $probed, 'changes' => $changes];
    }

    /** One HTTPS request to the edge's IP, with $host as SNI and Host. */
    public function probe(Server $edge, string $host): bool
    {
        $path = '/'.ltrim((string) config('platform.edge.watchdog.path', '/'), '/');
        try {
            $r = Http::timeout((int) config('platform.edge.watchdog.timeout', 5))->withoutRedirecting()
                ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$edge->ip}"]]])
                ->get("https://{$host}{$path}");

            return $r->status() > 0 && $r->status() < (int) config('platform.edge.watchdog.max_ok_status', 500);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @return array{fail:int, ok:int, out:bool} */
    public function state(Server $edge): array
    {
        return (array) Cache::get($this->key($edge), []) + ['fail' => 0, 'ok' => 0, 'out' => false];
    }

    private function key(Server $edge): string
    {
        return 'edge-watchdog:'.$edge->id;
    }

    /** Verified enterprise domains in a managed zone: [domain, zone]. */
    private function domains(): Collection
    {
        return Domain::allTenants()->with('application')->where('dns_status', 'verified')->whereNotNull('application_id')->orderBy('name')->get()
            ->filter(fn (Domain $d) => TraefikConfigService::isEnterpriseDomain($d))
            ->map(fn (Domain $d) => ['domain' => $d, 'zone' => DnsZone::forDomain($d->name, $d->organization_id)])
            ->filter(fn ($x) => $x['zone']?->provider)->values();
    }

    private function records(DnsZone $zone, string $name, array $ips): Collection
    {
        return DnsRecord::allTenants()->where('dns_zone_id', $zone->id)->where('type', 'A')->where('name', $name)->whereIn('content', $ips)->get();
    }

    private function takeOut(Server $edge, Collection $edges, Collection $domains, array $probed): array
    {
        $changes = [];
        $ips = $edges->pluck('ip')->all();
        foreach ($domains as ['domain' => $d, 'zone' => $zone]) {
            $records = $this->records($zone, $d->name, $ips);
            $mine = $records->where('content', $edge->ip);
            // Never the last one: keep it unless another edge record (ideally a healthy one) stays.
            $others = $records->where('content', '!=', $edge->ip);
            if ($mine->isEmpty() || $others->isEmpty()) {
                if ($mine->isNotEmpty()) {
                    $changes[] = "kept {$d->name} {$edge->ip} (last record)";
                }

                continue;
            }
            app(Tenant::class)->as($d->organization_id, function () use ($zone, $mine, $d, $edge, &$changes) {
                foreach ($mine as $r) {
                    try {
                        $this->zones->deleteRecord($zone, $r);
                        $changes[] = "-{$d->name} {$edge->ip}";
                        $this->audit->log('edge.watchdog.removed', 'domains', $d->id, ['domain' => $d->name, 'edge' => $edge->name, 'ip' => $edge->ip], $d->organization_id, null, 'system');
                    } catch (\Throwable $e) {
                        Log::warning("Edge watchdog: could not remove {$d->name} {$edge->ip}: {$e->getMessage()}");
                    }
                }
            });
        }

        return $changes;
    }

    private function putBack(Server $edge, Collection $domains): array
    {
        $changes = [];
        foreach ($domains as ['domain' => $d, 'zone' => $zone]) {
            if ($this->records($zone, $d->name, [$edge->ip])->isNotEmpty()) {
                continue;
            }
            app(Tenant::class)->as($d->organization_id, function () use ($zone, $d, $edge, &$changes) {
                try {
                    $proxied = $this->domains->edgeProxied($d) && $zone->provider->driver()->supportsProxy();
                    $this->zones->createRecord($zone, ['type' => 'A', 'name' => $d->name, 'content' => $edge->ip, 'ttl' => 1, 'proxied' => $proxied]);
                    $changes[] = "+{$d->name} {$edge->ip}";
                    $this->audit->log('edge.watchdog.restored', 'domains', $d->id, ['domain' => $d->name, 'edge' => $edge->name, 'ip' => $edge->ip], $d->organization_id, null, 'system');
                } catch (\Throwable $e) {
                    Log::warning("Edge watchdog: could not restore {$d->name} {$edge->ip}: {$e->getMessage()}");
                }
            });
        }

        return $changes;
    }

    private function title(Server $edge): string
    {
        return "Edge server {$edge->name} removed from enterprise DNS";
    }

    private function alert(Server $edge, bool $down): void
    {
        if ($down) {
            $this->raise($edge->organization_id, $edge->id, $this->title($edge),
                "{$edge->name} ({$edge->ip}) failed its health probe repeatedly, so its A records were removed from the enterprise domains. They are added back after it passes again.");
        } else {
            Alert::allTenants()->where('resource_id', $edge->id)->where('title', $this->title($edge))->where('status', 'open')->update(['status' => 'resolved', 'resolved_at' => now()]);
        }
    }

    private function allDownAlert(Collection $edges, bool $down): void
    {
        $title = 'Every enterprise edge is failing its health probe';
        $first = $edges->first();
        if ($down && ! Cache::has('edge-watchdog:all-down')) {
            Cache::forever('edge-watchdog:all-down', true);
            $this->raise($first->organization_id, $first->id, $title, 'No edge passed its probe, so DNS was left as it is (the last records are never removed). Check the edges and the enterprise backends.');
        } elseif (! $down && Cache::pull('edge-watchdog:all-down')) {
            Alert::allTenants()->where('title', $title)->where('status', 'open')->update(['status' => 'resolved', 'resolved_at' => now()]);
        }
    }

    private function raise(?string $orgId, string $serverId, string $title, string $message): void
    {
        Log::warning("Edge watchdog: {$title}");
        $alert = Alert::create(['organization_id' => $orgId, 'resource_type' => 'server', 'resource_id' => $serverId, 'severity' => 'critical', 'title' => $title, 'message' => $message]);
        foreach (NotificationChannel::allTenants()->where('organization_id', $orgId)->where('enabled', true)->get() as $c) {
            rescue(fn () => $this->notifications->send($c, "[CRITICAL] {$title}", $message, ['alert_id' => $alert->id]), report: false);
        }
    }
}
