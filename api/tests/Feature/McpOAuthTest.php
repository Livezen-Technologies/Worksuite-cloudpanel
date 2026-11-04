<?php

namespace Tests\Feature;

use App\Models\AccessToken;
use App\Models\Application;
use App\Support\Tenant;
use Tests\TestCase;

/** UAI: MCP clients sign in with OAuth 2.1 (discovery, dynamic registration, PKCE, refresh rotation). */
class McpOAuthTest extends TestCase
{
    private const CB = 'https://claude.ai/api/mcp/auth_callback';

    private function pkce(): array
    {
        $verifier = str_repeat('v', 20).bin2hex(random_bytes(16));

        return [$verifier, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
    }

    private function rpc(string $token)
    {
        return $this->api($token)->postJson('/api/v1/app/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'restart_app', 'arguments' => ['app' => 'shop']]]);
    }

    private function signIn(string $session, bool $write = false): array
    {
        $client = $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [self::CB]])->assertCreated()->json('client_id');
        [$verifier, $challenge] = $this->pkce();
        $q = ['response_type' => 'code', 'client_id' => $client, 'redirect_uri' => self::CB, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 'st-1', 'resource' => url('/api/v1/app/mcp')];
        config(['platform.panel_url' => 'https://panel.test']);
        $loc = $this->get('/oauth/authorize?'.http_build_query($q))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://panel.test/oauth/consent?', $loc);

        $this->api($session)->getJson('/api/v1/app/oauth/consent?'.http_build_query(['client_id' => $client, 'redirect_uri' => self::CB]))->assertOk()->assertJsonPath('data.name', 'Claude');
        $redirect = $this->api($session)->postJson('/api/v1/app/oauth/consent', ['client_id' => $client, 'redirect_uri' => self::CB, 'code_challenge' => $challenge, 'state' => 'st-1', 'approve' => true, 'write' => $write])
            ->assertOk()->json('data.redirect');
        parse_str(parse_url($redirect, PHP_URL_QUERY), $back);
        $this->assertSame('st-1', $back['state']);

        return [$client, $back['code'], $verifier];
    }

    public function test_discovery_points_mcp_clients_at_the_sign_in(): void
    {
        $r = $this->postJson('/api/v1/app/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])->assertUnauthorized();
        $this->assertStringContainsString('resource_metadata="'.url('/.well-known/oauth-protected-resource/api/v1/app/mcp').'"', $r->headers->get('WWW-Authenticate'));
        $this->getJson('/.well-known/oauth-protected-resource/api/v1/app/mcp')->assertOk()
            ->assertJsonPath('resource', url('/api/v1/app/mcp'))->assertJsonPath('authorization_servers.0', rtrim(url('/'), '/'));
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
            ->assertJsonPath('code_challenge_methods_supported', ['S256'])->assertJsonPath('registration_endpoint', rtrim(url('/'), '/').'/oauth/register');
        $this->postJson('/oauth/register', ['redirect_uris' => ['http://evil.example.com/cb']])->assertStatus(400);
        $this->postJson('/oauth/register', ['redirect_uris' => ['javascript:alert(1)']])->assertStatus(400);
    }

    public function test_a_client_signs_in_with_pkce_refreshes_and_is_revoked(): void
    {
        [, $org, $session] = $this->org();
        app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'shop', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'size' => 'small', 'port' => 80]));
        [$client, $code, $verifier] = $this->signIn($session);

        $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client, 'redirect_uri' => self::CB, 'code_verifier' => 'wrong'.$verifier])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $t = $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client, 'redirect_uri' => self::CB, 'code_verifier' => $verifier])
            ->assertOk()->assertJsonPath('token_type', 'Bearer')->assertJsonPath('scope', 'mcp')->json();

        // The token works for MCP, read-only as approved, and nowhere else.
        $this->api($t['access_token'])->postJson('/api/v1/app/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk();
        $this->rpc($t['access_token'])->assertJsonPath('result.isError', true);
        $this->api($t['access_token'])->getJson('/api/v1/app/apps')->assertForbidden();
        // …and can't approve another client for itself.
        [, $challenge] = $this->pkce();
        $this->api($t['access_token'])->postJson('/api/v1/app/oauth/consent', ['client_id' => $client, 'redirect_uri' => self::CB, 'code_challenge' => $challenge, 'approve' => true, 'write' => true])->assertForbidden();

        // Refresh rotates; the old refresh token is dead.
        $n = $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token'], 'client_id' => $client])->assertOk()->json();
        $this->assertNotSame($t['refresh_token'], $n['refresh_token']);
        $this->api($t['access_token'])->postJson('/api/v1/app/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertUnauthorized();

        // Reusing the old refresh token is treated as theft: the whole family is revoked.
        $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token'], 'client_id' => $client])->assertStatus(400);
        $this->api($n['access_token'])->postJson('/api/v1/app/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertUnauthorized();
        $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $n['refresh_token'], 'client_id' => $client])->assertStatus(400);
    }

    public function test_codes_are_single_use_and_revoking_in_the_panel_stops_refresh(): void
    {
        [, , $session] = $this->org();
        [$client, $code, $verifier] = $this->signIn($session, true);
        $ok = fn () => $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client, 'redirect_uri' => self::CB, 'code_verifier' => $verifier]);
        $t = $ok()->assertOk()->assertJsonPath('scope', 'mcp mcp.write')->json();
        $ok()->assertStatus(400); // second use

        // The second use revoked what the first produced.
        $this->api($t['access_token'])->postJson('/api/v1/app/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertUnauthorized();

        [$client2, $code2, $verifier2] = $this->signIn($session);
        $t2 = $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code2, 'client_id' => $client2, 'redirect_uri' => self::CB, 'code_verifier' => $verifier2])->assertOk()->json();
        $id = AccessToken::where('name', 'mcp:Claude')->latest()->first()->id;
        $this->api($session)->deleteJson("/api/v1/app/ai/tokens/{$id}")->assertNoContent();
        $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $t2['refresh_token'], 'client_id' => $client2])->assertStatus(400);

        // Denying sends the client an access_denied, and an unknown redirect is never followed.
        $this->api($session)->postJson('/api/v1/app/oauth/consent', ['client_id' => $client2, 'redirect_uri' => self::CB, 'code_challenge' => str_repeat('a', 43), 'approve' => false])
            ->assertJsonPath('data.redirect', self::CB.'?error=access_denied');
        $this->get('/oauth/authorize?'.http_build_query(['client_id' => $client2, 'redirect_uri' => 'https://evil.example.com/cb', 'response_type' => 'code']))->assertStatus(400);
        $this->get('/oauth/authorize?'.http_build_query(['client_id' => $client2, 'redirect_uri' => self::CB, 'response_type' => 'code', 'code_challenge_method' => 'plain', 'code_challenge' => str_repeat('a', 43)]))
            ->assertRedirect(self::CB.'?error=invalid_request');
    }
}
