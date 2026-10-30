<?php

namespace App\Services;

use App\Drivers\CloudflareTunnelApi;
use App\Exceptions\ApiException;
use App\Models\Alert;
use App\Models\Application;
use App\Models\CloudflareTunnel;
use App\Models\DnsProviderAccount;
use App\Models\Domain;
use App\Models\NotificationChannel;
use App\Models\Server;

/**
 * Cloudflare Tunnel (CFT-1..6): publish a server's apps through an outbound
 * tunnel instead of open ports 80/443.
 *
 *  1. a remotely managed tunnel is created in the Cloudflare account;
 *  2. a cloudflared container (`dmp-cloudflared`) runs on the server's proxy
 *     network with the tunnel token;
 *  3. every domain of an app on the server becomes an ingress rule to Traefik,
 *     which keeps routing by Host header and applying middlewares;
 *  4. each hostname gets a proxied CNAME to <tunnel>.cfargotunnel.com in its
 *     Cloudflare zone;
 *  5. `tunnels:sync` (every 5 minutes) refreshes health and alerts when down;
 *  6. disabling removes the container, the CNAMEs and the tunnel.
 */
class CloudflareTunnelService
{
    public const CONTAINER = 'dmp-cloudflared';

    public function __construct(private CommandBus $bus, private NotificationService $notifications) {}

    public function api(DnsProviderAccount $provider): CloudflareTunnelApi
    {
        if ($provider->type !== 'cloudflare' || empty($provider->credentials['account_id'])) {
            throw ApiException::validation(['dns_provider_id' => ['Pick a Cloudflare account with an account ID; the token needs Cloudflare Tunnel: Edit.']]);
        }

        return new CloudflareTunnelApi((string) $provider->credentials['token'], (string) $provider->credentials['account_id']);
    }

    public function enable(Server $server, DnsProviderAccount $provider): CloudflareTunnel
    {
        if (CloudflareTunnel::where('server_id', $server->id)->exists()) {
            throw ApiException::conflict("{$server->name} already has a tunnel.");
        }
        $api = $this->api($provider);
        $created = $api->create('dmp-'.$server->name.'-'.substr($server->id, 0, 8));
        $tunnel = CloudflareTunnel::create([
            'organization_id' => $server->organization_id, 'server_id' => $server->id, 'dns_provider_id' => $provider->id,
            'tunnel_id' => $created['id'], 'name' => $created['name'], 'token' => $api->token($created['id']), 'hostnames' => [],
        ]);
        $this->runConnector($tunnel);
        $this->sync($tunnel);

        return $tunnel->fresh();
    }

    /** CFT-2: (re)creates cloudflared with the tunnel token; it only makes outbound connections. */
    public function runConnector(CloudflareTunnel $tunnel): void
    {
        $spec = ContainerSpec::build([
            'name' => self::CONTAINER, 'image' => config('platform.tunnels.image'),
            'cmd' => ['tunnel', '--no-autoupdate', '--metrics', '0.0.0.0:2000', 'run'],
            'env' => ['TUNNEL_TOKEN' => $tunnel->token],
            'networks' => [['name' => 'proxy']], 'read_only_rootfs' => true,
            'labels' => ['io.dockermgr.role' => 'cloudflared'],
        ], $tunnel->server->organization);
        $this->bus->dispatch($tunnel->server, 'container.remove', ['container_id' => self::CONTAINER, 'force' => true]);
        $this->bus->dispatch($tunnel->server, 'container.create', $spec);
    }

    /** Domains served from the server: those of its apps. */
    public function hostnames(Server $server): array
    {
        $apps = Application::allTenants()->where('server_id', $server->id)->pluck('id');

        return Domain::allTenants()->whereIn('application_id', $apps)->orderBy('name')->pluck('name')->unique()->values()->all();
    }

    /** CFT-3/4: ingress rules to Traefik plus a proxied CNAME per hostname in its Cloudflare zone. */
    public function sync(CloudflareTunnel $tunnel): CloudflareTunnel
    {
        $api = $this->api($tunnel->provider);
        $driver = $tunnel->provider->driver();
        $origin = config('platform.tunnels.origin');
        $wanted = $this->hostnames($tunnel->server);
        $target = $tunnel->tunnel_id.'.cfargotunnel.com';
        $errors = [];
        try {
            $api->configure($tunnel->tunnel_id, array_map(fn ($h) => ['hostname' => $h, 'service' => $origin, 'originRequest' => ['httpHostHeader' => $h]], $wanted));
        } catch (ApiException $e) {
            $errors[] = $e->getMessage();
        }
        $current = collect($tunnel->hostnames ?? [])->keyBy('hostname');
        $next = [];
        foreach ($wanted as $host) {
            if ($current->has($host)) {
                $next[] = $current[$host];

                continue;
            }
            try {
                $zone = $this->zoneFor($driver, $host);
                if (! $zone) {
                    $errors[] = "{$host}: no zone for it in this Cloudflare account";

                    continue;
                }
                $existing = collect($driver->records($zone))->first(fn ($r) => $r['name'] === $host && in_array($r['type'], ['A', 'AAAA', 'CNAME'], true));
                $record = ['type' => 'CNAME', 'name' => $host, 'content' => $target, 'ttl' => 1, 'proxied' => true];
                $saved = $existing ? $driver->updateRecord($zone, $existing['id'], $record) : $driver->createRecord($zone, $record);
                $next[] = ['hostname' => $host, 'zone_id' => $zone, 'record_id' => $saved['id']];
            } catch (ApiException $e) {
                $errors[] = "{$host}: ".$e->getMessage();
            }
        }
        foreach ($current as $host => $entry) {
            if (! in_array($host, $wanted, true)) {
                try {
                    $driver->deleteRecord($entry['zone_id'], $entry['record_id']);
                } catch (ApiException) {
                    // already gone
                }
            }
        }
        try {
            $health = $api->status($tunnel->tunnel_id);
        } catch (ApiException $e) {
            $health = ['status' => 'down', 'connections' => 0];
            $errors[] = $e->getMessage();
        }
        $wasUp = in_array($tunnel->status, ['healthy', 'degraded'], true);
        $tunnel->update(['hostnames' => $next, 'status' => $health['status'], 'connections' => $health['connections'],
            'error' => $errors ? implode("\n", $errors) : null, 'last_synced_at' => now()]);
        if ($wasUp && $health['status'] === 'down') {
            $this->alert($tunnel);
        }

        return $tunnel;
    }

    private function zoneFor($driver, string $host): ?string
    {
        $labels = explode('.', $host);
        for ($i = 0; $i < count($labels) - 1; $i++) {
            if ($zone = $driver->findZone(implode('.', array_slice($labels, $i)))) {
                return $zone['id'];
            }
        }

        return null;
    }

    /** CFT-6 */
    public function disable(CloudflareTunnel $tunnel): void
    {
        $driver = $tunnel->provider->driver();
        foreach ($tunnel->hostnames ?? [] as $entry) {
            try {
                $driver->deleteRecord($entry['zone_id'], $entry['record_id']);
            } catch (ApiException) {
            }
        }
        if ($tunnel->server?->isOnline()) {
            $this->bus->dispatch($tunnel->server, 'container.remove', ['container_id' => self::CONTAINER, 'force' => true]);
        }
        try {
            $this->api($tunnel->provider)->delete($tunnel->tunnel_id);
        } catch (ApiException) {
            // deleted in the dashboard already
        }
        $tunnel->delete();
    }

    public function syncAll(): int
    {
        $n = 0;
        foreach (CloudflareTunnel::allTenants()->get(['id', 'organization_id']) as $row) {
            app(\App\Support\Tenant::class)->as($row->organization_id, fn () => $this->sync(CloudflareTunnel::findOrFail($row->id)));
            $n++;
        }

        return $n;
    }

    private function alert(CloudflareTunnel $tunnel): void
    {
        $title = "Cloudflare Tunnel down on {$tunnel->server->name}";
        if (Alert::allTenants()->where('resource_id', $tunnel->server_id)->where('title', $title)->where('status', 'open')->exists()) {
            return;
        }
        $message = 'The tunnel has no connections to Cloudflare; sites routed through it are unreachable. Check the dmp-cloudflared container.';
        $alert = Alert::create(['organization_id' => $tunnel->organization_id, 'resource_type' => 'server', 'resource_id' => $tunnel->server_id, 'severity' => 'critical', 'title' => $title, 'message' => $message]);
        foreach (NotificationChannel::allTenants()->where('organization_id', $tunnel->organization_id)->where('enabled', true)->get() as $channel) {
            $this->notifications->send($channel, "[CRITICAL] {$title}", $message, ['alert_id' => $alert->id]);
        }
    }

    public function present(CloudflareTunnel $t): array
    {
        return [
            'id' => $t->id, 'server_id' => $t->server_id, 'dns_provider_id' => $t->dns_provider_id, 'tunnel_id' => $t->tunnel_id, 'name' => $t->name,
            'status' => $t->status, 'connections' => $t->connections, 'hostnames' => array_column($t->hostnames ?? [], 'hostname'),
            'cname_target' => $t->tunnel_id.'.cfargotunnel.com', 'error' => $t->error, 'last_synced_at' => $t->last_synced_at,
        ];
    }
}
