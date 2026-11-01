<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Container;
use App\Models\Server;
use App\Services\ApprovalService;
use App\Services\ServerService;
use App\Support\Lists;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServerController extends Controller
{
    public function __construct(private ServerService $servers) {}

    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => "{$req}|string|max:120",
            'hostname' => 'nullable|string|max:190',
            'ip' => 'nullable|ip',
            'environment' => ['sometimes', Rule::in(['production', 'staging', 'development'])],
            'region' => 'nullable|string|max:60',
            'tags' => 'nullable|array', 'tags.*' => 'string|max:40',
            'roles' => 'sometimes|array|min:1', 'roles.*' => 'in:app,builder',
            'provider' => 'nullable|string|max:40',
            'server_group_id' => 'nullable|uuid|exists:server_groups,id',
            'scheduling_weight' => 'sometimes|integer|min:0|max:1000',
            'max_containers' => 'nullable|integer|min:1|max:10000',
            'shared_pool' => 'sometimes|boolean',
        ];
    }

    public function index(Request $request)
    {
        $query = Server::withCount('containers');
        $statusFilter = $request->query('status');

        $result = Lists::paginate($query, $request, ['name', 'hostname', 'ip'], ['name', 'created_at', 'last_heartbeat_at', 'cpu_pct', 'ram_pct'], [
            'environment', 'region', 'server_group_id',
            'tag' => fn ($q, $v) => $q->whereRaw('CAST(tags AS TEXT) LIKE ?', ['%"'.$v.'"%']),
            'status' => fn ($q, $v) => match ($v) {
                'maintenance' => $q->where('maintenance', true),
                'pending' => $q->whereNull('last_heartbeat_at')->where('maintenance', false),
                'offline' => $q->where('maintenance', false)->where('last_heartbeat_at', '<', now()->subSeconds(config('platform.offline_after_seconds'))),
                default => $q->where('maintenance', false)->where('last_heartbeat_at', '>=', now()->subSeconds(config('platform.offline_after_seconds'))),
            },
        ]);
        if (in_array($statusFilter, ['online', 'warning'], true)) {
            $result['data'] = $result['data']->filter(fn ($s) => $s->status === $statusFilter)->values();
        }

        return response()->json($result);
    }

    public function store(Request $request)
    {
        $server = Server::create($request->validate($this->rules(true)));

        return $this->data($server->fresh(), 201);
    }

    /** SRV-UPD: send the agent update to every online server running an older agent. */
    public function updateAllAgents(Request $request)
    {
        $targets = Server::all()->filter(fn (Server $s) => $s->agent_outdated && $s->isOnline() && ! $s->maintenance);
        $jobs = [];
        $skipped = [];
        foreach ($targets as $server) {
            // One server that cannot take the command must not stop the rest.
            try {
                $jobs[] = ['server_id' => $server->id, 'name' => $server->name, 'job_id' => $this->servers->agentCommand($server, 'update')->id];
            } catch (\App\Exceptions\ApiException $e) {
                $skipped[] = ['server_id' => $server->id, 'name' => $server->name, 'reason' => $e->getMessage()];
            }
        }
        app(\App\Services\AuditLogger::class)->log('servers.agent_update_all', 'servers', null, ['servers' => count($jobs), 'skipped' => count($skipped), 'version' => Server::latestAgentVersion()]);

        return $this->data(['version' => Server::latestAgentVersion(), 'updating' => $jobs, 'skipped' => $skipped]);
    }

    public function show(string $id)
    {
        return $this->data(Server::withCount('containers')->with('agent:id,server_id,version,enrolled_at,last_seen_at')->findOrFail($id));
    }

    public function update(Request $request, string $id)
    {
        $server = Server::findOrFail($id);
        $server->update($request->validate($this->rules(false)));

        return $this->data($server->fresh());
    }

    public function destroy(Request $request, string $id, ApprovalService $approvals)
    {
        $server = Server::findOrFail($id);
        $server->environment === 'production'
            ? $approvals->gate($request->user(), 'production.delete', 'server.delete', $server, [], $request->input('approval_id'))
            : $this->authorizeFor($request, 'servers.manage');
        // Deleting removes its volume records and strands its apps and databases: refuse while anything lives there.
        $left = app(\App\Services\ServerDrainService::class)->residents($server);
        if ($left['apps'] || $left['databases']) {
            throw \App\Exceptions\ApiException::conflict(sprintf('%s still runs %d app(s) and %d database(s). Drain it first, then move databases and apps with data volumes with a backup and restore.',
                $server->name, count($left['apps']), count($left['databases'])), ['residents' => $left]);
        }
        $server->delete();

        return response()->noContent();
    }

    public function enrollment(Request $request)
    {
        $data = $request->validate(['server_id' => 'required|uuid']);

        return $this->data($this->servers->createEnrollment(Server::findOrFail($data['server_id'])), 201);
    }

    /** Move this server's stateless apps elsewhere and list what has to be moved by hand. */
    public function drain(Request $request, string $id)
    {
        $server = Server::findOrFail($id);
        $this->authorizeFor($request, 'servers.manage');

        return $this->data(app(\App\Services\ServerDrainService::class)->drain($server, $request->user()));
    }

    /**
     * Cut a server's agent off (compromised host, machine handed back): its secret and client
     * certificates stop working at once. The server waits for a fresh enrolment token.
     */
    public function revokeAgent(Request $request, string $id)
    {
        $server = Server::findOrFail($id);
        $this->authorizeFor($request, 'servers.manage');
        $request->validate(['confirm' => 'required|in:'.$server->name]);
        $agent = \App\Models\Agent::allTenants()->where('server_id', $server->id)->first();
        if ($agent) {
            \App\Models\AgentCertificate::allTenants()->where('agent_id', $agent->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $agent->delete();
        }
        $server->forceFill(['last_heartbeat_at' => null])->save();
        app(\App\Services\AuditLogger::class)->log('server.agent_revoked', 'servers', $server->id, ['name' => $server->name, 'had_agent' => (bool) $agent]);

        return $this->data($server->fresh());
    }

    public function residents(string $id)
    {
        return $this->data(app(\App\Services\ServerDrainService::class)->residents(Server::findOrFail($id)));
    }

    public function maintenance(Request $request, string $id)
    {
        $data = $request->validate(['enabled' => 'required|boolean']);
        $server = Server::findOrFail($id);
        $server->update(['maintenance' => $data['enabled']]);

        return $this->data($server->fresh());
    }

    public function agent(string $id, string $action)
    {
        abort_unless(in_array($action, ['restart', 'update'], true), 404);

        return $this->accepted($this->servers->agentCommand(Server::findOrFail($id), $action));
    }

    public function verification(string $id)
    {
        return $this->data($this->servers->verification(Server::with('agent')->findOrFail($id)));
    }

    public function containers(Request $request, string $id)
    {
        $server = Server::findOrFail($id);

        return response()->json(Lists::paginate(Container::where('server_id', $server->id), $request, ['name', 'image'], ['name', 'state', 'cpu_pct', 'mem_mb', 'created_at'], ['state', 'health']));
    }
}
