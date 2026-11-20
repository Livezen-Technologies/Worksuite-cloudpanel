<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Database;
use App\Models\Project;
use App\Models\Secret;
use App\Models\Server;
use App\Models\Volume;
use App\Services\AuditLogger;
use App\Services\CommandBus;
use App\Services\DatabaseService;
use App\Services\JobService;
use App\Services\ProjectAccess;
use App\Support\Tenant;
use Illuminate\Http\Request;

/**
 * PWS-C: a project's databases and volumes. Everything is looked up by project id inside the
 * project's own organisation, so one customer never sees another's; passwords are masked and only
 * revealed through an audited, owner-only call. Admin and /app routes share it.
 */
class ProjectResourceController extends Controller
{
    public function __construct(private ProjectAccess $access, private AuditLogger $audit, private DatabaseService $databases) {}

    private function project(Request $request, string $id, string $ability): array
    {
        // Platform staff reach any organisation's project; everyone else only their own (404 otherwise).
        $project = Project::find($id) ?? (($p = Project::allTenants()->find($id)) && $this->access->role($p, $request->user()) === 'platform_admin' ? $p : null);
        abort_unless($project, 404);

        return [$project, $this->access->authorize($project, $request->user(), $ability)];
    }

    /** Nodes the project may place on: servers already running its apps; staff may pick any server. */
    public function nodes(Project $project, string $role): \Illuminate\Support\Collection
    {
        $ids = Application::allTenants()->where('project_id', $project->id)->whereNotNull('server_id')->pluck('server_id')->unique();
        $q = Server::allTenants();
        $role === 'platform_admin' ? $q->where(fn ($w) => $w->whereIn('id', $ids)->orWhere('organization_id', $project->organization_id)) : $q->whereIn('id', $ids);

        return $q->orderBy('name')->get(['id', 'name']);
    }

    private function db(Project $project, string $dbId): Database
    {
        return Database::allTenants()->where('organization_id', $project->organization_id)->where('project_id', $project->id)->findOrFail($dbId);
    }

    public function databases(Request $request, string $id)
    {
        [$project, $role] = $this->project($request, $id, 'view');
        $rows = Database::allTenants()->where('project_id', $project->id)->where('organization_id', $project->organization_id)->orderBy('name')->get();

        return $this->data([
            'databases' => $rows->map(fn (Database $d) => [
                'id' => $d->id, 'name' => $d->name, 'engine' => $d->engine, 'version' => $d->version, 'environment' => $d->environment,
                'status' => $d->status, 'size_mb' => $d->size_mb, 'last_backup_at' => $d->last_backup_at,
                'connection' => $this->databases->connection($d),
            ])->values(),
            'attachable' => in_array('manage', $this->access->abilities($role), true)
                ? Database::allTenants()->where('organization_id', $project->organization_id)->whereNull('project_id')->orderBy('name')->get(['id', 'name', 'engine'])
                : [],
            'nodes' => $this->nodes($project, $role),
            'engines' => array_keys(config('platform.database_templates')),
        ]);
    }

    public function storeDatabase(Request $request, string $id)
    {
        [$project, $role] = $this->project($request, $id, 'manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9][a-z0-9_-]*$/'], 'engine' => 'required|in:'.implode(',', array_keys(config('platform.database_templates'))),
            'version' => 'nullable|string|max:20', 'server_id' => 'required|uuid', 'environment' => 'sometimes|in:production,staging,development',
        ]);
        abort_unless($this->nodes($project, $role)->contains('id', $data['server_id']), 422, 'Choose a node this project already runs on.');
        app(\App\Services\ProjectQuotaService::class)->assert($project, null, ['containers' => 1]);
        $result = app(Tenant::class)->as($project->organization_id, fn () => $this->databases->create($data + ['project_id' => $project->id, 'any_tenant_server' => true]));
        $this->audit->log('project.database.create', 'project', $project->id, ['database_id' => $result['database']['id'], 'engine' => $data['engine']], $project->organization_id);

        return response()->json(['data' => ['job_id' => $result['job_id'], 'database' => $result['database']]], 202);
    }

    public function attachDatabase(Request $request, string $id, string $dbId)
    {
        [$project] = $this->project($request, $id, 'manage');
        $db = Database::allTenants()->where('organization_id', $project->organization_id)->whereNull('project_id')->findOrFail($dbId);
        $db->update(['project_id' => $project->id]);
        $this->audit->log('project.database.attach', 'project', $project->id, ['database_id' => $db->id], $project->organization_id);

        return $this->databases($request, $id);
    }

    public function detachDatabase(Request $request, string $id, string $dbId)
    {
        [$project] = $this->project($request, $id, 'manage');
        $this->db($project, $dbId)->update(['project_id' => null]);
        $this->audit->log('project.database.detach', 'project', $project->id, ['database_id' => $dbId], $project->organization_id);

        return $this->databases($request, $id);
    }

    /** The real password: owners only, every read audited. */
    public function credentials(Request $request, string $id, string $dbId)
    {
        [$project] = $this->project($request, $id, 'manage');
        $db = $this->db($project, $dbId);
        $secret = $db->secret_id ? Secret::allTenants()->where('organization_id', $project->organization_id)->find($db->secret_id) : null;
        $this->audit->log('project.database.credentials', 'project', $project->id, ['database_id' => $db->id], $project->organization_id);
        $conn = $this->databases->connection($db);
        $password = $secret?->value;

        return $this->data(array_merge($conn, ['password' => $password, 'url' => $password ? str_replace('••••••••', rawurlencode($password), $conn['url']) : $conn['url']]));
    }

    /** PWS-D: usage against quota; only platform staff change the limits (owners work within them). */
    public function quotas(Request $request, string $id)
    {
        [$project, $role] = $this->project($request, $id, 'view');

        return $this->data(['quotas' => app(\App\Services\ProjectQuotaService::class)->report($project), 'can_edit' => $role === 'platform_admin']);
    }

    public function setQuotas(Request $request, string $id)
    {
        [$project, $role] = $this->project($request, $id, 'view');
        abort_unless($role === 'platform_admin', 403, 'Only platform administrators set project quotas.');
        $keys = array_merge(\App\Services\ProjectQuotaService::PROJECT_KEYS, \App\Services\ProjectQuotaService::CONTAINER_KEYS);
        $data = $request->validate(collect($keys)->mapWithKeys(fn ($k) => [$k => 'nullable|numeric|min:0|max:1000000'])->all());
        $quotas = collect($keys)->mapWithKeys(fn ($k) => [$k => isset($data[$k]) ? (float) $data[$k] : null])->filter(fn ($v) => $v !== null)->all();
        $before = $project->quotas;
        $project->forceFill(['quotas' => $quotas ?: null])->save();
        $this->audit->log('project.quotas.set', 'project', $project->id, ['before' => $before, 'after' => $quotas], $project->organization_id);

        return $this->quotas($request, $id);
    }

    public function volumes(Request $request, string $id)
    {
        [$project, $role] = $this->project($request, $id, 'view');
        $rows = Volume::allTenants()->where('project_id', $project->id)->where('organization_id', $project->organization_id)->withCount('mounts')->orderBy('name')->get();

        return $this->data([
            'volumes' => $rows->map(fn (Volume $v) => ['id' => $v->id, 'name' => $v->name, 'type' => $v->type, 'status' => $v->status,
                'size_gb' => $v->size_gb, 'quota_gb' => $v->quota_gb, 'mounts' => $v->mounts_count, 'server_id' => $v->server_id])->values(),
            'attachable' => in_array('manage', $this->access->abilities($role), true)
                ? Volume::allTenants()->where('organization_id', $project->organization_id)->whereNull('project_id')->orderBy('name')->get(['id', 'name'])
                : [],
            'nodes' => $this->nodes($project, $role),
        ]);
    }

    public function storeVolume(Request $request, string $id, CommandBus $bus, JobService $jobs)
    {
        [$project, $role] = $this->project($request, $id, 'manage');
        $data = $request->validate(['server_id' => 'required|uuid', 'name' => ['required', 'string', 'max:120', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/'], 'quota_gb' => 'nullable|integer|min:1|max:10000']);
        abort_unless($this->nodes($project, $role)->contains('id', $data['server_id']), 422, 'Choose a node this project already runs on.');
        app(\App\Services\ProjectQuotaService::class)->assert($project, null, ['storage_gb' => (float) ($data['quota_gb'] ?? 0)]);
        $server = Server::allTenants()->findOrFail($data['server_id']);
        abort_if(Volume::allTenants()->where('server_id', $server->id)->where('name', $data['name'])->exists(), 422, 'A volume with that name already exists on this node.');
        [$volume, $job] = app(Tenant::class)->as($project->organization_id, function () use ($data, $server, $project, $bus, $jobs) {
            $opts = ($data['quota_gb'] ?? null) ? ['size' => $data['quota_gb'].'G'] : [];
            $volume = Volume::create(['server_id' => $server->id, 'project_id' => $project->id, 'name' => $data['name'], 'type' => 'volume', 'quota_gb' => $data['quota_gb'] ?? null, 'options' => $opts, 'status' => 'creating']);
            $job = $jobs->create('volume.create', ['execute'], $volume);
            $bus->dispatch($server, 'volume.create', array_filter(['name' => $volume->name, 'driver' => 'local', 'driver_opts' => $opts ? (object) $opts : null, 'labels' => (object) ['io.dockermgr.managed' => 'true', 'io.dockermgr.project' => $project->id]]), $job);

            return [$volume, $job];
        });
        $this->audit->log('project.volume.create', 'project', $project->id, ['volume_id' => $volume->id], $project->organization_id);

        return $this->accepted($job, ['volume' => $volume->fresh()]);
    }

    public function attachVolume(Request $request, string $id, string $volumeId)
    {
        [$project] = $this->project($request, $id, 'manage');
        $v = Volume::allTenants()->where('organization_id', $project->organization_id)->whereNull('project_id')->findOrFail($volumeId);
        $v->update(['project_id' => $project->id]);
        $this->audit->log('project.volume.attach', 'project', $project->id, ['volume_id' => $v->id], $project->organization_id);

        return $this->volumes($request, $id);
    }

    public function detachVolume(Request $request, string $id, string $volumeId)
    {
        [$project] = $this->project($request, $id, 'manage');
        Volume::allTenants()->where('organization_id', $project->organization_id)->where('project_id', $project->id)->findOrFail($volumeId)->update(['project_id' => null]);
        $this->audit->log('project.volume.detach', 'project', $project->id, ['volume_id' => $volumeId], $project->organization_id);

        return $this->volumes($request, $id);
    }
}
