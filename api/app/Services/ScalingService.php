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
 * removing it. Spreading replicas over several servers or regions is not built yet.
 */
class ScalingService
{
    public const MODES = ['fixed', 'autoscale', 'scheduled'];

    public const DEFAULTS = [
        'mode' => 'fixed', 'min' => 1, 'max' => 3, 'cpu_target' => 70, 'schedule' => [],
        'sticky' => false, 'health' => ['path' => '/', 'interval' => 10, 'timeout' => 5, 'threshold' => 3],
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
                $this->createReplica($app, $server, $i);
            }
            for ($i = $from; $i > $to; $i--) {
                $this->removeReplica($app, $server, $i);
            }
            $app->forceFill(['replicas' => $to])->save();
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
            $this->bus->dispatch($server, 'container.remove', ['container_id' => $this->replicaName($app, $i), 'keep_volumes' => true, 'force' => true]);
            $this->createReplica($app, $server, $i);
        }
    }

    private function createReplica(Application $app, Server $server, int $i): void
    {
        $config = Release::find($app->current_release_id)?->config ?? [];
        $name = $this->replicaName($app, $i);
        $spec = ContainerSpec::build([
            'name' => $name,
            'image' => $config['image'] ?? $app->image,
            'env' => ! empty($config['env_encrypted']) ? json_decode(Crypt::decryptString($config['env_encrypted']), true) : [],
            'ports' => [],
            'restart_policy' => ($config['auto_restart'] ?? true) ? 'unless-stopped' : 'no',
            'read_only_rootfs' => false,
            'memory_mb' => $config['memory_mb'] ?? null, 'cpus' => $config['cpus'] ?? null,
            'networks' => [['name' => 'proxy']],
            'mounts' => ! empty($config['volume_path']) ? [['type' => 'volume', 'source' => 'app-'.$app->name.'-data', 'target' => $config['volume_path']]] : [],
            'labels' => ['io.dockermgr.app' => $app->id, 'io.dockermgr.replica' => (string) $i],
            'pull' => 'missing',
        ] + (! empty($config['registry_auth']) ? ['registry_auth' => $config['registry_auth']] : []), $app->organization, TraefikLabels::for($app, $config['port'] ?? null));
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
                $cpu = (float) $this->containers($app)->whereNotNull('cpu_pct')->avg('cpu_pct');
                $highKey = "scale:high:{$app->id}";
                $lowKey = "scale:low:{$app->id}";
                if ($cpu > $s['cpu_target']) {
                    Cache::forget($lowKey);
                    $since = Cache::get($highKey) ?? tap($now->getTimestamp(), fn ($t) => Cache::put($highKey, $t, 3600));
                    if ($now->getTimestamp() - $since >= 180) {
                        $desired = $current + 1;
                        $reason = sprintf('autoscale: CPU %d%% > %d%% for 3 min', $cpu, $s['cpu_target']);
                        Cache::forget($highKey);
                    }
                } elseif ($cpu < $s['cpu_target'] / 2) {
                    Cache::forget($highKey);
                    $since = Cache::get($lowKey) ?? tap($now->getTimestamp(), fn ($t) => Cache::put($lowKey, $t, 3600));
                    if ($now->getTimestamp() - $since >= 900) {
                        $desired = $current - 1;
                        $reason = sprintf('autoscale: CPU %d%% < %d%% for 15 min', $cpu, $s['cpu_target'] / 2);
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
