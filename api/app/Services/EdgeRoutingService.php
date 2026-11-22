<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Job;
use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * XNODE-3: public-IP routing from the ingress/edge servers to apps homed on other nodes.
 *
 * Replaces #234's WireGuard path. An app whose home server is not an ingress (while a
 * dedicated ingress exists) publishes its container port on the host at a fixed port
 * (port()), and the home server's agent filters that port so only the ingress servers that
 * serve the app may reach it (edge.ports, an nftables table hooked at prerouting, i.e.
 * before Docker's DNAT, which is why UFW cannot do it). Traefik then sends the domain to
 * http://<home public ip>:<port> with a health check.
 *
 * TODO(XNODE-3b): the hop is plain HTTP behind that filter. HTTPS (panel CA, per-app
 * certificate, TLS sidecar, Traefik serversTransport rootCAs) is the next step; until then
 * EDGE_BACKEND_TLS=true makes these apps unroutable rather than silently downgrading.
 */
class EdgeRoutingService
{
    public function __construct(private CommandBus $bus) {}

    public static function tlsEnabled(): bool
    {
        return (bool) config('platform.edge.backend_tls');
    }

    private static function isIngress(Server $s): bool
    {
        $ids = (array) config('platform.traefik.ingress_server_ids');

        return $ids ? in_array($s->id, $ids, true) : (in_array('ingress', (array) ($s->tags ?? []), true) || TraefikConfigService::isEdge($s));
    }

    /** True when $server is not an ingress but a dedicated ingress exists (its apps are reached over the public IP). */
    public static function offIngress(?Server $server): bool
    {
        if (! $server || self::isIngress($server)) {
            return false;
        }
        if ((array) config('platform.traefik.ingress_server_ids')) {
            return true;
        }

        return Server::allTenants()->get(['id', 'tags'])->contains(fn ($s) => self::isIngress($s));
    }

    /** The ingress servers whose Traefik serves this app (edges for enterprise apps once edges exist). */
    public function ingressFor(Application $app): Collection
    {
        $all = app(TraefikConfigService::class)->ingressServers();
        $edges = $all->filter(fn (Server $s) => TraefikConfigService::isEdge($s));
        if ($edges->isNotEmpty()) {
            $enterprise = ! empty($app->source['enterprise_deployment_id']);
            $all = $all->filter(fn (Server $s) => TraefikConfigService::isEdge($s) === $enterprise);
        }

        return $all->reject(fn (Server $s) => $s->id === $app->server_id)->values();
    }

    /** The app's host port, allocated once and kept in source.edge_port (stable across deploys). */
    public function port(Application $app): int
    {
        if ($p = (int) ($app->source['edge_port'] ?? 0)) {
            return $p;
        }
        $base = (int) config('platform.edge.port_base', 51000);
        $span = max(1, (int) config('platform.edge.port_span', 10000));
        $taken = Application::allTenants()->where('server_id', $app->server_id)->where('id', '!=', $app->id)->get(['id', 'source'])
            ->map(fn ($a) => (int) ($a->source['edge_port'] ?? 0))->filter()->all();
        $start = crc32((string) $app->id) % $span;
        for ($i = 0; $i < $span; $i++) {
            $p = $base + (($start + $i) % $span);
            if (! in_array($p, $taken, true)) {
                break;
            }
        }
        $app->source = array_merge((array) $app->source, ['edge_port' => $p]);
        $app->saveQuietly();

        return $p;
    }

    /** Apps on $home that the ingress reaches over the public IP. */
    public function routedApps(Server $home): Collection
    {
        if (! self::offIngress($home)) {
            return collect();
        }

        // Every app with a port, domain or not: DeploymentService publishes all of them, and a
        // published port must never be left unfiltered.
        return Application::allTenants()->where('server_id', $home->id)->whereNotNull('port')->orderBy('id')->get();
    }

    /** The edge.ports payload for $home: its routed apps' host ports, open only to their ingress IPs. */
    public function firewallFor(Server $home): array
    {
        $rules = [];
        foreach ($this->routedApps($home) as $app) {
            $allow = $this->ingressFor($app)->pluck('ip')->filter()->unique()->sort()->values()->all();
            $rules[] = ['port' => $this->port($app), 'allow' => $allow];
        }

        return ['rules' => $rules];
    }

    /** Sends edge.ports to $home when its rules changed (or $force). Returns whether it sent. */
    public function syncFirewall(Server $home, bool $force = false, ?Job $job = null): bool
    {
        $payload = $this->firewallFor($home);
        $key = 'edge-ports:'.$home->id;
        $hash = hash('sha256', json_encode($payload));
        if (! $force && Cache::get($key) === $hash) {
            return false;
        }
        if ($payload['rules'] === [] && ! Cache::has($key)) {
            return false; // never had rules: nothing to remove
        }
        $this->bus->dispatch($home, 'edge.ports', $payload, $job);
        Cache::put($key, $hash, now()->addDays(30));

        return true;
    }

    /** Syncs every non-ingress server that hosts a routed app (called after each proxy push). */
    public function syncAll(bool $force = false): int
    {
        $n = 0;
        foreach (Server::allTenants()->get() as $s) {
            if (self::offIngress($s) || Cache::has('edge-ports:'.$s->id)) {
                $n += (int) $this->syncFirewall($s, $force);
            }
        }

        return $n;
    }
}
