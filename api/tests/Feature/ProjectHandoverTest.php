<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Project;
use Tests\TestCase;

/** PRJ-9: staff hand a project, its environments and apps over to a hosting customer. */
class ProjectHandoverTest extends TestCase
{
    public function test_staff_hand_a_project_with_its_apps_to_a_customer(): void
    {
        [, $staffOrg, $staff] = $this->org('super_admin');
        $server = $this->server($staffOrg);
        [$secret] = $this->enrollAgent($server, $staff);
        $this->heartbeat($secret);
        $project = $this->api($staff)->postJson('/api/v1/projects', ['name' => 'Shop'])->assertCreated()->json('data');
        $env = collect($project['environments'])->firstWhere('kind', 'production');
        $appId = $this->api($staff)->postJson('/api/v1/applications', ['name' => 'shop-web', 'source_type' => 'image', 'image' => 'nginx:1.27', 'server_id' => $server->id, 'port' => 80, 'environment_id' => $env['id']])
            ->assertCreated()->json('data.id');
        [, $customerOrg, $customer] = $this->org('owner', 'Acme Ltd');
        $this->assertCount(0, $this->api($customer)->getJson('/api/v1/projects')->json('data'));

        $this->api($staff)->getJson("/api/v1/projects/{$project['id']}/transfer")->assertOk()->assertJsonPath('data.applications', 1);
        $this->api($staff)->postJson("/api/v1/projects/{$project['id']}/transfer", ['organization_id' => $customerOrg->id, 'confirm' => 'Wrong'])->assertStatus(422);
        $this->api($staff)->postJson("/api/v1/projects/{$project['id']}/transfer", ['organization_id' => $staffOrg->id, 'confirm' => 'Shop'])->assertStatus(409);
        $this->api($staff)->postJson("/api/v1/projects/{$project['id']}/transfer", ['organization_id' => $customerOrg->id, 'confirm' => 'Shop'])
            ->assertOk()->assertJsonPath('data.moved.applications', 1)->assertJsonPath('data.organization.name', 'Acme Ltd');

        $p = Project::allTenants()->find($project['id']);
        $this->assertSame($customerOrg->id, $p->organization_id);
        $app = Application::allTenants()->find($appId);
        $this->assertSame([$customerOrg->id, 'customer'], [$app->organization_id, $app->kind]);
        $this->assertSame(0, \App\Models\Environment::allTenants()->where('project_id', $p->id)->where('organization_id', '!=', $customerOrg->id)->count());
        $this->assertSame(['Shop'], collect($this->api($customer)->getJson('/api/v1/projects')->json('data'))->pluck('name')->all(), 'the customer now manages it');
        $this->assertSame(2, AuditLog::query()->withoutGlobalScopes()->where('action', 'project.handover')->count(), 'both organisations get an audit entry');
    }

    public function test_customers_cannot_hand_projects_over(): void
    {
        [, , $owner] = $this->org();
        $project = $this->api($owner)->postJson('/api/v1/projects', ['name' => 'Mine'])->assertCreated()->json('data');
        [, $other] = $this->org();
        $this->api($owner)->postJson("/api/v1/projects/{$project['id']}/transfer", ['organization_id' => $other->id, 'confirm' => 'Mine'])->assertForbidden();
    }
}
