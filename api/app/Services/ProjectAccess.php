<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;

/**
 * PWS: the project role model, enforced server side.
 *
 *  platform_admin  platform staff (super_admin, infra_admin, devops): everything
 *  owner           the owning organisation's owner, or a project owner: apps, domains, backups, firewall, members
 *  developer       deploy approved versions, read logs, permitted app settings
 *  viewer          read only
 */
class ProjectAccess
{
    public const PLATFORM_ROLES = ['super_admin', 'infra_admin', 'devops'];

    public const ABILITIES = [
        'platform_admin' => ['view', 'deploy', 'settings', 'manage', 'members', 'delete'],
        'owner' => ['view', 'deploy', 'settings', 'manage', 'members', 'delete'],
        'developer' => ['view', 'deploy', 'settings'],
        'viewer' => ['view'],
    ];

    public function role(Project $project, ?User $user): ?string
    {
        if (! $user) {
            return null;
        }
        $orgRoles = Membership::allTenants()->where('user_id', $user->id)->pluck('role', 'organization_id');
        if ($orgRoles->contains(fn ($r) => in_array($r, self::PLATFORM_ROLES, true))) {
            return 'platform_admin';
        }
        if (($orgRoles[$project->organization_id] ?? null) === 'owner') {
            return 'owner';
        }

        return ProjectMember::where('project_id', $project->id)->where('user_id', $user->id)->value('role');
    }

    public function abilities(?string $role): array
    {
        return self::ABILITIES[$role] ?? [];
    }

    /** 403 unless the user may do this on the project; returns the role. */
    public function authorize(Project $project, ?User $user, string $ability): string
    {
        $role = $this->role($project, $user);
        abort_unless(in_array($ability, $this->abilities($role), true), 403, $role ? "Your project role ({$role}) does not allow this." : 'You have no role on this project.');

        return $role;
    }
}
