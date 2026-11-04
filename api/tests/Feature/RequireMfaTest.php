<?php

namespace Tests\Feature;

use App\Models\Passkey;
use Tests\TestCase;

/** MFA-POL: "Require MFA for every member" accepts an authenticator app or a passkey, and says how to comply. */
class RequireMfaTest extends TestCase
{
    public function test_the_policy_blocks_until_a_second_factor_is_set_up(): void
    {
        [$user, $org, $token] = $this->org();
        $org->forceFill(['settings' => array_replace_recursive($org->settings ?? [], ['security' => ['require_mfa' => true]])])->save();

        $this->api($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.mfa_setup_required', true);
        $this->api($token)->getJson('/api/v1/servers')->assertStatus(403)
            ->assertJsonPath('error.mfa_setup_required', true)
            ->assertJsonPath('error.code', 'forbidden');

        // A passkey alone satisfies "passkeys or TOTP".
        Passkey::create(['user_id' => $user->id, 'name' => 'Laptop', 'credential_id' => 'cred-1', 'public_key' => 'pk', 'credential_hash' => hash('sha256', 'cred-1')]);
        $this->api($token)->getJson('/api/v1/me')->assertJsonPath('data.mfa_setup_required', false);
        $this->api($token)->getJson('/api/v1/servers')->assertOk();
    }

    public function test_without_the_policy_nothing_is_required(): void
    {
        [, , $token] = $this->org();
        $this->api($token)->getJson('/api/v1/me')->assertJsonPath('data.mfa_setup_required', false);
        $this->api($token)->getJson('/api/v1/servers')->assertOk();
    }
}
