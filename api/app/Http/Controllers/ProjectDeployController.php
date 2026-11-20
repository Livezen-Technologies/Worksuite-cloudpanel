<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Deployment;
use App\Models\Job;
use App\Models\Project;
use App\Models\Release;
use App\Services\AuditLogger;
use App\Services\DeploymentService;
use App\Services\ProjectAccess;
use App\Support\Tenant;
use Illuminate\Http\Request;

/**
 * PWS-G: deploy jobs in the project workspace — progress by stage, failure details, retry and
 * rollback to an earlier release (the existing DeploymentService pipeline; a rollback is a deploy
 * of that release). Developers may retry and roll back; viewers only watch.
 */
class ProjectDeployController extends Controller
{
    public function __construct(private ProjectAccess $access, private AuditLogger $audit, private DeploymentService $deployments) {}

    private function project(Request $request, string $id, string $ability): array
    {
        $project = Project::find($id) ?? (($p = Project::allTenants()->find($id)) && $this->access->role($p, $request->user()) === 'platform_admin' ? $p : null);
        abort_unless($project, 404);

        return [$project, $this->access->authorize($project, $request->user(), $ability)];
    }

    public function index(Request $request, string $id)
    {
        [$project, $role] = $this->project($request, $id, 'view');
        $apps = Application::allTenants()->where('project_id', $project->id)->get(['id', 'name', 'current_release_id'])->keyBy('id');
        // Ids are UUIDv7, so they order by creation even within one second.
        $deps = Deployment::allTenants()->whereIn('application_id', $apps->keys())->orderByDesc('id')->limit(50)->get();
        $jobs = Job::allTenants()->whereIn('id', $deps->pluck('job_id')->filter())->get()->keyBy('id');
        $latest = $deps->groupBy('application_id')->map->first();

        return $this->data([
            'can_deploy' => in_array('deploy', $this->access->abilities($role), true),
            'deployments' => $deps->map(function (Deployment $d) use ($apps, $jobs, $latest) {
                $job = $jobs[$d->job_id] ?? null;

                return [
                    'id' => $d->id, 'application_id' => $d->application_id, 'application' => $apps[$d->application_id]->name ?? null,
                    'status' => $d->status, 'source' => $d->source, 'image' => $d->image, 'created_at' => $d->created_at,
                    'started_at' => $d->started_at, 'finished_at' => $d->finished_at,
                    'progress' => $job?->progress ?? ($d->status === 'succeeded' ? 100 : 0),
                    'stages' => $job?->stages ?? [], 'error' => $job?->error,
                    'logs_tail' => array_slice((array) ($d->logs_tail ?? []), -20),
                    // Only the newest deploy of an app is retried: retrying an old one would undo newer work.
                    'can_retry' => $d->status === 'failed' && ($latest[$d->application_id]->id ?? null) === $d->id,
                ];
            })->values(),
            'releases' => Release::allTenants()->whereIn('application_id', $apps->keys())->latest()->limit(100)->get()
                ->map(fn (Release $r) => ['id' => $r->id, 'application_id' => $r->application_id, 'version' => $r->version, 'image' => $r->image ?? null, 'created_at' => $r->created_at,
                    'current' => ($apps[$r->application_id]->current_release_id ?? null) === $r->id, 'available' => empty($r->config['image_deleted_at'])])->values(),
        ]);
    }

    public function retry(Request $request, string $id, string $deploymentId)
    {
        [$project] = $this->project($request, $id, 'deploy');
        $old = Deployment::allTenants()->findOrFail($deploymentId);
        $app = Application::allTenants()->where('project_id', $project->id)->findOrFail($old->application_id);
        abort_unless($old->status === 'failed', 409, 'Only a failed deployment can be retried.');
        abort_if(Deployment::allTenants()->where('application_id', $app->id)->where('id', '!=', $old->id)->where('id', '>', $old->id)->exists(), 409, 'A newer deployment exists; deploy again instead.');
        $release = $old->source === 'rollback' && $old->release_id ? Release::allTenants()->find($old->release_id) : null;
        $dep = app(Tenant::class)->as($project->organization_id, fn () => $this->deployments->start($app, $request->user(), $release ? 'rollback' : 'redeploy', $release));
        $this->audit->log('project.deploy.retry', 'project', $project->id, ['application_id' => $app->id, 'deployment_id' => $old->id, 'new_deployment_id' => $dep->id], $project->organization_id);

        return $this->accepted($dep->job, ['deployment_id' => $dep->id]);
    }

    public function rollback(Request $request, string $id)
    {
        [$project] = $this->project($request, $id, 'deploy');
        $data = $request->validate(['application_id' => 'required|uuid', 'release_id' => 'required|uuid']);
        $app = Application::allTenants()->where('project_id', $project->id)->findOrFail($data['application_id']);
        $release = Release::allTenants()->where('application_id', $app->id)->findOrFail($data['release_id']);
        abort_if(! empty($release->config['image_deleted_at']), 409, "Release v{$release->version}'s image was removed from the registry; redeploy from source instead.");
        $dep = app(Tenant::class)->as($project->organization_id, fn () => $this->deployments->start($app, $request->user(), 'rollback', $release));
        $this->audit->log('project.deploy.rollback', 'project', $project->id, ['application_id' => $app->id, 'release_id' => $release->id, 'deployment_id' => $dep->id], $project->organization_id);

        return $this->accepted($dep->job, ['deployment_id' => $dep->id]);
    }
}
