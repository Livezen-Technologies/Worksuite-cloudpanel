<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Container;
use App\Models\Network;
use App\Models\Server;
use App\Services\ApprovalService;
use App\Services\CommandBus;
use App\Services\JobService;
use App\Services\NetworkService;
use App\Support\Lists;
use App\Support\Tenant;
use Illuminate\Http\Request;

class NetworkController extends Controller
{
    public function __construct(private NetworkService $networks, private CommandBus $bus, private JobService $jobs) {}

    public function index(Request $request)
    {
        return response()->json(Lists::paginate(Network::query(), $request, ['name', 'subnet'], ['name', 'created_at', 'driver'], ['server_id', 'driver', 'status'],
            fn (Network $n) => $n->toArray() + ['containers' => null]));
    }

    public function show(string $id)
    {
        return $this->data(Network::findOrFail($id));
    }

    public function check(Request $request)
    {
        $data = $request->validate(['server_id' => 'required|uuid', 'name' => 'nullable|string', 'subnet' => 'nullable|string', 'driver' => 'nullable|string', 'ports' => 'nullable|array']);

        return $this->data($this->networks->check(Server::findOrFail($data['server_id']), $data));
    }

    public function store(Request $request, ApprovalService $approvals)
    {
        $data = $request->validate([
            'server_id' => 'required|uuid', 'name' => ['required', 'string', 'max:120', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/'],
            'driver' => 'sometimes|in:bridge,overlay,macvlan,ipvlan,host', 'subnet' => 'nullable|string', 'gateway' => 'nullable|ip',
            'internal' => 'sometimes|boolean', 'approval_id' => 'nullable|uuid',
        ]);
        $server = Server::findOrFail($data['server_id']);
        if (($data['driver'] ?? 'bridge') === 'host') {
            // NET-002: host networking only with approval and when the policy allows it.
            if (! Tenant::current()->setting('security.allow_host_network')) {
                throw ApiException::forbidden('Host networking is disabled in security settings.');
            }
            $approvals->gate($request->user(), 'network_storage.manage', 'network.host', $server, $data, $data['approval_id'] ?? null);
        }
        $check = $this->networks->check($server, $data);
        if (! $check['ok']) {
            throw ApiException::conflict($check['conflicts'][0]['message'], ['conflicts' => $check['conflicts']]);
        }
        $network = Network::create(['server_id' => $server->id, 'name' => $data['name'], 'driver' => $data['driver'] ?? 'bridge', 'subnet' => $data['subnet'] ?? null,
            'gateway' => $data['gateway'] ?? null, 'internal' => $data['internal'] ?? false, 'status' => 'creating']);
        $job = $this->jobs->create('network.create', ['execute'], $network);
        $this->bus->dispatch($server, 'network.create', array_filter(['name' => $network->name, 'driver' => $network->driver, 'internal' => $network->internal,
            'subnet' => $network->subnet, 'gateway' => $network->gateway, 'attachable' => true], fn ($v) => $v !== null), $job);

        return $this->accepted($job, ['network' => $network->fresh(), 'warnings' => $check['warnings']]);
    }

    public function update(Request $request, string $id)
    {
        $network = Network::findOrFail($id);
        $network->update($request->validate(['exposure' => 'sometimes|in:private,public']));

        return $this->data($network);
    }

    public function destroy(string $id)
    {
        $network = Network::findOrFail($id);
        if (in_array($network->name, ['bridge', 'host', 'none'], true)) {
            throw ApiException::conflict('Built-in Docker networks cannot be removed.');
        }
        $job = $this->jobs->create('network.remove', ['execute'], $network);
        $this->bus->dispatch($network->server, 'network.remove', ['name' => $network->name], $job);
        $network->update(['status' => 'removing']);

        return $this->accepted($job);
    }
}
