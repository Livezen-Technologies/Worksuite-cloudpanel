<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AgentCommand;
use App\Models\Job;
use App\Models\Server;
use App\Models\SwarmCluster;

/**
 * SWARM: clusters built from the servers' own Docker Engines through the agent. The manager
 * runs every cluster-wide command; nodes join with the worker token, which never leaves the
 * panel except inside the (encrypted) join command.
 */
class SwarmService
{
    public function __construct(private CommandBus $bus, private JobService $jobs, private AuditLogger $audit) {}

    /** The private (mesh) address when the server has one, so cluster traffic stays off the internet. */
    public static function addr(Server $s): string
    {
        $a = (string) ($s->mesh_ip ?: $s->ip);
        if (! filter_var($a, FILTER_VALIDATE_IP)) {
            throw ApiException::conflict("Server {$s->name} has no usable IP address for the cluster.");
        }

        return $a;
    }

    private function online(Server $s): void
    {
        if (! $s->isOnline()) {
            throw ApiException::conflict("Server {$s->name} is offline.");
        }
        if ($s->maintenance) {
            throw ApiException::conflict("Server {$s->name} is in maintenance mode.");
        }
    }

    private function run(Server $server, string $type, array $payload, SwarmCluster $c): Job
    {
        $job = $this->jobs->create($type, ['execute'], $server, ['cluster_id' => $c->id]);
        $this->bus->dispatch($server, $type, $payload + ['cluster_id' => $c->id], $job);

        return $job;
    }

    public function create(string $name, Server $manager): array
    {
        $this->online($manager);
        if ($manager->swarm_cluster_id) {
            throw ApiException::conflict("{$manager->name} is already in a cluster.");
        }
        $c = SwarmCluster::create(['name' => $name, 'manager_server_id' => $manager->id, 'advertise_addr' => self::addr($manager), 'status' => 'creating']);
        $job = $this->run($manager, 'swarm.init', ['advertise_addr' => $c->advertise_addr], $c);
        $this->audit->log('swarm.create', 'swarm_cluster', $c->id, ['manager' => $manager->name]);

        return [$c, $job];
    }

    public function join(SwarmCluster $c, Server $server): Job
    {
        $this->active($c);
        $this->online($server);
        if ($server->swarm_cluster_id) {
            throw ApiException::conflict("{$server->name} is already in a cluster.");
        }
        $job = $this->run($server, 'swarm.join', ['advertise_addr' => self::addr($server), 'manager' => $c->advertise_addr.':2377', 'token' => (string) $c->worker_token], $c);
        $this->audit->log('swarm.join', 'swarm_cluster', $c->id, ['server' => $server->name]);

        return $job;
    }

    public function leave(SwarmCluster $c, Server $server): Job
    {
        if ($server->swarm_cluster_id !== $c->id) {
            throw ApiException::conflict("{$server->name} is not in this cluster.");
        }
        if ($server->id === $c->manager_server_id) {
            throw ApiException::conflict('The manager cannot leave while it runs the cluster. Remove the other nodes first, then delete the cluster.');
        }
        $this->online($server);
        $job = $this->run($server, 'swarm.leave', ['force' => false], $c);
        $this->audit->log('swarm.leave', 'swarm_cluster', $c->id, ['server' => $server->name]);

        return $job;
    }

    public function refresh(SwarmCluster $c): Job
    {
        $this->active($c);

        return $this->run($this->managerOf($c), 'swarm.inspect', [], $c);
    }

    public function nodeAvailability(SwarmCluster $c, string $nodeId, string $availability): Job
    {
        $this->active($c);
        $this->audit->log('swarm.node', 'swarm_cluster', $c->id, ['node' => $nodeId, 'availability' => $availability]);

        return $this->run($this->managerOf($c), 'swarm.node_update', ['node_id' => $nodeId, 'availability' => $availability], $c);
    }

    public function scale(SwarmCluster $c, string $serviceId, int $replicas): Job
    {
        $this->active($c);
        $this->audit->log('swarm.scale', 'swarm_cluster', $c->id, ['service' => $serviceId, 'replicas' => $replicas]);

        return $this->run($this->managerOf($c), 'swarm.service_scale', ['service_id' => $serviceId, 'replicas' => $replicas], $c);
    }

    /** Deploys an image as a replicated service with a rolling, self-rolling-back update policy. */
    public function createService(SwarmCluster $c, array $v): Job
    {
        $this->active($c);
        $names = collect($c->snapshot['services'] ?? [])->pluck('name');
        if ($names->contains($v['name'])) {
            throw ApiException::conflict("A service named {$v['name']} already exists in this cluster.");
        }
        $this->audit->log('swarm.service_create', 'swarm_cluster', $c->id, ['name' => $v['name'], 'image' => $v['image'], 'replicas' => $v['replicas']]);

        return $this->run($this->managerOf($c), 'swarm.service_create', $v, $c);
    }

    public function updateImage(SwarmCluster $c, string $serviceId, string $image): Job
    {
        $this->active($c);
        $this->audit->log('swarm.service_image', 'swarm_cluster', $c->id, ['service' => $serviceId, 'image' => $image]);

        return $this->run($this->managerOf($c), 'swarm.service_image', ['service_id' => $serviceId, 'image' => $image], $c);
    }

    public function removeService(SwarmCluster $c, string $serviceId): Job
    {
        $this->active($c);
        $this->audit->log('swarm.service_remove', 'swarm_cluster', $c->id, ['service' => $serviceId]);

        return $this->run($this->managerOf($c), 'swarm.service_remove', ['service_id' => $serviceId], $c);
    }

    /**
     * SWARM: a secret goes into the cluster's own encrypted store. The value rides only in the
     * (encrypted) command and is wiped from it once the agent has answered.
     */
    public function createSecret(SwarmCluster $c, string $name, string $value): Job
    {
        $this->active($c);
        if (collect($c->snapshot['secrets'] ?? [])->pluck('name')->contains($name)) {
            throw ApiException::conflict("A secret named {$name} already exists. Swarm secrets can't be changed; create one with a new name.");
        }
        $this->audit->log('swarm.secret_create', 'swarm_cluster', $c->id, ['name' => $name]);

        return $this->run($this->managerOf($c), 'swarm.secret_create', ['name' => $name, 'data' => base64_encode($value)], $c);
    }

    public function removeSecret(SwarmCluster $c, string $secretId): Job
    {
        $this->active($c);
        $this->audit->log('swarm.secret_remove', 'swarm_cluster', $c->id, ['secret' => $secretId]);

        return $this->run($this->managerOf($c), 'swarm.secret_remove', ['secret_id' => $secretId], $c);
    }

    /** "Deploy from an app": the app's latest successful release image, which the build pipeline pushed. */
    public static function appImage(string $applicationId): string
    {
        $app = \App\Models\Application::findOrFail($applicationId);
        $image = \App\Models\Release::where('application_id', $app->id)->where('status', '!=', 'failed')->orderByDesc('version')->value('image');
        $local = (string) config('platform.builds.local_repository');
        if (! $image || ($local !== '' && str_starts_with((string) $image, $local.'/'))) {
            throw ApiException::validation(['application_id' => ["{$app->name} has no image in a registry yet. Give it a push registry and deploy it once, then deploy it here."]]);
        }

        return (string) $image;
    }

    /** Deleting the cluster record: only once the manager is its only node. */
    public function destroy(SwarmCluster $c): ?Job
    {
        $others = Server::where('swarm_cluster_id', $c->id)->where('id', '!=', $c->manager_server_id)->count();
        if ($others > 0) {
            throw ApiException::conflict("Remove the cluster's {$others} other node(s) first.");
        }
        $manager = $c->manager_server_id ? Server::find($c->manager_server_id) : null;
        $job = null;
        if ($manager && $c->status === 'active' && $manager->isOnline()) {
            $job = $this->run($manager, 'swarm.leave', ['force' => true], $c);
        }
        Server::where('swarm_cluster_id', $c->id)->update(['swarm_cluster_id' => null, 'swarm_role' => null, 'swarm_node_id' => null]);
        $this->audit->log('swarm.delete', 'swarm_cluster', $c->id, ['name' => $c->name]);
        $c->delete();

        return $job;
    }

    private function active(SwarmCluster $c): void
    {
        if ($c->status !== 'active') {
            throw ApiException::conflict($c->status === 'failed' ? "The cluster failed to start: {$c->error}" : 'The cluster is still being created.');
        }
    }

    private function managerOf(SwarmCluster $c): Server
    {
        $m = $c->manager_server_id ? Server::find($c->manager_server_id) : null;
        if (! $m) {
            throw ApiException::conflict('The cluster has no manager server.');
        }
        $this->online($m);

        return $m;
    }

    /** From the command bus when the agent reports a swarm.* command. */
    public function result(AgentCommand $cmd, bool $ok): void
    {
        $c = SwarmCluster::allTenants()->find($cmd->payload['cluster_id'] ?? null);
        if (! $c) {
            return;
        }
        $server = Server::allTenants()->find($cmd->server_id);
        $out = (array) ($cmd->output ?? []);
        if ($cmd->type === 'swarm.secret_create') {
            // Whatever the outcome, the value doesn't stay in the command record.
            $cmd->forceFill(['payload' => array_diff_key((array) $cmd->payload, ['data' => true]) + ['data' => '[removed]']])->save();
        }
        if (! $ok) {
            if ($cmd->type === 'swarm.init') {
                $c->forceFill(['status' => 'failed', 'error' => mb_substr((string) ($cmd->message ?: 'swarm init failed'), 0, 500)])->save();
            }

            return;
        }
        match ($cmd->type) {
            'swarm.init' => (function () use ($c, $server, $out) {
                $c->forceFill(['status' => 'active', 'error' => null, 'swarm_id' => $out['swarm_id'] ?? null,
                    'worker_token' => $out['worker_token'] ?? null, 'manager_token' => $out['manager_token'] ?? null])->save();
                $server?->forceFill(['swarm_cluster_id' => $c->id, 'swarm_role' => 'manager', 'swarm_node_id' => $out['node_id'] ?? null])->save();
            })(),
            'swarm.join' => $server?->forceFill(['swarm_cluster_id' => $c->id, 'swarm_role' => 'worker'])->save(),
            'swarm.leave' => $server?->forceFill(['swarm_cluster_id' => null, 'swarm_role' => null, 'swarm_node_id' => null])->save(),
            'swarm.inspect' => $c->forceFill(['snapshot' => ['nodes' => $out['nodes'] ?? [], 'services' => $out['services'] ?? [], 'secrets' => $out['secrets'] ?? []], 'refreshed_at' => now()])->save(),
            default => null,
        };
        // After a change to services, read the cluster again so the page shows it.
        if (in_array($cmd->type, ['swarm.service_create', 'swarm.service_image', 'swarm.service_remove', 'swarm.secret_create', 'swarm.secret_remove', 'swarm.service_scale', 'swarm.node_update', 'swarm.join', 'swarm.leave'], true) && $c->status === 'active') {
            rescue(fn () => app(\App\Support\Tenant::class)->as($c->organization_id, fn () => $this->refresh($c)), null, report: false);
        }
    }
}
