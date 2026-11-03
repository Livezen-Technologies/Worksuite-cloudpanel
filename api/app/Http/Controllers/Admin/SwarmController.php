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
            'nodes' => $c->snapshot['nodes'] ?? [], 'services' => $c->snapshot['services'] ?? [],
            'refreshed_at' => $c->refreshed_at?->toIso8601ZuluString(), 'created_at' => $c->created_at?->toIso8601ZuluString(),
        ];
    }

    public function index()
    {
        return $this->data([
            'clusters' => SwarmCluster::orderBy('name')->get()->map(fn ($c) => $this->present($c))->values(),
            'available_servers' => Server::whereNull('swarm_cluster_id')->orderBy('name')->get(['id', 'name', 'ip'])->values(),
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
