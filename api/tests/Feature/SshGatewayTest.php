<?php

namespace Tests\Feature;

use App\Models\Container;
use App\Models\TerminalSession;
use App\Models\UserSshKey;
use App\Support\SshKeys;
use App\Support\Tenant;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** SSH-GW: personal SSH keys and shells through the relay's SSH listener. */
class SshGatewayTest extends TestCase
{
    /** Built at run time so the source holds no secret-looking literal. */
    private static function secret(string $prefix = 'relay'): string
    {
        return $prefix.'-'.str_repeat('s', 24);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['security.relay.secret' => self::secret()]);
        config(['security.relay.agent_url' => 'wss://panel.test/relay']);
        config(['security.ssh_gateway.enabled' => true, 'security.ssh_gateway.host' => 'panel.test']);
        Storage::fake('local');
    }

    private function relay(): static
    {
        app(Tenant::class)->set(null);
        $this->app['auth']->forgetGuards();

        $headers = ['Accept' => 'application/json', 'Authorization' => ''];
        $headers['X-Relay-Secret'] = self::secret();

        return $this->withHeaders($headers);
    }

    private function setUpOrg(string $role = 'owner'): array
    {
        [$user, $org, $token] = $this->org($role);
        $server = $this->server($org);
        $this->enrollAgent($server, $token);
        $c = Container::allTenants()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'name' => 'shop-web', 'image' => 'nginx', 'docker_id' => 'abc123', 'state' => 'running']);

        return [$user, $org, $token, $c];
    }

    public function test_people_add_generate_and_delete_their_own_keys(): void
    {
        [, , $token] = $this->setUpOrg();
        $this->api($token)->getJson('/api/v1/me/ssh-keys')->assertOk()->assertJsonPath('data.gateway', ['enabled' => true, 'host' => 'panel.test', 'port' => 2222])->assertJsonPath('data.keys', []);

        $g = $this->api($token)->postJson('/api/v1/me/ssh-keys/generate', ['name' => 'laptop'])->assertCreated()->json('data');
        $this->assertStringContainsString('OPENSSH '.'PRIVATE KEY', $g['private_key']);
        $this->assertStringStartsWith('ssh-ed25519 ', $g['public_key']);
        $this->assertArrayNotHasKey('private_key', $this->api($token)->getJson('/api/v1/me/ssh-keys')->json('data.keys.0'), 'the private half is shown once');
        $this->assertSame(1, UserSshKey::count());
        $this->assertFalse(\Schema::hasColumn('user_ssh_keys', 'private_key'), 'never stored');

        $pasted = SshKeys::generate('desktop')['public'];
        $this->api($token)->postJson('/api/v1/me/ssh-keys', ['name' => 'desktop', 'public_key' => "  {$pasted}\n"])->assertCreated()->assertJsonPath('data.public_key', $pasted);
        $this->api($token)->postJson('/api/v1/me/ssh-keys', ['name' => 'again', 'public_key' => $pasted])->assertStatus(409);
        foreach (['not a key', 'ssh-ed25519 !!!', 'ssh-rsa '.base64_encode(pack('N', 11).'ssh-ed25519'.'x')] as $bad) {
            $this->api($token)->postJson('/api/v1/me/ssh-keys', ['name' => 'bad', 'public_key' => $bad])->assertStatus(422);
        }

        [, , $other] = $this->org();
        $this->api($other)->deleteJson("/api/v1/me/ssh-keys/{$g['id']}")->assertNotFound();
        $this->api($token)->deleteJson("/api/v1/me/ssh-keys/{$g['id']}")->assertNoContent();
        $this->assertSame(1, UserSshKey::count());
    }

    public function test_a_registered_key_opens_a_recorded_terminal_session_on_the_named_container(): void
    {
        [$user, $org, $token, $c] = $this->setUpOrg();
        $this->api($token)->patchJson('/api/v1/settings', ['security' => ['require_approval_destructive' => false]])->assertOk();
        $fp = $this->api($token)->postJson('/api/v1/me/ssh-keys/generate', ['name' => 'laptop'])->json('data.fingerprint');

        $this->relay()->postJson('/internal/relay/ssh/session', ['fingerprint' => $fp, 'target' => 'list'])->assertOk()
            ->assertJsonPath('data.targets', ['shop-web +'.$org->slug]);

        $s = $this->relay()->postJson('/internal/relay/ssh/session', ['fingerprint' => $fp, 'target' => 'shop-web', 'ip' => '203.0.113.7'])->assertOk()->json('data');
        $this->assertSame(['shop-web', $user->email], [$s['container'], $s['user']]);
        $session = TerminalSession::allTenants()->find($s['session_id']);
        $this->assertSame([$user->id, $c->id, '203.0.113.7', hash('sha256', $s['token'])], [$session->user_id, $session->container_id, $session->ip, $session->token_hash]);
        $this->assertNotSame('root', $session->shell_user);

        // The relay then connects it exactly as it does a browser.
        $this->relay()->postJson("/internal/relay/terminal-sessions/{$s['session_id']}/connect", ['token' => $s['token'], 'cols' => 120, 'rows' => 40])->assertOk();
        $this->assertNotNull(UserSshKey::first()->last_used_at);

        // Name+org works; unknown names and keys are refused; no secret, no entry.
        $this->relay()->postJson('/internal/relay/ssh/session', ['fingerprint' => $fp, 'target' => 'shop-web+'.$org->slug])->assertOk();
        $this->relay()->postJson('/internal/relay/ssh/session', ['fingerprint' => $fp, 'target' => 'nope'])->assertNotFound();
        $this->relay()->postJson('/internal/relay/ssh/session', ['fingerprint' => $fp, 'target' => 'bad name;'])->assertStatus(422);
        $this->relay()->postJson('/internal/relay/ssh/session', ['fingerprint' => 'SHA256:'.str_repeat('A', 43), 'target' => 'shop-web'])->assertStatus(401);
        $wrong = ['X-Relay-Secret' => self::secret('wrong')];
        $this->withHeaders($wrong)->postJson('/internal/relay/ssh/session', ['fingerprint' => $fp, 'target' => 'shop-web'])->assertStatus(401);
    }

    public function test_permissions_and_approvals_apply_as_in_the_panel(): void
    {
        [, $org, $token, $c] = $this->setUpOrg();
        // Approval on (the default): the first try asks for it, the next try after approval goes through.
        $fp = $this->api($token)->postJson('/api/v1/me/ssh-keys/generate', ['name' => 'laptop'])->json('data.fingerprint');
        $this->relay()->postJson('/internal/relay/ssh/session', ['fingerprint' => $fp, 'target' => 'shop-web'])->assertStatus(403)->assertJsonPath('error.code', 'approval_required');
        $approval = \App\Models\Approval::allTenants()->where('action', 'terminal.open')->sole();
        $approval->forceFill(['status' => 'approved'])->save();
        $this->relay()->postJson('/internal/relay/ssh/session', ['fingerprint' => $fp, 'target' => 'shop-web'])->assertOk();
        $this->relay()->postJson('/internal/relay/ssh/session', ['fingerprint' => $fp, 'target' => 'shop-web'])->assertStatus(403);

        // A role without the terminal permission sees nothing.
        [, $viewerToken] = $this->member($org, 'viewer');
        $vfp = $this->api($viewerToken)->postJson('/api/v1/me/ssh-keys/generate', ['name' => 'viewer-key'])->json('data.fingerprint');
        $this->relay()->postJson('/internal/relay/ssh/session', ['fingerprint' => $vfp, 'target' => 'list'])->assertOk()->assertJsonPath('data.targets', []);
        $this->relay()->postJson('/internal/relay/ssh/session', ['fingerprint' => $vfp, 'target' => 'shop-web'])->assertNotFound();
    }
}
