<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Application;
use App\Models\Container;
use App\Models\EnvVar;
use App\Models\Release;
use App\Models\ScalingEvent;
use App\Models\Server;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * SCL-1..5, 9, 10 / USCL-1..3: replicas of an app on its server.
 *
 * The primary container is the one deployments create (app-<name>); replicas are
 * app-<name>-r2 … app-<name>-rN, built from the current release with the same Traefik
 * service labels, so Traefik balances across all of them and drops unhealthy ones.
 * Scaling down stops a replica with a 30-second grace (connection draining) before
 * removing it.
 *
 * SCL-6: `servers` lists further servers for copies. Copies are dealt round-robin
 * over the app's server and those; a copy elsewhere publishes its port on that
 * server's private-network (mesh) address only, and the app's Traefik balances over
 * all copies through a file-provider pool service.
 * SCL-7: a canary (`app-<name>-canary`) runs another image and takes a weighted
 * share of the traffic until it is promoted or aborted.
 */
class ScalingService
{
    public const MODES = ['fixed', 'autoscale', 'scheduled'];

    /** SCL-3: round robin, or Traefik's power-of-two-choices (least busy of two). */
    public const STRATEGIES = ['round_robin', 'least_connections'];

    public const DEFAULTS = [
        'mode' => 'fixed', 'min' => 1, 'max' => 3, 'cpu_target' => 70, 'metric' => 'cpu', 'rps_target' => 50, 'schedule' => [],
        'sticky' => false, 'health' => ['path' => '/', 'interval' => 10, 'timeout' => 5, 'threshold' => 3],
        'servers' => [], 'canary' => null, 'strategy' => 'round_robin',
    ];

    /** Platform ceiling (SCL-1): 1–20. */
    public const MAX = 20;

    public function __construct(private CommandBus $bus, private AuditLogger $audit) {}

    public static function settings(Application $app): array
    {
        return array_replace_recursive(self::DEFAULTS, $app->scaling ?? []);
    }

    public function replicaName(Application $app, int $i): string
    {
        return $app->containerName().'-r'.$i;
    }

    /** @return \Illuminate\Support\Collection<int, Container> every container of the app, primary first */
    public function containers(Application $app)
    {
        return Container::allTenants()->where('application_id', $app->id)->orderBy('name')->get();
    }

    /** SCL-9: what breaks when there is more than one copy. */
    public function warnings(Application $app): array
    {
        $env = EnvVar::allTenants()->where('application_id', $app->id)->get()->mapWithKeys(fn ($v) => [strtoupper($v->key) => strtolower((string) $v->value)]);
        $out = [];
        if (($env['SESSION_DRIVER'] ?? null) === 'file' || ($env['SESSION_DRIVER'] ?? null) === null && preg_match('/^(wordpress|php|laravel)/i', (string) $app->image)) {
            $out[] = 'Sessions may be stored in files inside each copy, so visitors can be signed out when they reach another copy. Store sessions in Redis (SESSION_DRIVER=redis) or turn on sticky sessions.';
        }
        if ($app->volume_path) {
            $out[] = "Uploads in {$app->volume_path} live on this server's volume, which every copy here shares. They will not follow copies to other servers; use S3 or a shared volume before spreading out.";
        }

        return $out;
    }

    /**
     * SCL-1: set the number of copies. $limit is the caller's ceiling (plan or platform).
     * Returns the event that records it.
     */
    public function scale(Application $app, int $replicas, string $reason, ?string $userId = null, ?int $limit = null): ScalingEvent
    {
        $limit = min(self::MAX, $limit ?? self::MAX);
        $from = (int) ($app->replicas ?: 1);
        $to = max(1, min($replicas, $limit));
        $result = $replicas > $limit ? 'at_max' : 'applied';
        if (! $app->current_release_id) {
            throw ApiException::conflict('Deploy the app once before adding copies.');
        }
        $server = Server::allTenants()->find($app->server_id);
        if (! $server?->isOnline()) {
            throw ApiException::conflict('The app\'s server is offline.');
        }
        if ($to !== $from) {
            for ($i = $from + 1; $i <= $to; $i++) {
                $this->createReplica($app, $this->serverFor($app, $i), $i);
            }
            for ($i = $from; $i > $to; $i--) {
                $this->removeReplica($app, $server, $i);
            }
            $app->forceFill(['replicas' => $to])->save();
            $this->routingChanged();
        }
        $event = ScalingEvent::allTenants()->create([
            'organization_id' => $app->organization_id, 'application_id' => $app->id, 'from' => $from, 'to' => $to,
            'reason' => mb_substr($reason, 0, 255), 'result' => $result, 'user_id' => $userId, 'created_at' => now(),
        ]);
        $this->audit->log('app.scale', 'applications', $app->id, ['from' => $from, 'to' => $to, 'reason' => $reason, 'result' => $result], $app->organization_id, $userId);

        return $event;
    }

    /** SCL-5: after a deploy, replace each replica in turn with the new release; the others keep serving. */
    public function afterDeployment(Application $app): void
    {
        $app->refresh();
        if (($app->replicas ?? 1) < 2) {
            return;
        }
        $server = Server::allTenants()->find($app->server_id);
        if (! $server) {
            return;
        }
        for ($i = 2; $i <= $app->replicas; $i++) {
            $target = $this->serverFor($app, $i);
            $this->bus->dispatch($target, 'container.remove', ['container_id' => $this->replicaName($app, $i), 'keep_volumes' => true, 'force' => true]);
            $this->createReplica($app, $target, $i);
        }
    }

    /** SCL-6: the app's server first, then the extra servers that are on the private network and online. */
    public function placement(Application $app): array
    {
        $primary = Server::allTenants()->find($app->server_id);
        $extra = Server::allTenants()->whereIn('id', (array) self::settings($app)['servers'])->where('id', '!=', $app->server_id)
            ->where('organization_id', $app->organization_id)->whereNotNull('mesh_ip')->orderBy('name')->get()
            ->filter(fn ($s) => $s->isOnline() && ! $s->maintenance)->values()->all();

        return $primary ? array_merge([$primary], ($primary->mesh_ip ? $extra : [])) : [];
    }

    public function serverFor(Application $app, int $i): Server
    {
        $list = $this->placement($app);

        return $list[($i - 1) % count($list)];
    }

    /** Host port a remote copy publishes on its mesh address. */
    public static function hostPort(Application $app, int $i): int
    {
        return 30000 + (crc32($app->id) % 1000) * 20 + $i;
    }

    /** @return list<string>|null pool URLs when copies run on other servers; null when Docker's own balancing is enough */
    public function pool(Application $app): ?array
    {
        $port = Release::find($app->current_release_id)?->config['port'] ?? $app->port;
        $copies = Container::allTenants()->where('application_id', $app->id)->whereNotIn('state', ['removing', 'removed'])->where('name', 'not like', '%-canary')->with('server')->get();
        if (! $port || ! $copies->contains(fn ($c) => $c->server_id !== $app->server_id)) {
            return null;
        }

        return $copies->map(function ($c) use ($app, $port) {
            if ($c->server_id === $app->server_id) {
                return 'http://'.$c->name.':'.$port;
            }
            preg_match('/-r(\d+)$/', $c->name, $m);

            return 'http://'.$c->server?->mesh_ip.':'.self::hostPort($app, (int) ($m[1] ?? 0));
        })->filter(fn ($u) => ! str_contains($u, '//:'))->sort()->values()->all();
    }

    private function routingChanged(): void
    {
        app(TraefikConfigService::class)->push();
    }

    /** SCL-7: run `$image` beside the live release and send it `$weight` percent of requests. */
    public function startCanary(Application $app, string $image, int $weight, ?string $userId = null): array
    {
        $server = Server::allTenants()->find($app->server_id);
        if (! $app->current_release_id || ! $server?->isOnline()) {
            throw ApiException::conflict('Deploy the app and bring its server online before starting a canary.');
        }
        $config = Release::find($app->current_release_id)->config ?? [];
        $port = $config['port'] ?? $app->port;
        if (! $port) {
            throw ApiException::validation(['image' => ['A canary needs the app\'s port.']]);
        }
        $name = $app->containerName().'-canary';
        $service = $app->containerName().'-canary';
        $spec = ContainerSpec::build([
            'name' => $name, 'image' => $image,
            'env' => ! empty($config['env_encrypted']) ? json_decode(Crypt::decryptString($config['env_encrypted']), true) : [],
            'restart_policy' => 'unless-stopped', 'read_only_rootfs' => false,
            'memory_mb' => $config['memory_mb'] ?? null, 'cpus' => $config['cpus'] ?? null,
            'networks' => [['name' => 'proxy']],
            'labels' => ['io.dockermgr.app' => $app->id, 'io.dockermgr.canary' => 'true'],
            'pull' => 'missing',
        ] + (! empty($config['registry_auth']) ? ['registry_auth' => $config['registry_auth']] : []), $app->organization,
            ['traefik.enable' => 'true', "traefik.http.services.{$service}.loadbalancer.server.port" => (string) $port]);
        $this->bus->dispatch($server, 'container.remove', ['container_id' => $name, 'force' => true]);
        $this->bus->dispatch($server, 'container.create', $spec);
        Container::allTenants()->where('application_id', $app->id)->where('name', $name)->delete();
        Container::allTenants()->create([
            'organization_id' => $app->organization_id, 'server_id' => $server->id, 'application_id' => $app->id, 'name' => $name,
            'image' => $image, 'state' => 'creating', 'spec' => ContainerSpec::redact($spec), 'labels' => (array) $spec['labels'],
        ]);
        $canary = ['image' => $image, 'weight' => $weight, 'started_at' => now()->toIso8601String()];
        $this->saveCanary($app, $canary, "canary {$image} at {$weight}%", $userId);

        return $canary;
    }

    public function setCanaryWeight(Application $app, int $weight, ?string $userId = null): array
    {
        $canary = self::settings($app)['canary'] ?? throw ApiException::conflict('No canary is running.');
        $canary['weight'] = $weight;
        $this->saveCanary($app, $canary, "canary weight {$weight}%", $userId);

        return $canary;
    }

    /** Promote: deploy the canary's image as the next release, then remove the canary. */
    public function promoteCanary(Application $app, ?\App\Models\User $user): \App\Models\Deployment
    {
        $canary = self::settings($app)['canary'] ?? throw ApiException::conflict('No canary is running.');
        $app->update(['image' => $canary['image'], 'source_type' => in_array($app->source_type, ['git', 'dockerfile'], true) ? 'image' : $app->source_type]);
        $deployment = app(DeploymentService::class)->start($app, $user, 'canary');
        $this->stopCanary($app, 'canary promoted', $user?->id);

        return $deployment;
    }

    public function stopCanary(Application $app, string $reason = 'canary aborted', ?string $userId = null): void
    {
        $server = Server::allTenants()->find($app->server_id);
        $name = $app->containerName().'-canary';
        if ($server?->isOnline()) {
            $this->bus->dispatch($server, 'container.remove', ['container_id' => $name, 'force' => true]);
        }
        Container::allTenants()->where('application_id', $app->id)->where('name', $name)->update(['state' => 'removing']);
        $this->saveCanary($app, null, $reason, $userId);
    }

    private function saveCanary(Application $app, ?array $canary, string $reason, ?string $userId): void
    {
        $s = self::settings($app);
        $s['canary'] = $canary;
        $app->forceFill(['scaling' => $s])->save();
        $this->audit->log('app.canary', 'applications', $app->id, ['canary' => $canary, 'reason' => $reason], $app->organization_id, $userId);
        $this->routingChanged();
    }

    private function createReplica(Application $app, Server $server, int $i): void
    {
        $config = Release::find($app->current_release_id)?->config ?? [];
        $name = $this->replicaName($app, $i);
        $remote = $server->id !== $app->server_id;
        $port = $config['port'] ?? $app->port;
        $spec = ContainerSpec::build([
            'name' => $name,
            'image' => $config['image'] ?? $app->image,
            'env' => ! empty($config['env_encrypted']) ? json_decode(Crypt::decryptString($config['env_encrypted']), true) : [],
            // A copy elsewhere is reached over the private network only.
            'ports' => $remote && $port ? [['container_port' => (int) $port, 'host_port' => self::hostPort($app, $i), 'host_ip' => $server->mesh_ip]] : [],
            'restart_policy' => ($config['auto_restart'] ?? true) ? 'unless-stopped' : 'no',
            'read_only_rootfs' => false,
            'memory_mb' => $config['memory_mb'] ?? null, 'cpus' => $config['cpus'] ?? null,
            'networks' => [['name' => 'proxy']],
            'mounts' => ! empty($config['volume_path']) ? [['type' => 'volume', 'source' => 'app-'.$app->name.'-data', 'target' => $config['volume_path']]] : [],
            'labels' => ['io.dockermgr.app' => $app->id, 'io.dockermgr.replica' => (string) $i],
            'pull' => 'missing',
        ] + (! empty($config['registry_auth']) ? ['registry_auth' => $config['registry_auth']] : []), $app->organization, $remote ? [] : TraefikLabels::for($app, $config['port'] ?? null));
        if ($remote) {
            unset($spec['networks']);
        }
        Container::allTenants()->where('application_id', $app->id)->where('name', $name)->delete();
        Container::allTenants()->create([
            'organization_id' => $app->organization_id, 'server_id' => $server->id, 'application_id' => $app->id, 'name' => $name,
            'image' => $spec['image'], 'state' => 'creating', 'spec' => ContainerSpec::redact($spec), 'labels' => (array) $spec['labels'],
        ]);
        $this->bus->dispatch($server, 'container.create', $spec);
    }

    private function removeReplica(Application $app, Server $server, int $i): void
    {
        $name = $this->replicaName($app, $i);
        $server = Container::allTenants()->where('application_id', $app->id)->where('name', $name)->first()?->server ?? $server;
        // SCL-5: 30 seconds to finish in-flight requests once Traefik stops sending new ones.
        $this->bus->dispatch($server, 'container.stop', ['container_id' => $name, 'timeout' => 30]);
        $this->bus->dispatch($server, 'container.remove', ['container_id' => $name, 'keep_volumes' => true, 'force' => true]);
        Container::allTenants()->where('application_id', $app->id)->where('name', $name)->update(['state' => 'removing']);
    }

    /**
     * SCL-2: run every minute. Autoscale adds a copy after 3 minutes over the CPU target and
     * removes one after 15 minutes under half of it; scheduled mode follows its windows.
     */
    public function evaluate(?Carbon $now = null): int
    {
        $now ??= now();
        $changed = 0;
        foreach (Application::allTenants()->whereNotNull('scaling')->whereNotNull('current_release_id')->get() as $app) {
            $s = self::settings($app);
            $current = (int) ($app->replicas ?: 1);
            $desired = $current;
            $reason = null;
            if ($s['mode'] === 'scheduled') {
                $desired = $this->scheduled($s, $now);
                $reason = 'schedule';
            } elseif ($s['mode'] === 'autoscale') {
                // SCL-2: CPU across the copies, or requests per second per copy from Traefik's metrics.
                [$cpu, $target, $label] = $s['metric'] === 'rps'
                    ? [$this->rpsPerCopy($app, $current), (float) $s['rps_target'], 'requests/s per copy']
                    : [(float) $this->containers($app)->whereNotNull('cpu_pct')->avg('cpu_pct'), (float) $s['cpu_target'], 'CPU'];
                if ($cpu === null) {
                    continue; // no request metrics: leave the copies alone
                }
                $highKey = "scale:high:{$app->id}";
                $lowKey = "scale:low:{$app->id}";
                if ($cpu > $target) {
                    Cache::forget($lowKey);
                    $since = Cache::get($highKey) ?? tap($now->getTimestamp(), fn ($t) => Cache::put($highKey, $t, 3600));
                    if ($now->getTimestamp() - $since >= 180) {
                        $desired = $current + 1;
                        $reason = $this->why($label, $cpu, '>', $target, '3 min');
                        Cache::forget($highKey);
                    }
                } elseif ($cpu < $target / 2) {
                    Cache::forget($highKey);
                    $since = Cache::get($lowKey) ?? tap($now->getTimestamp(), fn ($t) => Cache::put($lowKey, $t, 3600));
                    if ($now->getTimestamp() - $since >= 900) {
                        $desired = $current - 1;
                        $reason = $this->why($label, $cpu, '<', $target / 2, '15 min');
                        Cache::forget($lowKey);
                    }
                } else {
                    Cache::forget($highKey);
                    Cache::forget($lowKey);
                }
            }
            $desired = max((int) $s['min'], $desired);
            if ($reason && $desired > (int) $s['max']) {
                // At the maximum: record it once an hour so someone can raise the limit.
                if (Cache::add("scale:atmax:{$app->id}", 1, 3600)) {
                    ScalingEvent::allTenants()->create(['organization_id' => $app->organization_id, 'application_id' => $app->id, 'from' => $current, 'to' => $current,
                        'reason' => $reason.' (at the maximum of '.$s['max'].')', 'result' => 'at_max', 'created_at' => $now]);
                }
                $desired = (int) $s['max'];
            }
            if ($desired !== $current) {
                try {
                    $this->scale($app, $desired, $reason ?? 'minimum', null, (int) $s['max']);
                    $changed++;
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        return $changed;
    }

    private function why(string $label, float $value, string $op, float $limit, string $for): string
    {
        return $label === 'CPU'
            ? sprintf('autoscale: CPU %d%% %s %d%% for %s', $value, $op, $limit, $for)
            : sprintf('autoscale: %s %s %s %s for %s', round($value, 1), $label, $op, round($limit, 1), $for);
    }

    /** Requests per second per copy over the last 3 minutes, or null without Prometheus. */
    public function rpsPerCopy(Application $app, int $copies): ?float
    {
        if (! \App\Support\PrometheusMetrics::enabled()) {
            return null;
        }
        $rps = rescue(fn () => app(\App\Support\PrometheusMetrics::class)->requestsPerSecond($app->name), null, report: false);

        return $rps === null ? null : $rps / max(1, $copies);
    }

    /** USCL-1: requests per second across all of an app's copies (cached 30 s), or null without Prometheus. */
    public function rps(Application $app): ?float
    {
        if (! \App\Support\PrometheusMetrics::enabled()) {
            return null;
        }

        return \Illuminate\Support\Facades\Cache::remember("app-rps:{$app->id}", 30, fn () => rescue(fn () => app(\App\Support\PrometheusMetrics::class)->requestsPerSecond($app->name), null, report: false));
    }

    /** The replica count a schedule asks for now: the first matching window, else the minimum. */
    private function scheduled(array $s, Carbon $now): int
    {
        foreach ((array) $s['schedule'] as $w) {
            $days = (array) ($w['days'] ?? [1, 2, 3, 4, 5, 6, 7]);
            $t = $now->format('H:i');
            if (in_array($now->dayOfWeekIso, $days, true) && $t >= ($w['from'] ?? '00:00') && $t < ($w['to'] ?? '24:00')) {
                return (int) ($w['replicas'] ?? $s['min']);
            }
        }

        return (int) $s['min'];
    }
}
