<?php

namespace Tests\Feature;

use App\Models\Container;
use App\Models\TerminalSession;
use App\Support\CommandSigner;
use App\Support\Tenant;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TerminalRelayTest extends TestCase
{
    private const SECRET = 'relay-test-secret-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        config(['security.relay.secret' => self::SECRET, 'security.relay.agent_url' => 'wss://panel.test/relay']);
        Storage::fake('local');
    }

    private function relay(): static
    {
        app(Tenant::class)->set(null);
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['X-Relay-Secret' => self::SECRET, 'Accept' => 'application/json', 'Authorization' => '']);
    }

    /** Owner, enrolled server with a running container, open session (approval gate off). */
    private function openSession(): array
    {
        [$user, $org, $token] = $this->org();
        $this->api($token)->patchJson('/api/v1/settings', ['security' => ['require_approval_destructive' => false]])->assertOk();
        $server = $this->server($org);
        [$secret, $hmac] = $this->enrollAgent($server, $token);
        $c = Container::allTenants()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'name' => 'web', 'image' => 'nginx', 'docker_id' => 'abc123', 'state' => 'running']);
        $s = $this->api($token)->postJson("/api/v1/containers/{$c->id}/terminal-sessions")->assertCreated()->json('data');
        parse_str(parse_url($s['ws_url'], PHP_URL_QUERY), $q);

        return compact('user', 'org', 'token', 'server', 'secret', 'hmac', 'c', 's') + ['wsToken' => $q['token']];
    }

    public function test_relay_endpoints_require_the_shared_secret(): void
    {
        $id = '00000000-0000-0000-0000-000000000000';
        $this->withHeaders(['Accept' => 'application/json'])->getJson("/internal/relay/terminal-sessions/{$id}")->assertStatus(401);
        $this->withHeaders(['X-Relay-Secret' => 'wrong-secret-0123456789'])->getJson("/internal/relay/terminal-sessions/{$id}")->assertStatus(401);
        config(['security.relay.secret' => null]);
        $this->relay()->getJson("/internal/relay/terminal-sessions/{$id}")->assertStatus(503);
    }

    public function test_connect_issues_signed_non_root_terminal_open_and_lifecycle_is_audited(): void
    {
        $x = $this->openSession();
        $this->assertStringStartsWith('/relay/terminal/'.$x['s']['session_id'].'?token=', $x['s']['ws_url']);
        $this->assertSame('/bin/sh', $x['s']['shell']);
        $id = $x['s']['session_id'];

        $this->relay()->postJson("/internal/relay/terminal-sessions/{$id}/connect", ['token' => 'wrong'])->assertStatus(401);
        $data = $this->relay()->postJson("/internal/relay/terminal-sessions/{$id}/connect", ['token' => $x['wsToken'], 'cols' => 120, 'rows' => 40, 'ip' => '203.0.113.9'])
            ->assertOk()->json('data');
        $this->assertSame($x['server']->id, $data['server_id']);
        $this->assertSame('abc123', $data['container_id']);
        $this->assertSame('app', $data['shell_user']);
        $this->assertTrue($data['recording']);
        $cmd = $data['command'];
        $this->assertSame('terminal.open', $cmd['type']);
        $this->assertSame(['session_id' => $id, 'container_id' => 'abc123', 'shell_user' => 'app', 'shell' => '/bin/sh', 'expires_at' => $cmd['payload']['expires_at'],
            'relay_url' => 'wss://panel.test/relay', 'cols' => 120, 'rows' => 40], $cmd['payload']);
        $this->assertSame(CommandSigner::sign($x['hmac'], $cmd['id'], $cmd['type'], $cmd['payload'], $cmd['issued_at']), $cmd['signature']);

        // Tokens are single use.
        $this->relay()->postJson("/internal/relay/terminal-sessions/{$id}/connect", ['token' => $x['wsToken']])->assertStatus(409);

        // The command is also delivered by the next heartbeat (fallback when the agent has no relay link),
        // and the heartbeat advertises the relay.
        $hb = $this->heartbeat($x['secret']);
        $this->assertSame('wss://panel.test/relay', $hb['relay_url']);
        $this->assertContains($cmd['id'], array_column($hb['commands'], 'id'));

        $this->relay()->getJson("/internal/relay/terminal-sessions/{$id}")->assertOk()->assertJsonPath('data.ended', false);
        // "End session" in the UI; the relay sees it on its next poll.
        $this->api($x['token'])->deleteJson("/api/v1/terminal-sessions/{$id}")->assertOk();
        $this->relay()->getJson("/internal/relay/terminal-sessions/{$id}")->assertOk()->assertJsonPath('data.ended', true);
        $this->relay()->postJson("/internal/relay/terminal-sessions/{$id}/connect", ['token' => $x['wsToken']])->assertStatus(410);
        $this->relay()->postJson("/internal/relay/terminal-sessions/{$id}/closed", ['reason' => 'ended'])->assertOk();

        $cast = json_encode(['version' => 2, 'width' => 120, 'height' => 40, 'timestamp' => time()])."\n".json_encode([0.1, 'o', "$ id\r\nuid=1000(app)\r\n"])."\n";
        $this->relay()->call('POST', "/internal/relay/terminal-sessions/{$id}/recording", [], [], [],
            ['HTTP_X_RELAY_SECRET' => self::SECRET, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/x-asciicast'], $cast)->assertOk();
        $this->relay()->call('POST', "/internal/relay/terminal-sessions/{$id}/recording", [], [], [],
            ['HTTP_X_RELAY_SECRET' => self::SECRET, 'HTTP_ACCEPT' => 'application/json'], "not a cast")->assertStatus(422);

        $list = $this->api($x['token'])->getJson('/api/v1/terminal-sessions')->assertOk()->json('data');
        $this->assertSame($id, $list[0]['id']);
        $this->assertSame('ended', $list[0]['status']);
        $this->assertSame('web', $list[0]['container_name']);
        $this->assertSame($x['user']->email, $list[0]['user_email']);
        $this->assertSame(strlen($cast), $list[0]['recording']['bytes']);
        $this->assertStringContainsString('uid=1000(app)', $this->api($x['token'])->getJson("/api/v1/terminal-sessions/{$id}/recording")->assertOk()->json('data.cast'));

        $actions = $this->api($x['token'])->getJson('/api/v1/audit-logs?per_page=100')->json('data.*.action');
        foreach (['terminal.opened', 'terminal.attached', 'terminal.closed'] as $a) {
            $this->assertContains($a, $actions);
        }
    }

    public function test_relay_close_reports_reason_and_expired_sessions_are_refused(): void
    {
        $x = $this->openSession();
        $id = $x['s']['session_id'];
        $this->relay()->postJson("/internal/relay/terminal-sessions/{$id}/closed", ['reason' => 'expired'])->assertOk();
        $this->assertSame('expired', TerminalSession::allTenants()->find($id)->close_reason);

        $y = $this->openSession();
        TerminalSession::allTenants()->whereKey($y['s']['session_id'])->update(['expires_at' => now()->subSecond()]);
        $this->relay()->postJson("/internal/relay/terminal-sessions/{$y['s']['session_id']}/connect", ['token' => $y['wsToken']])->assertStatus(410);
    }

    public function test_relay_agent_verification_and_terminal_needs_running_container(): void
    {
        $x = $this->openSession();
        $this->relay()->postJson('/internal/relay/agents/verify', ['agent_secret' => $x['secret']])->assertOk()->assertJsonPath('data.server_id', $x['server']->id);
        $this->relay()->postJson('/internal/relay/agents/verify', ['agent_secret' => 'nope'])->assertStatus(401);

        $stopped = Container::allTenants()->create(['organization_id' => $x['org']->id, 'server_id' => $x['server']->id, 'name' => 'db', 'image' => 'pg', 'docker_id' => 'def', 'state' => 'exited']);
        $this->api($x['token'])->postJson("/api/v1/containers/{$stopped->id}/terminal-sessions")->assertStatus(409);
    }
}
