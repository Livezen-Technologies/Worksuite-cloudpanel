<?php

namespace Tests\Feature;

use Tests\TestCase;

/** SSO-POL: "Require single sign-on" refuses password sign-in for members, never for owners. */
class RequireSsoTest extends TestCase
{
    private function requireSso($org): void
    {
        $org->forceFill(['settings' => array_replace_recursive($org->settings ?? [], ['security' => ['require_sso' => true]])])->save();
    }

    private function login(string $email)
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'password']);
    }

    public function test_members_use_a_sign_in_app_and_owners_keep_their_password(): void
    {
        [, , $platformToken] = $this->org(); // the first organisation holds the sign-in app settings
        [$dev, $org] = $this->org('developer');
        [$owner, $ownersOrg] = $this->org('owner');
        $this->requireSso($org);
        $this->requireSso($ownersOrg);

        // Until an app is set up, the rule waits rather than locking people out.
        $this->login($dev->email)->assertOk();

        $this->api($platformToken)->patchJson('/api/v1/services/google_login', ['enabled' => true, 'values' => ['client_id' => 'cid.apps', 'client_secret' => 'aaaa-aaaa-client']])->assertOk();
        $this->login($dev->email)->assertStatus(403)
            ->assertJsonPath('error.code', 'sso_required')
            ->assertJsonPath('error.sso.0.label', 'Google');
        $this->login($owner->email)->assertOk();
    }

    public function test_without_the_policy_passwords_work(): void
    {
        [, , $platformToken] = $this->org();
        $this->api($platformToken)->patchJson('/api/v1/services/google_login', ['enabled' => true, 'values' => ['client_id' => 'cid.apps', 'client_secret' => 'aaaa-aaaa-client']])->assertOk();
        [$dev] = $this->org('developer');
        $this->login($dev->email)->assertOk();
    }
}
