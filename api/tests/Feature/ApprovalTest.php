<?php

namespace Tests\Feature;

use App\Models\AgentCommand;
use App\Models\Container;
use Tests\TestCase;

class ApprovalTest extends TestCase
{
    public function test_production_container_removal_needs_second_approver(): void
    {
        [, $org, $owner] = $this->org();
        [, $second] = $this->member($org, 'owner');
        $server = $this->server($org, ['environment' => 'production']);
        $c = Container::allTenants()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'name' => 'db', 'image' => 'postgres', 'docker_id' => 'd9', 'state' => 'running']);

        $approvalId = $this->api($owner)->postJson("/api/v1/containers/{$c->id}/actions", ['action' => 'remove', 'keep_volumes' => false])
            ->assertStatus(202)->assertJsonPath('data.status', 'pending_approval')->json('data.approval_id');
        $this->assertSame(0, AgentCommand::allTenants()->count());

        $this->api($owner)->postJson("/api/v1/approvals/{$approvalId}/approve")->assertForbidden();
        $this->api($second)->postJson("/api/v1/approvals/{$approvalId}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $command = AgentCommand::allTenants()->first();
        $this->assertSame('container.remove', $command->type);
        $this->assertSame(['container_id' => 'd9', 'keep_volumes' => false, 'force' => true], $command->payload);
        $this->api($second)->postJson("/api/v1/approvals/{$approvalId}/approve")->assertStatus(409);
    }

    public function test_infra_admin_cannot_delete_production_and_setting_disables_approval(): void
    {
        [, $org, $owner] = $this->org();
        [, $infra] = $this->member($org, 'infra_admin');
        $server = $this->server($org);
        $c = Container::allTenants()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'name' => 'web', 'image' => 'nginx', 'docker_id' => 'd1', 'state' => 'running']);
        $this->api($infra)->postJson("/api/v1/containers/{$c->id}/actions", ['action' => 'remove'])->assertForbidden();
        $this->api($infra)->postJson("/api/v1/containers/{$c->id}/actions", ['action' => 'restart'])->assertStatus(202)->assertJsonStructure(['data' => ['job_id']]);

        $this->api($owner)->patchJson('/api/v1/settings', ['security' => ['require_approval_destructive' => false]])->assertOk()->assertJsonPath('data.security.require_approval_destructive', false);
        $this->api($owner)->postJson("/api/v1/containers/{$c->id}/actions", ['action' => 'remove'])->assertStatus(202)->assertJsonStructure(['data' => ['job_id']]);
    }

    public function test_terminal_session_after_approval(): void
    {
        [, $org, $owner] = $this->org();
        [, $second] = $this->member($org, 'owner');
        $server = $this->server($org);
        $c = Container::allTenants()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'name' => 'web', 'image' => 'nginx', 'docker_id' => 'd1', 'state' => 'running']);
        $approvalId = $this->api($owner)->postJson("/api/v1/containers/{$c->id}/terminal-sessions")->assertStatus(202)->json('data.approval_id');
        $this->api($second)->postJson("/api/v1/approvals/{$approvalId}/approve")->assertOk();
        $session = $this->api($owner)->postJson("/api/v1/containers/{$c->id}/terminal-sessions", ['approval_id' => $approvalId])->assertCreated()
            ->assertJsonStructure(['data' => ['session_id', 'ws_url', 'expires_at']])->json('data');
        $this->api($owner)->postJson("/api/v1/containers/{$c->id}/terminal-sessions", ['approval_id' => $approvalId])->assertForbidden();
        $this->api($owner)->deleteJson("/api/v1/terminal-sessions/{$session['session_id']}")->assertOk();
        $actions = $this->api($owner)->getJson('/api/v1/audit-logs?per_page=100')->json('data.*.action');
        $this->assertContains('terminal.opened', $actions);
        $this->assertContains('terminal.closed', $actions);
    }
}
