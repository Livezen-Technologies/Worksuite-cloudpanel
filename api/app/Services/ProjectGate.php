<?php

namespace App\Services;

use App\Models\Application;
use App\Models\BackupJob;
use App\Models\Container;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * PWS-B: which project a request touches, which project ability it needs, and whether the
 * caller's project role allows it.
 *
 * Who is checked: platform admins and the owning organisation's owner always pass. Anyone with a
 * project_members row is held to that row (viewer read only, developer deploy/logs/permitted
 * settings, owner everything inside the project). Organisation members with no row keep the
 * organisation RBAC they had before (owner decision recorded in the changelog), so nobody loses
 * access on upgrade; an owner restricts a person by giving them a project role.
 *
 * Refusals are audited (project.access.denied) so a probe of someone else's project leaves a trail.
 */
class ProjectGate
{
    /** Route names (without the app. prefix) whose mutation is a deploy, not a settings change. */
    public const DEPLOY = [
        'deployments.store', 'deployments.cancel', 'applications.rollback', 'apps.rollback', 'apps.redeploy',
        'apps.restart', 'containers.action', 'projects.deploy-jobs.retry', 'projects.deploy-jobs.rollback',
    ];

    public const DELETE = ['applications.destroy', 'apps.destroy', 'projects.destroy'];

    public const MEMBERS = [
        'projects.members.set', 'projects.members.remove', 'projects.transfer', 'projects.transfer.preview',
        'projects.dns.permissions', 'projects.dns.permissions.update', 'projects.dns.permissions.revoke',
    ];

    /** App fields a developer may change ("permitted app settings"): non-secret env, health, restart. */
    public const DEVELOPER_APP_FIELDS = ['env', 'health_path', 'auto_restart', 'restart'];

    public function __construct(private ProjectAccess $access, private AuditLogger $audit) {}

    public function check(Request $request): void
    {
        $route = $request->route();
        $name = $route?->getName();
        if (! $name || ! $request->user()) {
            return;
        }
        $name = Str::after($name, 'app.') === $name ? $name : Str::after($name, 'app.');
        $project = $this->project($name, $route->uri(), $route->parameters(), $request);
        if (! $project) {
            return;
        }
        $ability = $this->ability($name, $request);
        $role = $this->access->role($project, $request->user());
        if (in_array($role, ['platform_admin', 'owner'], true)) {
            return;
        }
        if ($role === null) {
            return; // No project role: the organisation RBAC already decided.
        }
        $allowed = in_array($ability, $this->access->abilities($role), true);
        if ($allowed && $ability === 'settings') {
            $allowed = $this->developerFieldsOnly($request);
        }
        if (! $allowed) {
            $this->audit->log('project.access.denied', 'project', $project->id, ['route' => $name, 'ability' => $ability, 'role' => $role], $project->organization_id);
            abort(403, "Your project role ({$role}) does not allow this.");
        }
    }

    public function ability(string $name, Request $request): string
    {
        if (in_array($name, self::MEMBERS, true)) {
            return 'members';
        }
        if ($request->isMethodSafe()) {
            return 'view';
        }
        if (in_array($name, self::DELETE, true)) {
            return 'delete';
        }
        if (in_array($name, self::DEPLOY, true)) {
            return 'deploy';
        }
        if (in_array($name, ['applications.update', 'apps.update'], true)) {
            return 'settings';
        }

        return 'manage';
    }

    /** A developer's app PATCH may only touch the permitted fields, and no secret variables. */
    private function developerFieldsOnly(Request $request): bool
    {
        $keys = array_keys($request->except(['_method']));
        if (array_diff($keys, self::DEVELOPER_APP_FIELDS)) {
            return false;
        }
        $app = Application::allTenants()->find($request->route('id'));
        $secretKeys = $app ? \App\Models\EnvVar::allTenants()->where('application_id', $app->id)->where('secret', true)->pluck('key')->all() : [];
        if (! $request->has('env')) {
            return true;
        }
        // env is a full replace: a developer must leave every secret in place (listed, value null)
        // and may not create one.
        $sent = collect((array) $request->input('env', []));
        foreach ($sent as $var) {
            if (! empty($var['secret'])) {
                return false;
            }
            if (in_array($var['key'] ?? null, $secretKeys, true) && ($var['value'] ?? null) !== null) {
                return false;
            }
        }

        return ! array_diff($secretKeys, $sent->pluck('key')->all());
    }

    /** The project the request touches, from the route's id or (for creates) the body. */
    public function project(string $name, string $uri, array $params, Request $request): ?Project
    {
        $id = $params['id'] ?? null;
        $base = Str::after($uri, 'api/v1/');
        $base = Str::startsWith($base, 'app/') ? Str::after($base, 'app/') : $base;
        $head = Str::before($base, '/');

        $projectId = match (true) {
            $id && $head === 'projects' => $id,
            $id && in_array($head, ['applications', 'apps'], true) => $this->appProject($id),
            $id && $head === 'containers' => $this->containerProject($id),
            $id && $head === 'deployments' => $this->appProject(Deployment::allTenants()->whereKey($id)->value('application_id')),
            $id && $head === 'environments' => Environment::allTenants()->whereKey($id)->value('project_id'),
            $id && in_array($head, ['project-backups', 'backups'], true) => $this->backupProject($id),
            ! $id && in_array($name, ['deployments.store'], true) => $this->appProject($request->input('application_id')),
            ! $id && in_array($name, ['applications.store', 'apps.store', 'containers.store', 'templates.install'], true) => $request->input('project_id'),
            default => null,
        };

        return $projectId ? Project::allTenants()->find($projectId) : null;
    }

    private function appProject(?string $appId): ?string
    {
        return $appId && Str::isUuid($appId) ? Application::allTenants()->whereKey($appId)->value('project_id') : null;
    }

    private function containerProject(string $id): ?string
    {
        $c = Container::allTenants()->whereKey($id)->first(['application_id', 'project_id']);

        return $c ? ($c->project_id ?: $this->appProject($c->application_id)) : null;
    }

    private function backupProject(string $id): ?string
    {
        $b = BackupJob::allTenants()->whereKey($id)->first(['application_id', 'project_id']);

        return $b ? ($b->project_id ?: $this->appProject($b->application_id)) : null;
    }
}
