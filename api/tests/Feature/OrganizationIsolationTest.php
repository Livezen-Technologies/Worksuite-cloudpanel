<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Container;
use App\Models\Server;
use App\Support\Tenant;
use Tests\TestCase;

/** SRS 9.1: organisation A cannot read or change organisation B resources. */
class OrganizationIsolationTest extends TestCase
{
    public function test_org_cannot_read_or_change_another_orgs_resources(): void
    {
        [, $orgA, $tokenA] = $this->org();
        [, $orgB, $tokenB] = $this->org();
        $serverA = $this->server($orgA, ['name' => 'a-server']);
        $containerA = Container::allTenants()->create(['organization_id' => $orgA->id, 'server_id' => $serverA->id, 'name' => 'a-ctr', 'image' => 'nginx', 'docker_id' => 'abc', 'state' => 'running']);
        $appA = app(Tenant::class)->as($orgA->id, fn () => Application::create(['name' => 'a-app', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx:1', 'size' => 'small']));

        $this->api($tokenB)->getJson('/api/v1/servers')->assertOk()->assertJsonPath('meta.total', 0);
        $this->api($tokenB)->getJson("/api/v1/servers/{$serverA->id}")->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->api($tokenB)->patchJson("/api/v1/servers/{$serverA->id}", ['name' => 'pwned'])->assertNotFound();
        $this->api($tokenB)->deleteJson("/api/v1/servers/{$serverA->id}")->assertNotFound();
        $this->api($tokenB)->postJson('/api/v1/servers/enrollment', ['server_id' => $serverA->id])->assertNotFound();
        $this->api($tokenB)->getJson("/api/v1/containers/{$containerA->id}")->assertNotFound();
        $this->api($tokenB)->postJson("/api/v1/containers/{$containerA->id}/actions", ['action' => 'stop'])->assertNotFound();
        $this->api($tokenB)->postJson('/api/v1/containers/bulk-actions', ['ids' => [$containerA->id], 'action' => 'stop'])->assertNotFound();
        $this->api($tokenB)->getJson("/api/v1/app/apps/{$appA->id}")->assertNotFound();
        $this->api($tokenB)->patchJson("/api/v1/app/apps/{$appA->id}", ['auto_restart' => false])->assertNotFound();
        $this->api($tokenB)->postJson('/api/v1/app/domains', ['domain' => 'x.example.com', 'app_id' => $appA->id])->assertNotFound();
        $this->api($tokenB)->getJson('/api/v1/containers')->assertJsonPath('meta.total', 0);
        $this->api($tokenB)->getJson('/api/v1/search?q=a-')->assertOk()->assertJsonMissing(['label' => 'a-server']);

        $this->assertSame('a-server', Server::allTenants()->find($serverA->id)->name);
        $this->api($tokenA)->getJson("/api/v1/servers/{$serverA->id}")->assertOk()->assertJsonPath('data.name', 'a-server');
    }

    public function test_switching_token_org_requires_membership(): void
    {
        [$userA, , $tokenA] = $this->org();
        [, $orgB] = $this->org();
        $userA->forceFill(['current_organization_id' => $orgB->id])->save();
        $token = $userA->createToken('session')->plainTextToken; // no org pinned on token → current org B, not a member
        $this->api($token)->getJson('/api/v1/servers')->assertForbidden();
    }
}
