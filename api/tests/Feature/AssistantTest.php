<?php

namespace Tests\Feature;

use App\Contracts\ChatModel;
use App\Drivers\FakeChatModel;
use App\Models\AiAction;
use App\Models\Application;
use App\Models\Container;
use App\Support\Tenant;
use Tests\TestCase;

/** UAI: the built-in assistant uses the MCP tools, the AI level and approvals. */
class AssistantTest extends TestCase
{
    private FakeChatModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new FakeChatModel;
        $this->app->instance(ChatModel::class, $this->model);
    }

    private function account(): array
    {
        [$user, $org, $token] = $this->org();
        $server = $this->server($org);
        app(Tenant::class)->as($org->id, function () use ($server) {
            $a = Application::create(['name' => 'shop', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'size' => 'small', 'port' => 80, 'server_id' => $server->id]);
            Container::create(['server_id' => $server->id, 'application_id' => $a->id, 'name' => 'shop', 'image' => 'nginx', 'state' => 'running']);
        });

        return [$user, $org, $token];
    }

    public function test_the_assistant_reads_with_tools_and_keeps_the_conversation(): void
    {
        [, , $token] = $this->account();
        $this->model->use('list_apps', [])->say('You have one app, shop, and it is running.');

        $r = $this->api($token)->postJson('/api/v1/app/ai/assistant', ['message' => 'What apps do I have?'])->assertOk()->json('data');
        $this->assertSame('You have one app, shop, and it is running.', $r['reply']);
        $this->assertSame('done', $r['actions'][0]['status']);

        // The tool result went back to the model as a tool_result for that tool_use.
        $second = $this->model->requests[1]['messages'];
        $this->assertSame('tool_result', end($second)['content'][0]['type']);
        $this->assertSame('tu_1', end($second)['content'][0]['toolUseID']);
        $this->assertStringContainsString('shop', end($second)['content'][0]['content']);
        $this->assertContains('restart_app', array_column($this->model->requests[0]['tools'], 'name'));

        // The next message continues the same conversation.
        $this->model->say('Still just shop.');
        $this->api($token)->postJson('/api/v1/app/ai/assistant', ['message' => 'And now?'])->assertOk();
        $this->assertCount(5, $this->model->requests[2]['messages'], 'user, assistant tool_use, tool_result, assistant, user');
        $this->assertCount(4, $this->api($token)->getJson('/api/v1/app/ai/assistant')->json('data.messages'));

        $this->api($token)->deleteJson('/api/v1/app/ai/assistant')->assertNoContent();
        $this->assertSame([], $this->api($token)->getJson('/api/v1/app/ai/assistant')->json('data.messages'));
    }

    public function test_changes_from_the_assistant_wait_for_approval(): void
    {
        [, , $token] = $this->account();
        $this->model->use('restart_app', ['app' => 'shop'])->say('I asked to restart shop; it is waiting for your approval.');
        $r = $this->api($token)->postJson('/api/v1/app/ai/assistant', ['message' => 'Restart shop'])->assertOk()->json('data');
        $this->assertSame('pending', $r['actions'][0]['status']);
        $this->assertSame('Assistant', AiAction::allTenants()->sole()->client);

        $this->api($token)->patchJson('/api/v1/app/ai/settings', ['level' => 'read_only'])->assertOk();
        $this->model->use('restart_app', ['app' => 'shop'], 'tu_2')->say('I cannot change anything in read-only mode.');
        $r = $this->api($token)->postJson('/api/v1/app/ai/assistant', ['message' => 'Restart it anyway'])->assertOk()->json('data');
        $this->assertSame('refused', $r['actions'][0]['status']);
        $this->assertTrue(end($this->model->requests[3]['messages'])['content'][0]['isError']);
    }

    public function test_refusals_limits_switches_and_isolation(): void
    {
        [, , $token] = $this->account();
        $this->model->refuse();
        $this->api($token)->postJson('/api/v1/app/ai/assistant', ['message' => 'something'])->assertOk()->assertJsonPath('data.reply', "I can't help with that request.");

        $this->api($token)->patchJson('/api/v1/app/ai/settings', ['assistant' => false])->assertOk();
        $this->api($token)->postJson('/api/v1/app/ai/assistant', ['message' => 'hi'])->assertStatus(409);
        $this->api($token)->patchJson('/api/v1/app/ai/settings', ['assistant' => true])->assertOk();

        config(['platform.ai.max_turns_per_day' => 3]);
        $this->api($token)->postJson('/api/v1/app/ai/assistant', ['message' => 'two'])->assertOk();
        $this->api($token)->postJson('/api/v1/app/ai/assistant', ['message' => 'three'])->assertOk();
        $this->api($token)->postJson('/api/v1/app/ai/assistant', ['message' => 'four'])->assertStatus(409)->assertJsonPath('error.reason', 'ai_daily_limit');

        // Another account sees none of it.
        [, , $other] = $this->org();
        $this->assertSame([], $this->api($other)->getJson('/api/v1/app/ai/assistant')->json('data.messages'));

        // Without a key the assistant is off.
        config(['platform.ai.driver' => 'anthropic', 'platform.ai.api_key' => null]);
        $this->api($other)->getJson('/api/v1/app/ai')->assertJsonPath('data.assistant.available', false);
        $this->api($other)->postJson('/api/v1/app/ai/assistant', ['message' => 'hi'])->assertStatus(409);
    }

    public function test_admins_see_the_ai_setup_and_activity(): void
    {
        [, , $token] = $this->account();
        $this->model->use('list_apps', [])->say('ok');
        $this->api($token)->postJson('/api/v1/app/ai/assistant', ['message' => 'list'])->assertOk();
        $d = $this->api($token)->getJson('/api/v1/ai')->assertOk()->json('data');
        $this->assertSame('claude-opus-5-5', $d['assistant']['model']);
        $this->assertSame(1, $d['last_24h']['tool_calls']);
        $this->assertSame(1, $d['last_24h']['conversations']);
        $this->assertArrayNotHasKey('api_key', $d['assistant']);
    }
}
