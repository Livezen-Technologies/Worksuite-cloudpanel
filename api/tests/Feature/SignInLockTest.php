<?php

namespace Tests\Feature;

use App\Support\Totp;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** AUTH-LOCK: failed sign-ins are counted per account, across addresses. */
class SignInLockTest extends TestCase
{
    private function attempt(string $email, string $password, string $ip, ?string $otp = null)
    {
        RateLimiter::clear('auth:'.$ip.'|'.$email); // isolate the per-IP route limit from the per-account lock
        RateLimiter::clear('auth-ip:'.$ip);

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/login', array_filter(['email' => $email, 'password' => $password, 'otp' => $otp]));
    }

    public function test_ten_wrong_passwords_from_many_addresses_pause_the_account(): void
    {
        [$user] = $this->org();
        for ($i = 1; $i <= 10; $i++) {
            $this->attempt($user->email, 'wrong-'.$i, "198.51.100.{$i}")->assertStatus(401);
        }
        // Even the right password from a fresh address waits now.
        $this->attempt($user->email, 'password', '203.0.113.9')->assertStatus(429)
            ->assertJsonPath('error.code', 'too_many_attempts')->assertJsonPath('error.locked_for_minutes', 15);

        // A password reset lifts the pause.
        \App\Support\SignInLock::clear($user->email);
        $this->attempt($user->email, 'password', '203.0.113.9')->assertOk();
    }

    public function test_wrong_two_factor_codes_count_and_a_success_resets_the_count(): void
    {
        [$user, , $token] = $this->org();
        $secret = $this->api($token)->postJson('/api/v1/me/mfa/enable')->json('data.secret');
        $this->api($token)->postJson('/api/v1/me/mfa/confirm', ['otp' => Totp::code($secret)])->assertOk();

        for ($i = 1; $i <= 9; $i++) {
            $this->attempt($user->email, 'password', "198.51.100.{$i}", '000000')->assertStatus(401);
        }
        $this->attempt($user->email, 'password', '198.51.100.50', Totp::code($secret))->assertOk();
        // The count started again, so one more mistake does not lock.
        $this->attempt($user->email, 'password', '198.51.100.51', '000000')->assertStatus(401);
        $this->attempt($user->email, 'password', '198.51.100.52', Totp::code($secret))->assertOk();
    }
}
