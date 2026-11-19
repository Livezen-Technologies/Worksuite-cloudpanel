<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\BackupJob;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\EnvVar;
use App\Models\Membership;
use App\Models\Network;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ProjectAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * PWS: the project workspace. Everything here is looked up by project id (never by name) and
 * gated by the caller's project role; every change is audited. Admin (/projects) and customer
 * (/app/projects) routes share it.
 */
class ProjectWorkspaceController extends Controller
{
    public function __construct(private ProjectAccess $access, private AuditLogger $audit) {}

    public function show(Request $request, string $id)
    {
        $project = Project::findOrFail($id);
        $role = $this->access->authorize($project, $request->user(), 'view');
        $appIds = Application::allTenants()->where('project_id', $project->id)->pluck('id');
        $apps = Application::allTenants()->whereIn('id', $appIds)->get(['id', 'name'])->keyBy('id');

        return $this->data([
            'role' => $role,
            'abilities' => $this->access->abilities($role),
            'domains' => Domain::allTenants()->where(fn ($q) => $q->where('project_id', $project->id)->orWhereIn('application_id', $appIds))
                ->orderBy('name')->get()->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'status' => $d->status, 'application' => $apps[$d->application_id]->name ?? null])->values(),
            'deployments' => Deployment::allTenants()->whereIn('application_id', $appIds)->latest()->limit(25)->get()
                ->map(fn ($d) => ['id' => $d->id, 'status' => $d->status, 'application' => $apps[$d->application_id]->name ?? null, 'application_id' => $d->application_id, 'created_at' => $d->created_at])->values(),
            'networks' => Network::allTenants()->where('project_id', $project->id)->orderBy('name')->get()
                ->map(fn ($n) => ['id' => $n->id, 'name' => $n->name, 'scope' => $n->scope, 'status' => $n->status])->values(),
            // Keys only: values (and secrets) stay on the app's own page.
            'variables' => EnvVar::allTenants()->whereIn('application_id', $appIds)->orderBy('key')->get()
                ->map(fn ($v) => ['key' => $v->key, 'secret' => (bool) $v->secret, 'application' => $apps[$v->application_id]->name ?? null, 'application_id' => $v->application_id])->values(),
            'activity' => AuditLog::query()->withoutGlobalScopes()->where(fn ($q) => $q->where('resource_id', $project->id)->orWhereIn('resource_id', $appIds))
                ->orderByDesc('id')->limit(25)->get(['id', 'actor', 'action', 'resource_type', 'created_at'])->values(),
            'members' => $this->members($project),
            'delete_blockers' => $this->blockers($project),
        ]);
    }

    public function members(Project $project): array
    {
        return ProjectMember::where('project_id', $project->id)->with('user:id,name,email')->get()
            ->map(fn ($m) => ['user_id' => $m->user_id, 'name' => $m->user?->name, 'email' => $m->user?->email, 'role' => $m->role])->values()->all();
    }

    /** Why the project cannot be deleted yet (active apps, backups). */
    public function blockers(Project $project): array
    {
        $out = [];
        if (($n = Application::allTenants()->where('project_id', $project->id)->count()) > 0) {
            $out[] = "{$n} application".($n === 1 ? '' : 's');
        }
        if (($n = BackupJob::allTenants()->where('target_type', 'project')->where('project_id', $project->id)->count()) > 0) {
            $out[] = "{$n} backup".($n === 1 ? '' : 's');
        }

        return $out;
    }

    public function setMember(Request $request, string $id, string $userId)
    {
        $project = Project::findOrFail($id);
        $this->access->authorize($project, $request->user(), 'members');
        $v = $request->validate(['role' => ['required', Rule::in(ProjectMember::ROLES)]]);
        $user = User::findOrFail($userId);
        // Only people of the owning organisation can be given a role on its project.
        abort_unless(Membership::allTenants()->where('organization_id', $project->organization_id)->where('user_id', $user->id)->exists(), 422, 'Add the person to the organisation first.');
        ProjectMember::updateOrCreate(['project_id' => $project->id, 'user_id' => $user->id], ['role' => $v['role']]);
        $this->audit->log('project.member.set', 'project', $project->id, ['user_id' => $user->id, 'role' => $v['role']], $project->organization_id);

        return $this->data($this->members($project));
    }

    public function removeMember(Request $request, string $id, string $userId)
    {
        $project = Project::findOrFail($id);
        $this->access->authorize($project, $request->user(), 'members');
        ProjectMember::where('project_id', $project->id)->where('user_id', $userId)->delete();
        $this->audit->log('project.member.remove', 'project', $project->id, ['user_id' => $userId], $project->organization_id);

        return $this->data($this->members($project));
    }
}
