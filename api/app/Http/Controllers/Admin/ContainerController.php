<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Container;
use App\Models\Server;
use App\Models\TerminalSession;
use App\Services\Authorizer;
use App\Services\ContainerService;
use App\Support\Lists;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContainerController extends Controller
{
    public function __construct(private ContainerService $containers) {}

    public function index(Request $request)
    {
        $query = Container::query();
        app(Authorizer::class)->scopeAssigned($request->user(), 'resources.view', $query);

        return response()->json(Lists::paginate($query, $request, ['name', 'image'], ['name', 'state', 'cpu_pct', 'mem_mb', 'created_at', 'started_at'], [
            'server_id', 'health', 'application_id',
            'state' => fn ($q, $v) => match ($v) {
                'unhealthy' => $q->where('health', 'unhealthy'),
                'stopped' => $q->whereIn('state', ['exited', 'created', 'dead']),
                default => $q->where('state', $v),
            },
        ]));
    }

    public function show(Request $request, string $id)
    {
        $container = Container::findOrFail($id);
        $this->authorizeFor($request, 'resources.view', $container);

        return $this->data(array_merge($container->toArray(), [
            'server' => $container->server?->only(['id', 'name', 'environment']),
            'limits' => ['memory_mb' => $container->spec['memory_mb'] ?? null, 'cpus' => $container->spec['cpus'] ?? null, 'pids' => $container->spec['pids_limit'] ?? null],
            'user' => $container->spec['user'] ?? null,
            'restart_policy' => $container->spec['restart_policy'] ?? null,
        ]));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'server_id' => 'required|uuid',
            'name' => ['required', 'string', 'max:120', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/'],
            'image' => 'required|string|max:255',
            'registry_id' => 'nullable|uuid',
            'registry_auth' => 'nullable|array',
            'env' => 'nullable|array',
            'secrets' => 'nullable|array', 'secrets.*.secret_id' => 'required|uuid', 'secrets.*.env' => 'nullable|string',
            'ports' => 'nullable|array', 'ports.*.container_port' => 'required|integer|between:1,65535', 'ports.*.host_port' => 'nullable|integer|between:1,65535',
            'ports.*.protocol' => 'nullable|in:tcp,udp', 'ports.*.host_ip' => 'nullable|ip',
            'network' => 'nullable|string|max:120',
            'mounts' => 'nullable|array', 'mounts.*.type' => 'nullable|in:volume,bind,tmpfs', 'mounts.*.source' => 'required|string', 'mounts.*.target' => 'required|string',
            'limits' => 'nullable|array', 'limits.memory_mb' => 'nullable|integer|min:16', 'limits.cpus' => 'nullable|numeric|min:0.05',
            'restart_policy' => 'nullable|in:no,always,unless-stopped,on-failure',
            'healthcheck' => 'nullable|array',
            'user' => 'nullable|string|max:64',
            'read_only_rootfs' => 'nullable|boolean',
            'privileged' => 'nullable|boolean',
            'cap_add' => 'nullable|array',
            'cmd' => 'nullable|array',
            'labels' => 'nullable|array',
        ]);
        $server = Server::findOrFail($data['server_id']);

        return $this->accepted($this->containers->create($server, $data));
    }

    /** Ingress and agent containers: removing one takes the whole server offline. */
    private static function isInfrastructure(Container $container): bool
    {
        $name = strtolower((string) $container->name);
        $image = strtolower((string) $container->image);

        return str_contains($name, 'traefik') || str_starts_with($image, 'traefik:') || str_contains($name, 'dockermgr-agent');
    }

    public function action(Request $request, string $id)
    {
        $data = $request->validate(['action' => ['required', Rule::in(ContainerService::ACTIONS)], 'keep_volumes' => 'sometimes|boolean', 'timeout' => 'nullable|integer|min:0|max:600', 'approval_id' => 'nullable|uuid']);
        $container = Container::findOrFail($id);
        if (self::isInfrastructure($container) && in_array($data['action'], ['remove', 'stop', 'pause', 'kill'], true)) {
            return response()->json(['error' => [
                'code' => 'container_protected',
                'message' => "{$container->name} serves every site on this server, including this panel. Manage it from the server itself.",
            ]], 409);
        }
        $job = $this->containers->action($request->user(), $container, $data['action'], $data);

        return $this->accepted($job, ['container_id' => $container->id, 'action' => $data['action']]);
    }

    public function bulk(Request $request)
    {
        $data = $request->validate(['ids' => 'required|array|min:1|max:200', 'ids.*' => 'uuid', 'action' => ['required', Rule::in(['start', 'stop', 'restart', 'pause', 'unpause'])]]);
        $containers = Container::whereIn('id', $data['ids'])->get();
        abort_if($containers->count() !== count(array_unique($data['ids'])), 404);

        return $this->accepted($this->containers->bulk($request->user(), $containers, $data['action']), ['count' => $containers->count()]);
    }

    public function prunePreview(Request $request)
    {
        return $this->data($this->containers->prunePreview($request->input('server_id')));
    }

    public function prune(Request $request)
    {
        $request->validate(['server_id' => 'nullable|uuid', 'ids' => 'nullable|array', 'ids.*' => 'uuid']);
        if ($ids = $request->input('ids')) {
            $containers = Container::whereIn('id', $ids)->whereIn('state', ['exited', 'created', 'dead'])->get();

            return $this->accepted($this->containers->bulk($request->user(), $containers, 'remove'), ['count' => $containers->count()]);
        }

        return $this->accepted($this->containers->prune($request->input('server_id')));
    }

    public function logs(Request $request, string $id)
    {
        $container = Container::findOrFail($id);
        $this->authorizeFor($request, 'logs.view', $container);

        return $this->data($this->containers->logs($container, max(1, min(5000, (int) $request->query('tail', 200)))));
    }

    public function terminal(Request $request, string $id)
    {
        $container = Container::findOrFail($id);

        return $this->data($this->containers->openTerminal($request->user(), $container, $request->input('approval_id')), 201);
    }

    public function endTerminal(Request $request, string $id)
    {
        $session = TerminalSession::findOrFail($id);
        abort_unless($session->user_id === $request->user()->id || $request->user()->hasPermission('approvals.decide'), 403);

        return $this->data($this->containers->endTerminal($session));
    }
}
