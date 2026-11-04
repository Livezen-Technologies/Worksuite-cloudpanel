<?php

namespace Tests\Feature;

use App\Support\Totp;
use Tests\TestCase;

/** MFA-REC: one-time recovery codes for a lost authenticator. */
class RecoveryCodesTest extends TestCase
{
    private function enable(string $token): array
    {
        $secret = $this->api($token)->postJson('/api/v1/me/mfa/enable')->json('data.secret');
        $codes = $this->api($token)->postJson('/api/v1/me/mfa/confirm', ['otp' => Totp::code($secret)])->assertOk()->json('data.recovery_codes');

        return [$secret, $codes];
    }

    public function test_codes_are_issued_once_hashed_and_each_works_a_single_time(): void
    {
        [$user, , $token] = $this->org();
        [, $codes] = $this->enable($token);

        $this->assertCount(10, $codes);
        $this->assertMatchesRegularExpression('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $codes[0]);
        $stored = $user->fresh()->mfa_recovery_codes;
        $this->assertCount(10, $stored);
        $this->assertNotContains($codes[0], $stored, 'only hashes are stored');
        $this->assertArrayNotHasKey('mfa_recovery_codes', $user->fresh()->toArray());
        $this->api($token)->getJson('/api/v1/me')->assertJsonPath('data.user.mfa_recovery_codes_left', 10);

        $login = fn (string $otp) => $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'otp' => $otp]);
        $login(strtoupper(str_replace('-', ' ', $codes[0])))->assertOk(); // spacing and case are forgiven
        $login($codes[0])->assertStatus(401)->assertJsonPath('error.mfa_required', true);
        $this->assertSame(9, count($user->fresh()->mfa_recovery_codes));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $user->id]);
        $login('aaaaa-aaaaa')->assertStatus(401);
    }

    public function test_regenerating_needs_a_current_code_and_replaces_the_set(): void
    {
        [$user, , $token] = $this->org();
        [$secret, $old] = $this->enable($token);

        $this->api($token)->postJson('/api/v1/me/mfa/recovery-codes', ['otp' => '000000'])->assertStatus(422);
        $new = $this->api($token)->postJson('/api/v1/me/mfa/recovery-codes', ['otp' => Totp::code($secret)])->assertOk()->json('data.recovery_codes');
        $this->assertCount(10, $new);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'otp' => $old[0]])->assertStatus(401);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'otp' => $new[0]])->assertOk();
    }

    public function test_turning_off_two_factor_clears_the_codes(): void
    {
        [$user, , $token] = $this->org();
        [$secret] = $this->enable($token);
        $this->api($token)->postJson('/api/v1/me/mfa/disable', ['otp' => Totp::code($secret)])->assertOk();
        $this->assertNull($user->fresh()->mfa_recovery_codes);
    }
}
