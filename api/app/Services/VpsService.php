<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AgentCommand;
use App\Models\Container;
use App\Models\Server;
use App\Models\User;
use App\Models\Vps;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * UVPS: container VPS with root access, run under Sysbox on servers that hold the
 * "vps" role. Sysbox maps root inside to an unprivileged host user; the agent refuses
 * to create one on a host without that runtime.
 */
class VpsService
{
    public function __construct(private CommandBus $bus, private JobService $jobs, private ContainerService $containers, private AuditLogger $audit) {}

    public function plans(): array
    {
        return collect(config('platform.vps.plans'))->map(fn ($p, $k) => ['id' => $k] + $p)->values()->all();
    }

    public function images(): array
    {
        return collect(config('platform.vps.images'))->map(fn ($i, $k) => ['id' => $k, 'label' => $i['label']])->values()->all();
    }

    public function present(Vps $v): array
    {
        $plan = config("platform.vps.plans.{$v->plan}", []);
        // The host belongs to the platform; only its address and region are shown.
        $server = $v->server_id ? Server::allTenants()->find($v->server_id) : null;

        return [
            'id' => $v->id, 'name' => $v->name, 'status' => $this->status($v), 'error' => $v->error,
            'plan' => ['id' => $v->plan, 'label' => $plan['label'] ?? $v->plan, 'cpus' => $plan['cpus'] ?? null, 'memory_mb' => $plan['memory_mb'] ?? null, 'type' => $plan['type'] ?? null],
            'os' => ['id' => $v->os, 'label' => config("platform.vps.images.{$v->os}.label", $v->os)],
            'region' => $v->region ?? $server?->region, 'ip' => $server?->ip, 'ssh_port' => $v->ssh_port,
            'ssh' => $server?->ip && $v->ssh_port ? "ssh -p {$v->ssh_port} root@{$server->ip}" : null,
            'created_at' => $v->created_at?->toIso8601ZuluString(),
        ];
    }

    /** The container's last known state wins once it exists. */
    public function status(Vps $v): string
    {
        if (in_array($v->status, ['provisioning', 'failed'], true)) {
            return $v->status;
        }
        $state = $v->container?->state;

        return match ($state) {
            'running' => 'running',
            'exited', 'created', 'stopped' => 'stopped',
            'restarting' => 'restarting',
            default => $v->status,
        };
    }

    public function create(User $user, array $v): array
    {
        $plan = config("platform.vps.plans.{$v['plan']}");
        if (! $plan || ! $plan['available']) {
            throw ApiException::validation(['plan' => ['This plan is not available yet.']]);
        }
        $image = config("platform.vps.images.{$v['os']}") ?? throw ApiException::validation(['os' => ['Unknown operating system.']]);
        $limit = (int) config('platform.vps.limit');
        if (Vps::count() >= $limit) {
            throw ApiException::conflict("Your plan includes {$limit} servers and they are all in use.", ['reason' => 'quota']);
        }
        if (Vps::whereRaw('lower(name) = ?', [mb_strtolower($v['name'])])->exists()) {
            throw ApiException::conflict('You already have a server with that name.');
        }

        return DB::transaction(function () use ($user, $v, $plan, $image) {
            $server = $this->place($plan, $v['region'] ?? null);
            $vps = Vps::create(['name' => $v['name'], 'plan' => $v['plan'], 'os' => $v['os'], 'region' => $server->region, 'server_id' => $server->id,
                'ssh_port' => $this->port($server), 'status' => 'provisioning']);
            $container = Container::create(['server_id' => $server->id, 'name' => $vps->dockerName(), 'image' => $image['image'], 'state' => 'creating',
                'labels' => ['io.dockermgr.vps' => 'true', 'io.dockermgr.vps_id' => $vps->id]]);
            $vps->update(['container_id' => $container->id]);
            $job = $this->jobs->create('vps.create', ['execute'], $server, ['name' => $vps->name]);
            $command = $this->bus->dispatch($server, 'vps.create', [
                'name' => $vps->dockerName(), 'hostname' => $vps->name, 'image' => $image['image'],
                'memory_mb' => $plan['memory_mb'], 'cpus' => $plan['cpus'], 'ssh_port' => $vps->ssh_port,
                'authorized_keys' => array_values($v['ssh_keys']), 'labels' => ['io.dockermgr.vps_id' => $vps->id],
            ], $job);
            $job->update(['resource_type' => 'Vps', 'resource_id' => $vps->id, 'payload' => ['command_id' => $command->id]]);
            $this->audit->log('vps.create', 'vps', $vps->id, ['plan' => $v['plan'], 'os' => $v['os'], 'server_id' => $server->id]);

            return [$vps, $job];
        });
    }

    /** Called from the command bus when the agent reports vps.create. */
    public function created(AgentCommand $command, bool $ok): void
    {
        $name = $command->payload['name'] ?? '';
        $container = Container::allTenants()->where('server_id', $command->server_id)->where('name', $name)->first();
        $vps = $container ? Vps::allTenants()->where('container_id', $container->id)->first() : null;
        if ($ok) {
            $container?->update(['docker_id' => $command->output['container_id'] ?? null, 'state' => 'running', 'started_at' => now()]);
            $vps?->update(['status' => 'running', 'error' => null]);
        } else {
            $container?->update(['state' => 'failed']);
            $vps?->update(['status' => 'failed', 'error' => mb_substr((string) ($command->message ?: 'The server could not be created.'), 0, 500)]);
        }
    }

    public function action(Vps $vps, string $action)
    {
        $container = $vps->container;
        if (! $container || ! $container->docker_id || $vps->status === 'provisioning') {
            throw ApiException::conflict('This server is still being set up.');
        }
        if ($vps->status === 'failed') {
            throw ApiException::conflict('This server failed to start. Delete it and create a new one.');
        }
        $job = $this->containers->perform($container, $action);
        $vps->update(['status' => $action === 'stop' ? 'stopped' : 'running']);
        $this->audit->log("vps.{$action}", 'vps', $vps->id);

        return $job;
    }

    public function destroy(Vps $vps)
    {
        $container = $vps->container;
        $job = null;
        if ($container && $container->docker_id) {
            // The VPS disk goes with it: a deleted server is gone.
            $job = $this->containers->perform($container, 'remove', ['keep_volumes' => false, 'force' => true]);
        } else {
            $container?->delete();
        }
        $this->audit->log('vps.delete', 'vps', $vps->id, ['name' => $vps->name]);
        $vps->delete();

        return $job;
    }

    private function place(array $plan, ?string $region): Server
    {
        $orgId = app(Tenant::class)->id();
        $candidates = Server::allTenants()
            ->where(fn ($q) => $q->where('organization_id', $orgId)->orWhere('shared_pool', true))
            ->where('maintenance', false)
            ->whereNotNull('last_heartbeat_at')
            ->where('last_heartbeat_at', '>=', now()->subSeconds(config('platform.offline_after_seconds')))
            ->get()
            ->filter(fn (Server $s) => $s->status === 'online' && $s->hasRole('vps'));
        if ($region) {
            $candidates = $candidates->where('region', $region);
        }
        $scored = $candidates->map(function (Server $s) {
            $reserved = Vps::allTenants()->where('server_id', $s->id)->get()->sum(fn ($v) => (int) config("platform.vps.plans.{$v->plan}.memory_mb", 0));
            $free = ($s->ram_mb ?? 0) * (1 - ($s->ram_pct ?? 0) / 100) - $reserved;

            return ['server' => $s, 'free' => $free];
        })->filter(fn ($c) => $c['free'] >= $plan['memory_mb']);
        if ($scored->isEmpty()) {
            throw ApiException::conflict($region ? "No server capacity is free in {$region} right now. Try another region or a smaller plan." : 'No server capacity is free right now. Try a smaller plan or retry shortly.', ['reason' => 'no_capacity']);
        }

        return $scored->sortByDesc('free')->first()['server'];
    }

    private function port(Server $server): int
    {
        [$lo, $hi] = config('platform.vps.ssh_ports');
        $used = Vps::allTenants()->where('server_id', $server->id)->lockForUpdate()->pluck('ssh_port')->all();
        for ($p = $lo; $p <= $hi; $p++) {
            if (! in_array($p, $used, true)) {
                return $p;
            }
        }
        throw ApiException::conflict('This server has no free SSH ports.', ['reason' => 'no_capacity']);
    }

    public function regions(): array
    {
        return Server::allTenants()->where('shared_pool', true)->get()->filter(fn (Server $s) => $s->hasRole('vps'))->pluck('region')->filter()->unique()->values()->all();
    }
}
