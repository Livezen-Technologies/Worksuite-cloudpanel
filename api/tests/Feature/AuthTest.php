<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Totp;
use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_register_login_me_logout(): void
    {
        $token = $this->postJson('/api/v1/auth/register', ['name' => 'Ann', 'email' => 'ann@example.test', 'password' => 'secret-pass', 'organization' => 'Ann Co'])
            ->assertCreated()->assertJsonStructure(['data' => ['token', 'user' => ['id', 'email']]])->json('data.token');

        $this->api($token)->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.role', 'owner')
            ->assertJsonPath('data.organization.name', 'Ann Co')
            ->assertJsonFragment(['servers.manage']);

        $login = $this->postJson('/api/v1/auth/login', ['email' => 'ann@example.test', 'password' => 'secret-pass'])->assertOk()->json('data.token');
        $this->api($login)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->api($login)->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_bad_credentials_use_structured_error_with_request_id(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'x'])
            ->assertStatus(401)
            ->assertJsonStructure(['error' => ['code', 'message', 'fields', 'request_id']])
            ->assertJsonPath('error.code', 'unauthenticated')
            ->assertHeader('X-Request-Id');

        $this->postJson('/api/v1/auth/register', ['email' => 'bad'])->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['fields' => ['name', 'email', 'password']]]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/servers')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_mfa_enable_confirm_and_login_requires_otp(): void
    {
        [$user, , $token] = $this->org();
        $secret = $this->api($token)->postJson('/api/v1/me/mfa/enable')->assertOk()->assertJsonStructure(['data' => ['secret', 'otpauth_url']])->json('data.secret');
        $this->api($token)->postJson('/api/v1/me/mfa/confirm', ['otp' => '000000'])->assertStatus(422);
        $this->api($token)->postJson('/api/v1/me/mfa/confirm', ['otp' => Totp::code($secret)])->assertOk();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(401)->assertJsonPath('error.mfa_required', true);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'otp' => Totp::code($secret)])->assertOk();
        $this->assertTrue(User::find($user->id)->mfa_enabled);
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'x@example.test', 'password' => 'bad']);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'x@example.test', 'password' => 'bad'])->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    }
}
