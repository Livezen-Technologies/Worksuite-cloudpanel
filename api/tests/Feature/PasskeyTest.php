<?php

namespace Tests\Feature;

use App\Models\Passkey;
use App\Support\Totp;
use Tests\Support\SoftAuthenticator;
use Tests\TestCase;

class PasskeyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['security.webauthn.rp_id' => 'localhost', 'security.webauthn.origins' => ['http://localhost:5173']]);
    }

    /** Registers a passkey for the token's user; returns the authenticator and passkey id. */
    private function register(string $token, string $name = 'Laptop'): array
    {
        $opts = $this->api($token)->postJson('/api/v1/me/passkeys/options')->assertOk()->json('data');
        $this->assertSame('localhost', $opts['options']['rp']['id']);
        $this->assertSame('required', $opts['options']['authenticatorSelection']['residentKey']);
        $auth = new SoftAuthenticator;
        $pk = $this->api($token)->postJson('/api/v1/me/passkeys', ['challenge_id' => $opts['challenge_id'], 'name' => $name, 'credential' => $auth->create($opts['options'])])
            ->assertCreated()->assertJsonPath('data.name', $name)->json('data');

        return [$auth, $pk['id']];
    }

    public function test_register_list_rename_delete(): void
    {
        [, , $token] = $this->org();
        [$auth, $id] = $this->register($token);
        $this->api($token)->getJson('/api/v1/me/passkeys')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.transports', ['internal'])
            ->assertJsonMissingPath('data.0.public_key');
        $this->api($token)->patchJson("/api/v1/me/passkeys/{$id}", ['name' => 'YubiKey'])->assertOk()->assertJsonPath('data.name', 'YubiKey');

        // Re-registering the same authenticator is excluded / refused; a replayed challenge is refused.
        $opts = $this->api($token)->postJson('/api/v1/me/passkeys/options')->json('data');
        $this->assertCount(1, $opts['options']['excludeCredentials']);
        $this->api($token)->postJson('/api/v1/me/passkeys', ['challenge_id' => $opts['challenge_id'], 'credential' => $auth->create($opts['options'])])->assertStatus(409);
        $this->api($token)->postJson('/api/v1/me/passkeys', ['challenge_id' => $opts['challenge_id'], 'credential' => (new SoftAuthenticator)->create($opts['options'])])->assertStatus(401);

        // Another user cannot rename or delete it.
        [, , $other] = $this->org();
        $this->api($other)->deleteJson("/api/v1/me/passkeys/{$id}")->assertNotFound();
        $this->api($token)->deleteJson("/api/v1/me/passkeys/{$id}")->assertNoContent();
        $this->assertSame(0, Passkey::count());
    }

    public function test_wrong_origin_is_rejected_at_registration(): void
    {
        [, , $token] = $this->org();
        $opts = $this->api($token)->postJson('/api/v1/me/passkeys/options')->json('data');
        $evil = new SoftAuthenticator('https://evil.example');
        $this->api($token)->postJson('/api/v1/me/passkeys', ['challenge_id' => $opts['challenge_id'], 'credential' => $evil->create($opts['options'])])->assertStatus(422);
    }

    public function test_passwordless_sign_in_with_discoverable_passkey(): void
    {
        [$user, , $token] = $this->org();
        [$auth] = $this->register($token);
        $this->app['auth']->forgetGuards();
        $opts = $this->withHeaders(['Authorization' => ''])->postJson('/api/v1/auth/passkey/options')->assertOk()->json('data');
        $this->assertSame([], $opts['options']['allowCredentials'] ?? []);
        $login = $this->postJson('/api/v1/auth/passkey/login', ['challenge_id' => $opts['challenge_id'], 'credential' => $auth->get($opts['options'])])
            ->assertOk()->assertJsonPath('data.user.email', $user->email)->json('data.token');
        $this->api($login)->getJson('/api/v1/me')->assertOk();
        $this->assertSame(1, Passkey::first()->sign_count);
        $this->assertNotNull(Passkey::first()->last_used_at);

        // Challenge is single use; a signature over a different challenge fails.
        $this->postJson('/api/v1/auth/passkey/login', ['challenge_id' => $opts['challenge_id'], 'credential' => $auth->get($opts['options'])])->assertStatus(401);
        $opts2 = $this->postJson('/api/v1/auth/passkey/options')->json('data');
        $this->postJson('/api/v1/auth/passkey/login', ['challenge_id' => $opts2['challenge_id'], 'credential' => $auth->get($opts2['options'], SoftAuthenticator::b64u(random_bytes(32)))])->assertStatus(401);
        // An unregistered authenticator fails.
        $opts3 = $this->postJson('/api/v1/auth/passkey/options')->json('data');
        $stranger = new SoftAuthenticator;
        $stranger->userHandle = $user->id;
        $this->postJson('/api/v1/auth/passkey/login', ['challenge_id' => $opts3['challenge_id'], 'credential' => $stranger->get($opts3['options'])])->assertStatus(401);
    }

    public function test_passkey_as_second_factor_when_mfa_is_on(): void
    {
        [$user, , $token] = $this->org();
        [$auth] = $this->register($token);
        $secret = $this->api($token)->postJson('/api/v1/me/mfa/enable')->json('data.secret');
        $this->api($token)->postJson('/api/v1/me/mfa/confirm', ['otp' => Totp::code($secret)])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(401)
            ->assertJsonPath('error.mfa_required', true)->assertJsonPath('error.passkey_available', true);
        $opts = $this->postJson('/api/v1/auth/passkey/options', ['email' => $user->email])->json('data');
        $this->assertCount(1, $opts['options']['allowCredentials']);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'passkey' => ['challenge_id' => $opts['challenge_id'], 'credential' => $auth->get($opts['options'])]])
            ->assertOk()->assertJsonStructure(['data' => ['token']]);

        // Someone else's passkey cannot be the second factor for this account.
        [, , $otherToken] = $this->org();
        [$otherAuth] = $this->register($otherToken);
        $opts = $this->postJson('/api/v1/auth/passkey/options')->json('data');
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'passkey' => ['challenge_id' => $opts['challenge_id'], 'credential' => $otherAuth->get($opts['options'])]])
            ->assertStatus(401);
    }
}
