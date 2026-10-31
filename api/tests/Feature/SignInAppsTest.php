<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** SVC-6/7: captcha on sign-in and "Sign in with" providers for existing accounts. */
class SignInAppsTest extends TestCase
{
    private function setUpService(string $token, string $key, array $values): void
    {
        $this->api($token)->patchJson("/api/v1/services/{$key}", ['enabled' => true, 'values' => $values])->assertOk();
    }

    public function test_captcha_guards_sign_in_when_set_up(): void
    {
        [$user, , $token] = $this->org();
        $user->forceFill(['password' => 'password'])->save();
        $this->getJson('/api/v1/auth/config')->assertOk()->assertJsonPath('data.captcha', null);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

        $this->setUpService($token, 'turnstile', ['site_key' => '0x4AAA-site', 'secret_key' => 'aaaa-aaaa-secret']);
        $this->getJson('/api/v1/auth/config')->assertOk()->assertJsonPath('data.captcha', ['provider' => 'turnstile', 'site_key' => '0x4AAA-site']);
        $this->assertStringNotContainsString('aaaa-aaaa-secret', $this->getJson('/api/v1/auth/config')->getContent());

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['captcha']]]);
        Http::fake(['challenges.cloudflare.com/*' => Http::sequence()->push(['success' => false])->push(['success' => true])]);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'captcha' => 'bad'])->assertStatus(422);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'captcha' => 'good'])->assertOk();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['captcha']]]);
    }

    public function test_google_sign_in_for_an_existing_verified_account_with_pkce(): void
    {
        [$admin, , $token] = $this->org();
        $this->setUpService($token, 'google_login', ['client_id' => 'cid.apps', 'client_secret' => 'aaaa-aaaa-client']);
        $this->assertSame([['key' => 'google_login', 'label' => 'Google']], $this->getJson('/api/v1/auth/config')->json('data.sso'));

        $url = $this->getJson('/api/v1/auth/sso/google_login')->assertOk()->json('data.url');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertStringEndsWith('/auth/sso/callback', $q['redirect_uri']);

        $email = strtoupper($admin->email);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'at']),
            'openidconnect.googleapis.com/*' => function () use (&$email) { return Http::response(['email' => $email, 'email_verified' => true]); },
        ]);
        $this->postJson('/api/v1/auth/sso/callback', ['state' => $q['state'], 'code' => 'c'])->assertOk()->assertJsonStructure(['data' => ['token']]);
        $this->postJson('/api/v1/auth/sso/callback', ['state' => $q['state'], 'code' => 'c'])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['state']]]);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth2.googleapis.com') && isset($r['code_verifier']));

        // Unknown accounts and unverified emails are refused.
        parse_str(parse_url($this->getJson('/api/v1/auth/sso/google_login')->json('data.url'), PHP_URL_QUERY), $q2);
        $email = 'nobody@example.test';
        $this->postJson('/api/v1/auth/sso/callback', ['state' => $q2['state'], 'code' => 'c'])->assertStatus(401);
        $this->getJson('/api/v1/auth/sso/github_login')->assertNotFound();
    }

    public function test_two_factor_accounts_still_need_their_code(): void
    {
        [$admin, , $token] = $this->org();
        $secret = \App\Support\Totp::generateSecret();
        $admin->forceFill(['mfa_enabled' => true, 'mfa_secret' => $secret])->save();
        $this->setUpService($token, 'github_login', ['client_id' => 'gh', 'client_secret' => 'aaaa-aaaa-client']);
        parse_str(parse_url($this->getJson('/api/v1/auth/sso/github_login')->json('data.url'), PHP_URL_QUERY), $q);
        Http::fake(['github.com/login/oauth/access_token' => Http::response(['access_token' => 'at']), 'api.github.com/user/emails' => Http::response([['email' => $admin->email, 'primary' => true, 'verified' => true]])]);
        $ticket = $this->postJson('/api/v1/auth/sso/callback', ['state' => $q['state'], 'code' => 'c'])->assertStatus(401)->assertJsonPath('error.mfa_required', true)->json('error.ticket');
        $this->assertNotNull($ticket);
        $this->postJson('/api/v1/auth/sso/callback', ['ticket' => $ticket, 'otp' => '000000'])->assertStatus(401);
        $this->postJson('/api/v1/auth/sso/callback', ['ticket' => $ticket, 'otp' => \App\Support\Totp::code($secret)])->assertOk();
    }

    public function test_people_see_their_sign_in_apps_and_can_turn_one_off(): void
    {
        [$admin, , $token] = $this->org();
        $this->setUpService($token, 'google_login', ['client_id' => 'cid.apps', 'client_secret' => 'aaaa-aaaa-client']);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'at']),
            'openidconnect.googleapis.com/*' => Http::response(['email' => $admin->email, 'email_verified' => true]),
        ]);
        $signIn = function () {
            parse_str(parse_url($this->getJson('/api/v1/auth/sso/google_login')->json('data.url'), PHP_URL_QUERY), $q);

            return $this->postJson('/api/v1/auth/sso/callback', ['state' => $q['state'], 'code' => 'c']);
        };

        $apps = $this->api($token)->getJson('/api/v1/me/sign-in-apps')->assertOk()->json('data');
        $this->assertSame([['key' => 'google_login', 'label' => 'Google', 'email' => null, 'last_used_at' => null, 'blocked' => false]], $apps);

        $signIn()->assertOk();
        $google = $this->api($token)->getJson('/api/v1/me/sign-in-apps')->json('data.0');
        $this->assertSame($admin->email, $google['email']);
        $this->assertNotNull($google['last_used_at']);

        $this->api($token)->patchJson('/api/v1/me/sign-in-apps/google_login', ['blocked' => true])->assertOk()->assertJsonPath('data.0.blocked', true);
        $signIn()->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.sign_in_app_blocked', 'user_id' => $admin->id]);

        $this->api($token)->patchJson('/api/v1/me/sign-in-apps/google_login', ['blocked' => false])->assertOk();
        $signIn()->assertOk();
        $this->api($token)->patchJson('/api/v1/me/sign-in-apps/nope', ['blocked' => true])->assertNotFound();
    }
}

