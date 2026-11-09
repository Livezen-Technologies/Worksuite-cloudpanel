<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\SwarmCluster;
use App\Services\SwarmService;
use Illuminate\Http\Request;

/** SWARM: the admin Cluster page. */
class SwarmController extends Controller
{
    public function __construct(private SwarmService $swarm) {}

    private function present(SwarmCluster $c): array
    {
        return [
            'id' => $c->id, 'name' => $c->name, 'status' => $c->status, 'error' => $c->error, 'advertise_addr' => $c->advertise_addr,
            'manager_server_id' => $c->manager_server_id,
            'servers' => Server::where('swarm_cluster_id', $c->id)->orderBy('name')->get(['id', 'name', 'ip', 'swarm_role', 'swarm_node_id'])->values(),
            'nodes' => $c->snapshot['nodes'] ?? [], 'services' => $c->snapshot['services'] ?? [], 'secrets' => $c->snapshot['secrets'] ?? [],
            'refreshed_at' => $c->refreshed_at?->toIso8601ZuluString(), 'created_at' => $c->created_at?->toIso8601ZuluString(),
        ];
    }

    public function index()
    {
        return $this->data([
            'clusters' => SwarmCluster::orderBy('name')->get()->map(fn ($c) => $this->present($c))->values(),
            'available_servers' => Server::whereNull('swarm_cluster_id')->orderBy('name')->get(['id', 'name', 'ip'])->values(),
            // Apps whose builds push to a registry can be deployed here by their latest image.
            'apps' => \App\Models\Application::whereNotNull('current_release_id')->orderBy('name')->get(['id', 'name'])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $v = $request->validate(['name' => 'required|string|min:2|max:60', 'manager_server_id' => 'required|uuid']);
        [$c, $job] = $this->swarm->create($v['name'], Server::findOrFail($v['manager_server_id']));

        return $this->accepted($job, ['cluster' => $this->present($c)]);
    }

    public function join(Request $request, string $id)
    {
        $v = $request->validate(['server_id' => 'required|uuid']);

        return $this->accepted($this->swarm->join(SwarmCluster::findOrFail($id), Server::findOrFail($v['server_id'])));
    }

    public function leave(Request $request, string $id)
    {
        $v = $request->validate(['server_id' => 'required|uuid']);

        return $this->accepted($this->swarm->leave(SwarmCluster::findOrFail($id), Server::findOrFail($v['server_id'])));
    }

    public function refresh(string $id)
    {
        return $this->accepted($this->swarm->refresh(SwarmCluster::findOrFail($id)));
    }

    public function node(Request $request, string $id, string $nodeId)
    {
        $v = $request->validate(['availability' => 'required|in:active,pause,drain']);

        return $this->accepted($this->swarm->nodeAvailability(SwarmCluster::findOrFail($id), $nodeId, $v['availability']));
    }

    public function scale(Request $request, string $id, string $serviceId)
    {
        $v = $request->validate(['replicas' => 'required|integer|between:0,100']);

        return $this->accepted($this->swarm->scale(SwarmCluster::findOrFail($id), $serviceId, $v['replicas']));
    }

    public function storeService(Request $request, string $id)
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:63', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/'],
            'image' => ['required_without:application_id', 'nullable', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9._\/:@-]*$/i'],
            'application_id' => 'nullable|uuid',
            'replicas' => 'required|integer|between:0,100',
            'secrets' => 'nullable|array|max:20', 'secrets.*' => ['string', 'max:64', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/'],
            'mounts' => 'nullable|array|max:10', 'mounts.*.source' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/'],
            'mounts.*.target' => ['required', 'string', 'max:255', 'regex:#^/[A-Za-z0-9._/-]*$#', 'not_regex:#(^/$|\.\.|//)#'], 'mounts.*.read_only' => 'nullable|boolean',
            'env' => 'nullable|array', 'env.*' => 'nullable|string|max:4096',
            'ports' => 'nullable|array|max:10', 'ports.*.target' => 'required|integer|between:1,65535', 'ports.*.published' => 'required|integer|between:1,65535',
            'ports.*.protocol' => 'nullable|in:tcp,udp',
            'constraints' => 'nullable|array|max:10', 'constraints.*' => ['string', 'max:200', 'regex:/^(node\.role|node\.hostname|node\.labels\.[a-zA-Z0-9._-]{1,63})\s*(==|!=)\s*[a-zA-Z0-9._-]{1,63}$/'],
            'memory_mb' => 'nullable|integer|between:16,262144', 'cpus' => 'nullable|numeric|between:0.01,64',
        ]);
        foreach (array_keys($v['env'] ?? []) as $k) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $k)) {
                throw \App\Exceptions\ApiException::validation(['env' => ["Invalid variable name {$k}."]]);
            }
        }

        if (! empty($v['application_id'])) {
            $v['image'] = SwarmService::appImage($v['application_id']);
        }
        unset($v['application_id']);
        $c = SwarmCluster::findOrFail($id);
        $known = collect($c->snapshot['secrets'] ?? [])->pluck('name');
        if ($missing = collect($v['secrets'] ?? [])->diff($known)->values()->all()) {
            throw \App\Exceptions\ApiException::validation(['secrets' => ['Unknown secret: '.implode(', ', $missing).'. Create it first.']]);
        }

        return $this->accepted($this->swarm->createService($c, array_filter($v, fn ($x) => $x !== null)));
    }

    public function serviceImage(Request $request, string $id, string $serviceId)
    {
        $v = $request->validate(['image' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9._\/:@-]*$/i']]);

        return $this->accepted($this->swarm->updateImage(SwarmCluster::findOrFail($id), $serviceId, $v['image']));
    }

    public function destroyService(Request $request, string $id, string $serviceId)
    {
        $c = SwarmCluster::findOrFail($id);
        $name = collect($c->snapshot['services'] ?? [])->firstWhere('id', $serviceId)['name'] ?? null;
        if (! $name || trim((string) $request->input('confirm')) !== $name) {
            throw \App\Exceptions\ApiException::validation(['confirm' => ['Type the service name to remove it.']]);
        }

        return $this->accepted($this->swarm->removeService($c, $serviceId));
    }

    public function storeSecret(Request $request, string $id)
    {
        $v = $request->validate(['name' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/'], 'value' => 'required|string|max:512000']);

        return $this->accepted($this->swarm->createSecret(SwarmCluster::findOrFail($id), $v['name'], $v['value']));
    }

    public function destroySecret(string $id, string $secretId)
    {
        return $this->accepted($this->swarm->removeSecret(SwarmCluster::findOrFail($id), $secretId));
    }

    public function destroy(Request $request, string $id)
    {
        $c = SwarmCluster::findOrFail($id);
        if (trim((string) $request->input('confirm')) !== $c->name) {
            throw \App\Exceptions\ApiException::validation(['confirm' => ['Type the cluster name to delete it.']]);
        }
        $job = $this->swarm->destroy($c);

        return $job ? $this->accepted($job) : response()->noContent();
    }
}
