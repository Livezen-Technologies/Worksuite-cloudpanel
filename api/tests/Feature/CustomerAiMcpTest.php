<?php

namespace Tests\Feature;

use App\Models\AiAction;
use App\Models\Application;
use App\Models\Container;
use App\Support\Tenant;
use Tests\TestCase;

/** UAI: the MCP server, AI permission levels, approvals and scoped tokens. */
class CustomerAiMcpTest extends TestCase
{
    private function rpc(string $token, string $method, array $params = [], int $id = 1)
    {
        return $this->api($token)->postJson('/api/v1/app/mcp', ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
    }

    private function setup2(): array
    {
        [, $org, $session] = $this->org();
        $server = $this->server($org);
        $app = app(Tenant::class)->as($org->id, function () use ($server) {
            $a = Application::create(['name' => 'shop', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'size' => 'small', 'port' => 80, 'server_id' => $server->id]);
            Container::create(['server_id' => $server->id, 'application_id' => $a->id, 'name' => 'shop', 'image' => 'nginx', 'state' => 'running']);

            return $a;
        });

        return [$org, $session, $app];
    }

    public function test_an_mcp_client_lists_tools_and_reads_apps(): void
    {
        [, $session, $app] = $this->setup2();
        $tok = $this->api($session)->postJson('/api/v1/app/ai/tokens', ['name' => 'Cursor'])->assertCreated()->json('data.token');

        $this->rpc($tok, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'cursor', 'version' => '1']])
            ->assertOk()->assertJsonPath('result.protocolVersion', '2025-06-18')->assertJsonPath('result.capabilities.tools.listChanged', false);
        $this->api($tok)->postJson('/api/v1/app/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])->assertStatus(202);
        $names = array_column($this->rpc($tok, 'tools/list')->assertOk()->json('result.tools'), 'name');
        $this->assertContains('list_apps', $names);
        $this->assertContains('redeploy_app', $names);

        $r = $this->rpc($tok, 'tools/call', ['name' => 'list_apps', 'arguments' => (object) []])->assertOk();
        $this->assertFalse($r->json('result.isError'));
        $this->assertSame('shop', $r->json('result.structuredContent.result.0.name'));
        $this->rpc($tok, 'tools/call', ['name' => 'get_app', 'arguments' => ['app' => 'nope']])->assertJsonPath('result.isError', true);
        $this->rpc($tok, 'bogus')->assertJsonPath('error.code', -32601);

        $this->assertSame('Cursor', AiAction::allTenants()->latest()->first()->client);
        // The MCP token reaches nothing else.
        $this->api($tok)->getJson('/api/v1/app/apps')->assertForbidden();
    }

    public function test_changes_follow_the_ai_level_and_approvals(): void
    {
        [, $session, $app] = $this->setup2();
        $ro = $this->api($session)->postJson('/api/v1/app/ai/tokens', ['name' => 'Reader'])->json('data.token');
        $rw = $this->api($session)->postJson('/api/v1/app/ai/tokens', ['name' => 'Claude', 'write' => true])->json('data.token');

        // A read-only token never changes anything.
        $this->rpc($ro, 'tools/call', ['name' => 'restart_app', 'arguments' => ['app' => 'shop']])->assertJsonPath('result.isError', true);
        $this->assertSame('refused', AiAction::allTenants()->latest()->first()->status);

        // Default level "ask": the change waits for a person.
        $this->rpc($rw, 'tools/call', ['name' => 'restart_app', 'arguments' => ['app' => 'shop']])->assertJsonPath('result.isError', false);
        $pending = AiAction::allTenants()->where('status', 'pending')->sole();
        $this->assertSame(1, count($this->api($session)->getJson('/api/v1/app/ai')->assertOk()->json('data.pending')));
        $this->api($session)->postJson("/api/v1/app/ai/actions/{$pending->id}/approve")->assertOk()->assertJsonPath('data.status', 'done');
        $this->api($session)->postJson("/api/v1/app/ai/actions/{$pending->id}/reject")->assertStatus(409);

        // read_only refuses; autonomous runs straight away.
        $this->api($session)->patchJson('/api/v1/app/ai/settings', ['level' => 'read_only'])->assertOk();
        $this->rpc($rw, 'tools/call', ['name' => 'restart_app', 'arguments' => ['app' => 'shop']])->assertJsonPath('result.isError', true);
        $this->api($session)->patchJson('/api/v1/app/ai/settings', ['level' => 'autonomous'])->assertOk()->assertJsonPath('data.level', 'autonomous');
        $this->rpc($rw, 'tools/call', ['name' => 'restart_app', 'arguments' => ['app' => 'shop']])->assertJsonPath('result.structuredContent.result.app', 'shop');
        $this->api($session)->patchJson('/api/v1/app/ai/settings', ['level' => 'wild'])->assertStatus(422);
    }

    public function test_tokens_and_requests_stay_inside_the_account(): void
    {
        [, $sessionA] = $this->setup2();
        [, $sessionB] = $this->setup2();
        $t = $this->api($sessionA)->postJson('/api/v1/app/ai/tokens', ['name' => 'Mine', 'write' => true])->json('data');
        $this->rpc($t['token'], 'tools/call', ['name' => 'restart_app', 'arguments' => ['app' => 'shop']]);
        $pending = AiAction::allTenants()->where('status', 'pending')->sole();

        $this->api($sessionB)->postJson("/api/v1/app/ai/actions/{$pending->id}/approve")->assertNotFound();
        $this->api($sessionB)->deleteJson("/api/v1/app/ai/tokens/{$t['id']}")->assertNotFound();
        $this->assertSame([], $this->api($sessionB)->getJson('/api/v1/app/ai')->json('data.activity'));

        $this->api($sessionA)->deleteJson("/api/v1/app/ai/tokens/{$t['id']}")->assertNoContent();
        $this->rpc($t['token'], 'tools/list')->assertUnauthorized();
    }
}
