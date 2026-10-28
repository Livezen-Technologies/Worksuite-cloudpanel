<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Container;
use App\Support\Tenant;
use Tests\TestCase;

class RbacTest extends TestCase
{
    public function test_viewer_can_read_but_not_mutate(): void
    {
        [, $org] = $this->org();
        [, $viewer] = $this->member($org, 'viewer');
        $this->server($org);

        $this->api($viewer)->getJson('/api/v1/servers')->assertOk()->assertJsonPath('meta.total', 1);
        $this->api($viewer)->postJson('/api/v1/servers', ['name' => 'x'])->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        $this->api($viewer)->getJson('/api/v1/users')->assertForbidden();
        $this->api($viewer)->getJson('/api/v1/audit-logs')->assertOk();
    }

    public function test_billing_manager_cannot_see_servers_or_deploy(): void
    {
        [, $org] = $this->org();
        [, $billing] = $this->member($org, 'billing');
        $this->api($billing)->getJson('/api/v1/servers')->assertForbidden();
        $this->api($billing)->postJson('/api/v1/deployments', ['application_id' => '00000000-0000-0000-0000-000000000000'])->assertForbidden();
        $this->api($billing)->getJson('/api/v1/app/billing')->assertOk();
        $this->api($billing)->getJson('/api/v1/app/apps')->assertForbidden();
    }

    public function test_developer_only_sees_assigned_apps(): void
    {
        [$owner, $org] = $this->org();
        [$dev, $devToken] = $this->member($org, 'developer');
        $server = $this->server($org);
        [$mine, $other] = app(Tenant::class)->as($org->id, fn () => [
            Application::create(['name' => 'mine', 'source_type' => 'image', 'image' => 'nginx:1']),
            Application::create(['name' => 'other', 'source_type' => 'image', 'image' => 'nginx:1']),
        ]);
        $mine->members()->attach($dev->id);
        $c = Container::allTenants()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'application_id' => $other->id, 'name' => 'c-other', 'image' => 'nginx', 'docker_id' => 'd1', 'state' => 'running']);

        $this->api($devToken)->getJson('/api/v1/applications')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'mine');
        $this->api($devToken)->getJson("/api/v1/applications/{$other->id}")->assertForbidden();
        $this->api($devToken)->postJson('/api/v1/deployments', ['application_id' => $other->id])->assertForbidden();
        $this->api($devToken)->postJson("/api/v1/containers/{$c->id}/actions", ['action' => 'restart'])->assertForbidden();
        $this->api($devToken)->getJson('/api/v1/servers')->assertForbidden();
    }

    public function test_api_token_abilities_limit_access(): void
    {
        [$owner, $org, $token] = $this->org();
        $readOnly = $this->api($token)->postJson('/api/v1/api-tokens', ['name' => 'ci', 'abilities' => ['read'], 'expires_at' => now()->addDay()->toIso8601String()])
            ->assertCreated()->assertJsonStructure(['data' => ['token', 'expires_at']])->json('data.token');
        $this->app['auth']->forgetGuards();
        $this->api($readOnly)->getJson('/api/v1/servers')->assertOk();
        $this->api($readOnly)->postJson('/api/v1/servers', ['name' => 'nope'])->assertForbidden();

        $this->api($token)->postJson('/api/v1/api-tokens', ['name' => 'evil', 'abilities' => ['made.up']])->assertStatus(422);
    }

    public function test_role_matrix_endpoint(): void
    {
        [, , $token] = $this->org();
        $matrix = collect($this->api($token)->getJson('/api/v1/roles/devops')->assertOk()->json('data.permissions'))->keyBy('name');
        $this->assertTrue($matrix['terminal']['granted']);
        $this->assertTrue($matrix['terminal']['requires_approval']);
        $this->assertFalse($matrix['billing.manage']['granted']);
        $this->assertCount(8, $this->api($token)->getJson('/api/v1/roles')->json('data'));
    }
}
