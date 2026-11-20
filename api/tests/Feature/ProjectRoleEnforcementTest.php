<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\EnvVar;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Support\Tenant;
use Tests\TestCase;

/** PWS-B: project roles are enforced on the existing app, container, deploy and project endpoints. */
class ProjectRoleEnforcementTest extends TestCase
{
    private function fixture(): array
    {
        [, $org, $owner] = $this->org('owner', 'Acme');
        $server = $this->server($org);
        [$project, $app, $container] = app(Tenant::class)->as($org->id, function () use ($server) {
            $p = Project::create(['name' => 'Shop']);
            $a = Application::create(['name' => 'shop', 'source_type' => 'image', 'image' => 'nginx', 'port' => 80, 'server_id' => $server->id, 'project_id' => $p->id, 'status' => 'running', 'kind' => 'customer']);
            $c = Container::create(['application_id' => $a->id, 'server_id' => $server->id, 'name' => 'shop-web', 'image' => 'nginx', 'state' => 'running']);
            EnvVar::create(['application_id' => $a->id, 'key' => 'DB_PASSWORD', 'value' => 'x', 'secret' => true]);

            return [$p, $a, $c];
        });

        return [$org, $owner, $project, $app, $container];
    }

    private function withRole($org, $project, string $role): string
    {
        [$user, $token] = $this->member($org, 'developer');
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $user->id, 'role' => $role]);

        return $token;
    }

    public function test_a_viewer_reads_but_cannot_change_anything(): void
    {
        [$org, , $project, $app, $container] = $this->fixture();
        $viewer = $this->withRole($org, $project, 'viewer');

        $this->api($viewer)->getJson("/api/v1/app/apps/{$app->id}")->assertOk();
        $this->api($viewer)->patchJson("/api/v1/app/apps/{$app->id}", ['health_path' => '/up'])->assertForbidden();
        $this->api($viewer)->postJson("/api/v1/app/apps/{$app->id}/restart")->assertForbidden();
        $this->api($viewer)->postJson("/api/v1/app/apps/{$app->id}/rollback", ['release_id' => (string) \Illuminate\Support\Str::uuid()])->assertForbidden();
        $this->api($viewer)->deleteJson("/api/v1/app/apps/{$app->id}")->assertForbidden();
        $this->api($viewer)->patchJson("/api/v1/app/projects/{$project->id}", ['name' => 'X'])->assertForbidden();
        $this->api($viewer)->postJson("/api/v1/app/projects/{$project->id}/backups")->assertForbidden();
        $this->assertNotNull(Application::allTenants()->find($app->id));
        $this->assertGreaterThanOrEqual(5, AuditLog::query()->withoutGlobalScopes()->where('action', 'project.access.denied')->count());
    }

    public function test_a_developer_deploys_and_changes_only_permitted_settings(): void
    {
        [$org, , $project, $app] = $this->fixture();
        $dev = $this->withRole($org, $project, 'developer');

        $this->api($dev)->patchJson("/api/v1/app/apps/{$app->id}", ['health_path' => '/up'])->assertOk();
        $this->api($dev)->patchJson("/api/v1/app/apps/{$app->id}", ['env' => [['key' => 'APP_DEBUG', 'value' => '0']]])->assertForbidden(); // would drop the secret
        $this->api($dev)->patchJson("/api/v1/app/apps/{$app->id}", ['env' => [['key' => 'APP_DEBUG', 'value' => '0'], ['key' => 'DB_PASSWORD', 'value' => null]]])->assertOk();
        $this->api($dev)->patchJson("/api/v1/app/apps/{$app->id}", ['env' => [['key' => 'DB_PASSWORD', 'value' => null], ['key' => 'NEW', 'value' => 'v', 'secret' => true]]])->assertForbidden();
        $this->api($dev)->patchJson("/api/v1/app/apps/{$app->id}", ['env' => [['key' => 'DB_PASSWORD', 'value' => 'y']]])->assertForbidden();
        $this->assertTrue(EnvVar::allTenants()->where('application_id', $app->id)->where('key', 'DB_PASSWORD')->exists());
        $this->api($dev)->patchJson("/api/v1/app/apps/{$app->id}", ['size' => 'pro'])->assertForbidden();
        $this->assertNotSame(403, $this->api($dev)->postJson("/api/v1/app/apps/{$app->id}/restart")->status());
        $this->api($dev)->deleteJson("/api/v1/app/apps/{$app->id}")->assertForbidden();
        $this->api($dev)->putJson("/api/v1/app/projects/{$project->id}/members/{$org->id}", ['role' => 'owner'])->assertForbidden();
        $this->assertSame('/up', Application::allTenants()->find($app->id)->health_path);
    }

    public function test_the_admin_routes_apply_the_same_roles_and_unaffected_members_keep_org_rbac(): void
    {
        [$org, $owner, $project, $app, $container] = $this->fixture();
        $viewer = $this->withRole($org, $project, 'viewer');
        $this->api($viewer)->postJson("/api/v1/containers/{$container->id}/actions", ['action' => 'restart'])->assertForbidden();
        $this->api($viewer)->deleteJson("/api/v1/applications/{$app->id}")->assertForbidden();

        // The organisation owner is the project owner; a member without a project row keeps org RBAC.
        $this->api($owner)->patchJson("/api/v1/app/apps/{$app->id}", ['auto_deploy' => false])->assertOk();
        [, $plain] = $this->member($org, 'developer');
        $this->api($plain)->patchJson("/api/v1/app/apps/{$app->id}", ['health_path' => '/x'])->assertOk();
    }
}
