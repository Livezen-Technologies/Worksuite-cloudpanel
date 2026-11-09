<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Domain;
use App\Models\NotificationChannel;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;

/**
 * SCL-8: DNS failover between ingress (edge) servers. When EDGE_FAILOVER is on,
 * every verified domain in a zone the panel manages gets one A record per
 * ingress server that is online; an ingress server that goes offline or into
 * maintenance is taken out of DNS and put back when it returns.
 *
 * Only A records that already point at an ingress server are touched, so a
 * domain on a tunnel, on someone else's host or with hand-made records is left
 * alone, and the last record is never removed.
 */
class EdgeFailoverService
{
    public const TTL = 300;

    public function __construct(private DnsZoneService $zones, private TraefikConfigService $traefik, private NotificationService $notifications) {}

    public static function enabled(): bool
    {
        return (bool) config('platform.edge.failover');
    }

    /** @return array{online: list<string>, changes: list<string>} */
    public function check(): array
    {
        $ingress = $this->traefik->ingressServers();
        $all = $ingress->pluck('ip')->filter()->unique()->values()->all();
        $online = $ingress->filter(fn (Server $s) => $s->ip && $s->isOnline() && ! $s->maintenance)->pluck('ip')->unique()->values()->all();
        $changes = [];
        if (! self::enabled() || count($all) < 2 || ! $online) {
            return ['online' => $online, 'changes' => $changes];
        }
        $this->announce($ingress, $online);
        foreach (Domain::allTenants()->where('dns_status', 'verified')->whereNotNull('application_id')->get() as $domain) {
            $zone = DnsZone::forDomain($domain->name, $domain->organization_id);
            if (! $zone?->provider) {
                continue;
            }
            $records = DnsRecord::allTenants()->where('dns_zone_id', $zone->id)->where('type', 'A')->where('name', $domain->name)->get();
            $ours = $records->filter(fn ($r) => in_array($r->content, $all, true));
            if ($ours->isEmpty()) {
                continue; // not pointed at the edge: not ours to manage
            }
            app(\App\Support\Tenant::class)->as($domain->organization_id, function () use ($zone, $domain, $ours, $online, &$changes) {
                foreach (array_diff($online, $ours->pluck('content')->all()) as $ip) {
                    $this->zones->createRecord($zone, ['type' => 'A', 'name' => $domain->name, 'content' => $ip, 'ttl' => self::TTL]);
                    $changes[] = "+{$domain->name} {$ip}";
                }
                foreach ($ours->reject(fn ($r) => in_array($r->content, $online, true)) as $r) {
                    $this->zones->deleteRecord($zone, $r);
                    $changes[] = "-{$domain->name} {$r->content}";
                }
            });
        }

        return ['online' => $online, 'changes' => $changes];
    }

    /** One alert when an edge server leaves DNS, resolved when it comes back. */
    private function announce($ingress, array $online): void
    {
        foreach ($ingress as $s) {
            $down = ! in_array($s->ip, $online, true);
            $key = 'edge-down:'.$s->id;
            if ($down && ! Cache::has($key)) {
                Cache::forever($key, true);
                $title = "Edge server {$s->name} taken out of DNS";
                $message = "{$s->name} ({$s->ip}) is offline or in maintenance, so its A records were removed; visitors go to the other edge servers. It is added back when it returns.";
                $alert = Alert::create(['organization_id' => $s->organization_id, 'resource_type' => 'server', 'resource_id' => $s->id, 'severity' => 'critical', 'title' => $title, 'message' => $message]);
                foreach (NotificationChannel::allTenants()->where('organization_id', $s->organization_id)->where('enabled', true)->get() as $c) {
                    $this->notifications->send($c, "[CRITICAL] {$title}", $message, ['alert_id' => $alert->id]);
                }
            } elseif (! $down && Cache::pull($key)) {
                Alert::allTenants()->where('resource_id', $s->id)->where('title', "Edge server {$s->name} taken out of DNS")->where('status', 'open')
                    ->update(['status' => 'resolved', 'resolved_at' => now()]);
            }
        }
    }
}
