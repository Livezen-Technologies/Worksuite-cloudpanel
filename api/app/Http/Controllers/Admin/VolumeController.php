<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\StoragePool;
use App\Models\Volume;
use App\Services\ApprovalService;
use App\Services\CommandBus;
use App\Services\JobService;
use App\Support\Lists;
use Illuminate\Http\Request;

class VolumeController extends Controller
{
    public function __construct(private CommandBus $bus, private JobService $jobs) {}

    public function index(Request $request)
    {
        // Who it belongs to: the project, the apps whose containers mount it, and the owning organisation
        // (an app's, which on a shared server may be another customer's, else the volume's own).
        $all = fn ($q) => $q->withoutGlobalScope('organization');
        $query = Volume::withCount('mounts')->with([
            'organization:id,name', 'project:id,name,organization_id', 'project.organization:id,name',
            'mounts' => $all, 'mounts.container' => $all,
            'mounts.container.application' => fn ($q) => $q->withoutGlobalScope('organization')->select('id', 'name', 'project_id', 'organization_id'),
            'mounts.container.application.organization:id,name',
        ]);

        return response()->json(Lists::paginate($query, $request, ['name'], ['name', 'created_at', 'size_gb'], [
            'server_id', 'type', 'storage_pool_id',
            'unprotected' => fn ($q, $v) => $v ? $q->whereNotIn('id', \App\Models\BackupPolicy::where('target_type', 'volume')->whereNotNull('target_id')->select('target_id')) : $q,
            'project_id',
        ], function (Volume $v) {
            $apps = $v->mounts->map(fn ($m) => $m->container?->application)->filter()->unique('id')->values();
            if ($apps->isEmpty() && ! $v->project) {
                // The agent reports how many containers use a volume, not which, so infer from Docker's naming.
                [$inferApps, $inferProject] = $this->inferOwner($v);
                $apps = $inferApps;
                $v->setRelation('project', $inferProject);
            }
            $owner = $apps->first()?->organization ?? $v->project?->organization ?? $v->organization;
            $row = collect($v->toArray())->except(['mounts', 'project', 'organization'])->all();

            return $row + [
                'consumers' => $v->mounts_count, 'warning' => $v->has_backup_policy ? null : 'No backup policy',
                'project_name' => $v->project?->name,
                'applications' => $apps->pluck('name')->all(),
                'owner_name' => $owner?->name,
            ];
        }));
    }

    /** @var array<string, \Illuminate\Support\Collection> per-server containers, loaded once per request */
    private array $containersByServer = [];

    /**
     * Owner of a volume no mount record links: `db-<name>-data` is that database's volume, and
     * `<stack>_<name>` is a Compose volume of the containers labelled with that stack on the same server.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: ?\App\Models\Project}
     */
    private function inferOwner(Volume $v): array
    {
        $containers = $this->containersByServer[$v->server_id] ??= \App\Models\Container::allTenants()->where('server_id', $v->server_id)
            ->with(['application' => fn ($q) => $q->withoutGlobalScope('organization')->select('id', 'name', 'project_id', 'organization_id'),
                'application.organization:id,name', 'application.project:id,name,organization_id', 'project:id,name,organization_id'])
            ->get(['id', 'name', 'labels', 'application_id', 'project_id', 'server_id']);

        if (preg_match('/^db-(.+)-data$/', $v->name, $m)) {
            $matched = $containers->where('name', 'db-'.$m[1]);
        } elseif (str_contains($v->name, '_')) {
            $stack = strstr($v->name, '_', true);
            $matched = $containers->filter(fn ($c) => (((array) $c->labels)['com.docker.compose.project'] ?? null) === $stack);
        } else {
            $matched = collect();
        }

        $apps = $matched->map(fn ($c) => $c->application)->filter()->unique('id')->values();
        $project = $matched->map(fn ($c) => $c->project ?? $c->application?->project)->filter()->first();

        return [$apps, $project];
    }

    public function show(string $id)
    {
        return $this->data(Volume::with('mounts')->findOrFail($id));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'server_id' => 'required|uuid', 'name' => ['required', 'string', 'max:120', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/'],
            'type' => 'sometimes|in:volume,bind,nfs', 'storage_pool_id' => 'nullable|uuid',
            'quota_gb' => 'nullable|integer|min:1', 'nfs_server' => 'required_if:type,nfs|nullable|string', 'nfs_path' => 'required_if:type,nfs|nullable|string',
            'path' => 'required_if:type,bind|nullable|string|starts_with:/srv/dockermgr',
        ]);
        $server = Server::findOrFail($data['server_id']);
        $type = $data['type'] ?? 'volume';
        $driverOpts = match ($type) {
            'nfs' => ['type' => 'nfs', 'o' => 'addr='.$data['nfs_server'].',rw,nfsvers=4', 'device' => ':'.$data['nfs_path']],
            'bind' => ['type' => 'none', 'o' => 'bind', 'device' => $data['path']],
            default => $data['quota_gb'] ?? null ? ['size' => $data['quota_gb'].'G'] : [],
        };
        $volume = Volume::create(['server_id' => $server->id, 'name' => $data['name'], 'type' => $type, 'storage_pool_id' => $data['storage_pool_id'] ?? null,
            'quota_gb' => $data['quota_gb'] ?? null, 'options' => $driverOpts, 'status' => 'creating', 'mountpoint' => $type === 'bind' ? $data['path'] : null]);
        $job = $this->jobs->create('volume.create', ['execute'], $volume);
        $this->bus->dispatch($server, 'volume.create', array_filter(['name' => $volume->name, 'driver' => 'local', 'driver_opts' => $driverOpts ? (object) $driverOpts : null, 'labels' => (object) ['io.dockermgr.managed' => 'true']]), $job);

        return $this->accepted($job, ['volume' => $volume->fresh()]);
    }

    public function update(Request $request, string $id)
    {
        $volume = Volume::findOrFail($id);
        $volume->update($request->validate(['quota_gb' => 'nullable|integer|min:1', 'storage_pool_id' => 'nullable|uuid']));

        return $this->data($volume);
    }

    public function destroy(Request $request, string $id, ApprovalService $approvals)
    {
        $volume = Volume::findOrFail($id);
        $production = ($volume->server?->environment ?? 'production') === 'production';
        $production ? $approvals->gate($request->user(), 'production.delete', 'volume.delete', $volume, [], $request->input('approval_id')) : null;
        $job = $this->jobs->create('volume.remove', ['execute'], $volume);
        $this->bus->dispatch($volume->server, 'volume.remove', ['name' => $volume->name], $job);
        $volume->update(['status' => 'removing']);

        return $this->accepted($job);
    }

    public function pools(Request $request)
    {
        return response()->json(Lists::paginate(StoragePool::query(), $request, ['name'], ['name', 'capacity_gb'], ['type', 'server_id'],
            fn (StoragePool $p) => $p->toArray() + ['free_gb' => max(0, $p->capacity_gb - $p->used_gb), 'used_pct' => $p->capacity_gb ? round($p->used_gb / $p->capacity_gb * 100, 1) : 0]));
    }
}
