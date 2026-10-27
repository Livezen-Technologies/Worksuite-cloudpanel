<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Container;
use App\Models\Job;
use App\Models\Server;
use App\Models\TerminalSession;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ContainerService
{
    public const ACTIONS = ['start', 'stop', 'restart', 'pause', 'unpause', 'remove'];

    public function __construct(private CommandBus $bus, private JobService $jobs, private ApprovalService $approvals, private AuditLogger $audit) {}

    /** CTR-006 create wizard. */
    public function create(Server $server, array $input): Job
    {
        $spec = ContainerSpec::build($input, app(Tenant::class)->organization());
        $job = $this->jobs->create('container.create', ['execute'], $server, ['name' => $spec['name'] ?? null]);
        $command = $this->bus->dispatch($server, 'container.create', $spec, $job);
        $container = Container::create([
            'server_id' => $server->id, 'name' => $spec['name'] ?? 'ctr-'.Str::lower(Str::random(6)),
            'image' => $spec['image'], 'state' => 'creating', 'spec' => ContainerSpec::redact($spec), 'labels' => (array) $spec['labels'],
            'ports' => array_map(fn ($p) => ($p['host_port'] ?? '').':'.$p['container_port'].'/'.($p['protocol'] ?? 'tcp'), $spec['ports'] ?? []),
        ]);
        $job->update(['resource_type' => 'Container', 'resource_id' => $container->id, 'payload' => ['command_id' => $command->id]]);

        return $job;
    }

    /** Lifecycle action; removal of production containers goes through the approval gate. */
    public function action(User $user, Container $container, string $action, array $options = []): Job
    {
        $this->authorizeAction($user, $container, $action, $options);

        return $this->perform($container, $action, $options);
    }

    public function perform(Container $container, string $action, array $options = []): Job
    {
        $job = $this->jobs->create("container.{$action}", ['execute'], $container);
        $this->bus->dispatch($container->server, "container.{$action}", $this->payload($container, $action, $options), $job);

        return $job;
    }

    private function authorizeAction(User $user, Container $container, string $action, array $options): void
    {
        if ($action === 'remove') {
            $production = ($container->server?->environment ?? 'production') === 'production';
            $production
                ? $this->approvals->gate($user, 'production.delete', 'container.remove', $container, ['keep_volumes' => $options['keep_volumes'] ?? true], $options['approval_id'] ?? null)
                : app(Authorizer::class)->authorize($user, 'containers.operate', $container, true);
        } else {
            app(Authorizer::class)->authorize($user, 'containers.operate', $container, true);
        }
    }

    private function payload(Container $container, string $action, array $options): array
    {
        $payload = ['container_id' => $container->dockerRef()];
        if (in_array($action, ['stop', 'restart'], true) && isset($options['timeout'])) {
            $payload['timeout'] = (int) $options['timeout'];
        }
        if ($action === 'remove') {
            // CTR-004: volumes are kept unless explicitly unticked.
            $payload['keep_volumes'] = (bool) ($options['keep_volumes'] ?? true);
            $payload['force'] = (bool) ($options['force'] ?? true);
        }

        return $payload;
    }

    /** Bulk action: one job, one command per container. */
    public function bulk(User $user, Collection $containers, string $action): Job
    {
        foreach ($containers as $container) {
            $this->authorizeAction($user, $container, $action, []);
        }
        $job = $this->jobs->create("container.bulk.{$action}", ['execute'], null, ['ids' => $containers->pluck('id')->all()]);
        foreach ($containers as $container) {
            $this->bus->dispatch($container->server, "container.{$action}", $this->payload($container, $action, []), $job);
        }

        return $job;
    }

    /** CTR-009: what a prune would remove (stopped containers not managed by an app). */
    public function prunePreview(?string $serverId): array
    {
        $query = Container::query()->whereIn('state', ['exited', 'created', 'dead'])->whereNull('application_id');
        if ($serverId) {
            $query->where('server_id', $serverId);
        }
        $items = $query->get(['id', 'name', 'image', 'state', 'server_id']);

        return ['containers' => $items, 'count' => $items->count()];
    }

    public function prune(?string $serverId): Job
    {
        $servers = Server::query()->when($serverId, fn ($q) => $q->whereKey($serverId))->get()->filter->isOnline();
        $job = $this->jobs->create('container.prune', ['execute'], null, ['server_id' => $serverId, 'preview' => $this->prunePreview($serverId)['count']]);
        foreach ($servers as $server) {
            $this->bus->dispatch($server, 'container.prune', ['labels' => (object) []], $job);
        }
        if ($servers->isEmpty()) {
            $this->jobs->succeed($job, ['message' => 'No online servers to prune.']);
        }

        return $job;
    }

    /** LOG-001: last known logs plus a fresh fetch request. */
    public function logs(Container $container, int $tail): array
    {
        $job = $this->jobs->create('container.logs', ['execute'], $container);
        $this->bus->dispatch($container->server, 'container.logs', ['container_id' => $container->dockerRef(), 'tail' => $tail, 'timestamps' => true], $job);
        $lines = $container->last_logs ? array_slice(explode("\n", rtrim($container->last_logs)), -$tail) : [];

        return ['container_id' => $container->id, 'lines' => $lines, 'fetched_at' => $container->logs_fetched_at?->toIso8601ZuluString(), 'job_id' => $job->id];
    }

    /** CTR-008 / LOG-002: time-limited, recorded, non-root terminal session. */
    public function openTerminal(User $user, Container $container, ?string $approvalId): array
    {
        $this->approvals->gate($user, 'terminal', 'terminal.open', $container, [], $approvalId);
        $token = Str::random(48);
        $session = TerminalSession::create([
            'container_id' => $container->id, 'user_id' => $user->id, 'token_hash' => hash('sha256', $token),
            'shell_user' => 'app', 'expires_at' => now()->addMinutes(config('platform.terminal_ttl_minutes')),
            'recording_path' => 'terminal-recordings/'.now()->format('Y/m/d').'/'.Str::uuid().'.cast', 'ip' => request()->ip(),
        ]);
        $this->audit->log('terminal.opened', 'containers', $container->id, ['session_id' => $session->id]);

        return [
            'session_id' => $session->id,
            'ws_url' => rtrim(config('platform.terminal_ws_url'), '/').'/'.$session->id.'?token='.$token,
            'expires_at' => $session->expires_at->toIso8601ZuluString(),
            'shell_user' => $session->shell_user,
        ];
    }

    public function endTerminal(TerminalSession $session): TerminalSession
    {
        if (! $session->ended_at) {
            $session->update(['ended_at' => now()]);
            $this->audit->log('terminal.closed', 'containers', $session->container_id, ['session_id' => $session->id]);
        }

        return $session;
    }
}
