<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Staff SSO: "Sign in with WorkSuite Cloud" for panel staff, verified against the issuer's JWKS. */
class WscStaffSsoTest extends TestCase
{
    private const ISS = 'https://account.worksuitecloud.test';

    private $key;

    private string $kid = 'k1';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.wsc_oidc' => ['issuer' => self::ISS, 'client_id' => 'panel-staff', 'client_secret' => 'test-secret', 'redirect_uri' => 'https://panel.test/auth/wsc/callback']]);
        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private function jwt(array $claims, $key = null): string
    {
        $h = self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $this->kid]));
        $p = self::b64(json_encode($claims));
        openssl_sign("{$h}.{$p}", $sig, $key ?? $this->key, OPENSSL_ALGO_SHA256);

        return "{$h}.{$p}.".self::b64($sig);
    }

    /** Starts a flow and returns [state, nonce] from the authorisation URL. */
    private function begin(string $idTokenFor = '', array $extra = [], $signWith = null, bool $silent = false): array
    {
        $d = openssl_pkey_get_details($this->key)['rsa'];
        $tokenBody = [];
        Http::fake([
            self::ISS.'/.well-known/openid-configuration' => Http::response(['issuer' => self::ISS, 'authorization_endpoint' => self::ISS.'/oauth/authorize', 'token_endpoint' => self::ISS.'/oauth/token', 'jwks_uri' => self::ISS.'/oauth/jwks']),
            self::ISS.'/oauth/jwks' => Http::response(['keys' => [['kty' => 'RSA', 'kid' => $this->kid, 'alg' => 'RS256', 'use' => 'sig', 'n' => self::b64($d['n']), 'e' => self::b64($d['e'])]]]),
            self::ISS.'/oauth/token' => function ($req) use (&$tokenBody) {
                $tokenBody = $req->data();

                return Http::response(['access_token' => 'at', 'token_type' => 'Bearer', 'id_token' => $this->pendingIdToken]);
            },
        ]);
        $url = $this->getJson('/api/v1/auth/sso/wsc'.($silent ? '?prompt=none' : ''))->assertOk()->json('data.url');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertSame('https://panel.test/auth/wsc/callback', $q['redirect_uri']);
        $silent ? $this->assertSame('none', $q['prompt']) : $this->assertArrayNotHasKey('prompt', $q);
        $this->pendingIdToken = $idTokenFor === '' ? '' : $this->jwt(array_replace([
            'iss' => self::ISS, 'aud' => 'panel-staff', 'sub' => 'staff:7', 'exp' => time() + 300, 'iat' => time(),
            'nonce' => $q['nonce'], 'email' => $idTokenFor, 'email_verified' => true, 'name' => 'Staff', 'wsc_staff' => true, 'wsc_roles' => ['super_admin'], 'wsc_app' => 'panel', 'wsc_app_role' => 'infra_admin',
        ], $extra), $signWith);

        return [$q['state'], $q['nonce']];
    }

    public string $pendingIdToken = '';

    public function test_hidden_while_unset(): void
    {
        config(['services.wsc_oidc.issuer' => null]);
        $keys = array_column($this->getJson('/api/v1/auth/config')->assertOk()->json('data.sso'), 'key');
        $this->assertNotContains('wsc', $keys);
        $this->getJson('/api/v1/auth/sso/wsc')->assertStatus(404);
    }

    public function test_offered_when_configured(): void
    {
        $this->getJson('/api/v1/auth/config')->assertOk()->assertJsonFragment(['key' => 'wsc', 'label' => 'WorkSuite Cloud']);
    }

    public function test_staff_signs_in_by_verified_email_case_insensitively(): void
    {
        [$staff] = $this->org('super_admin');
        [$state] = $this->begin(strtoupper($staff->email));
        $this->postJson('/api/v1/auth/sso/callback', ['state' => $state, 'code' => 'abc'])->assertOk()->assertJsonStructure(['data' => ['token']]);
        $this->assertDatabaseHas('sso_identities', ['user_id' => $staff->id, 'provider' => 'wsc']);
        // The state is single-use.
        $this->postJson('/api/v1/auth/sso/callback', ['state' => $state, 'code' => 'abc'])->assertStatus(422);
    }

    public function test_silent_start_asks_for_prompt_none(): void
    {
        $this->begin('', [], null, true);
    }

    public function test_wrong_state_is_refused(): void
    {
        [$staff] = $this->org('super_admin');
        $this->begin($staff->email);
        $this->postJson('/api/v1/auth/sso/callback', ['state' => 'not-the-state', 'code' => 'abc'])->assertStatus(422);
    }

    public function test_first_sign_in_creates_the_staff_user_with_the_granted_role(): void
    {
        [, $org] = $this->org('super_admin');
        [$state] = $this->begin('New.Admin@Example.test', ['sub' => 'staff:42', 'name' => 'New Admin', 'wsc_app_role' => 'devops']);
        $this->postJson('/api/v1/auth/sso/callback', ['state' => $state, 'code' => 'abc'])->assertOk()->assertJsonStructure(['data' => ['token']]);
        $u = \App\Models\User::where('wsc_sub', 'staff:42')->firstOrFail();
        $this->assertSame('new.admin@example.test', $u->email);
        $this->assertSame('New Admin', $u->name);
        $this->assertTrue($u->managed_by_wsc);
        $this->assertSame('devops', \App\Models\Membership::allTenants()->where('user_id', $u->id)->where('organization_id', $org->id)->value('role'));
    }

    public function test_existing_user_is_linked_by_email_and_takes_the_role_from_the_claim(): void
    {
        [, $org] = $this->org('super_admin');
        [$staff] = $this->member($org, 'devops');
        [$state] = $this->begin(strtoupper($staff->email), ['sub' => 'staff:9', 'wsc_app_role' => 'infra_admin']);
        $this->postJson('/api/v1/auth/sso/callback', ['state' => $state, 'code' => 'abc'])->assertOk();
        $staff->refresh();
        $this->assertSame('staff:9', $staff->wsc_sub);
        $this->assertTrue($staff->managed_by_wsc);
        $this->assertSame('infra_admin', \App\Models\Membership::allTenants()->where('user_id', $staff->id)->value('role'));
        // Later sign-ins find the user by wsc_sub even after an email change in Cloud Admin.
        \Illuminate\Support\Facades\Cache::flush();
        [$state] = $this->begin('renamed@example.test', ['sub' => 'staff:9', 'wsc_app_role' => 'super_admin']);
        $this->postJson('/api/v1/auth/sso/callback', ['state' => $state, 'code' => 'abc'])->assertOk();
        $this->assertSame('renamed@example.test', $staff->fresh()->email);
        $this->assertSame('super_admin', \App\Models\Membership::allTenants()->where('user_id', $staff->id)->value('role'));
    }

    public function test_without_a_panel_role_the_sign_in_is_refused_and_nobody_is_created(): void
    {
        $this->org('super_admin');
        foreach ([['wsc_app_role' => null], ['wsc_app_role' => 'owner']] as $bad) {
            \Illuminate\Support\Facades\Cache::flush();
            [$state] = $this->begin('nobody@example.test', $bad);
            $this->postJson('/api/v1/auth/sso/callback', ['state' => $state, 'code' => 'abc'])->assertStatus(403);
        }
        $this->assertDatabaseMissing('users', ['email' => 'nobody@example.test']);
    }

    public function test_a_locally_suspended_user_cannot_sign_in(): void
    {
        [, $org] = $this->org('super_admin');
        [$staff] = $this->member($org, 'devops');
        \App\Models\Membership::allTenants()->where('user_id', $staff->id)->update(['status' => 'suspended']);
        [$state] = $this->begin($staff->email);
        $this->postJson('/api/v1/auth/sso/callback', ['state' => $state, 'code' => 'abc'])->assertStatus(403);
        $this->assertSame('suspended', \App\Models\Membership::allTenants()->where('user_id', $staff->id)->value('status'));
    }

    // ---- provisioning events -------------------------------------------------------------------

    private function jwks(): void
    {
        $d = openssl_pkey_get_details($this->key)['rsa'];
        Http::fake([
            self::ISS.'/.well-known/openid-configuration' => Http::response(['issuer' => self::ISS, 'authorization_endpoint' => self::ISS.'/oauth/authorize', 'token_endpoint' => self::ISS.'/oauth/token', 'jwks_uri' => self::ISS.'/oauth/jwks']),
            self::ISS.'/oauth/jwks' => Http::response(['keys' => [['kty' => 'RSA', 'kid' => $this->kid, 'alg' => 'RS256', 'use' => 'sig', 'n' => self::b64($d['n']), 'e' => self::b64($d['e'])]]]),
        ]);
    }

    private function event(array $claims = [], $key = null): string
    {
        return $this->jwt(array_replace(['iss' => self::ISS, 'aud' => 'panel-staff', 'iat' => time(), 'exp' => time() + 300, 'jti' => (string) \Illuminate\Support\Str::uuid(),
            'event' => 'upsert', 'sub' => 'staff:5', 'email' => 'ops@example.test', 'name' => 'Ops Person', 'role' => 'devops'], $claims), $key);
    }

    private function push(string $jwt, string $type = 'application/jwt')
    {
        return $this->call('POST', '/api/v1/auth/wsc/provision', [], [], [], ['CONTENT_TYPE' => $type, 'HTTP_ACCEPT' => 'application/json'], $jwt);
    }

    public function test_provision_upsert_creates_then_changes_the_role(): void
    {
        [, $org] = $this->org('super_admin');
        $this->jwks();
        $this->push($this->event())->assertNoContent();
        $u = \App\Models\User::where('wsc_sub', 'staff:5')->firstOrFail();
        $m = fn () => \App\Models\Membership::allTenants()->where('user_id', $u->id)->where('organization_id', $org->id)->first();
        $this->assertSame(['devops', 'active'], [$m()->role, $m()->status]);
        $this->push($this->event(['role' => 'infra_admin', 'name' => 'Ops Renamed']))->assertNoContent();
        $this->assertSame('infra_admin', $m()->role);
        $this->assertSame('Ops Renamed', $u->fresh()->name);
        $this->push($this->event(['role' => 'owner']))->assertStatus(401);
    }

    public function test_provision_disable_suspends_and_ends_sessions_and_upsert_re_enables(): void
    {
        [, $org] = $this->org('super_admin');
        [$staff, $token] = $this->member($org, 'devops');
        $this->jwks();
        $this->push($this->event(['event' => 'disable', 'sub' => 'staff:5', 'email' => $staff->email, 'role' => null]))->assertNoContent();
        $m = fn () => \App\Models\Membership::allTenants()->where('user_id', $staff->id)->first();
        $this->assertSame('suspended', $m()->status);
        $this->assertSame(0, $staff->tokens()->count());
        $this->assertNotNull($staff->fresh()->wsc_disabled_at);
        $this->push($this->event(['sub' => 'staff:5', 'email' => $staff->email, 'role' => 'devops']))->assertNoContent();
        $this->assertSame('active', $m()->status);
        // A local suspension is not undone by WorkSuite Cloud.
        $m()->update(['status' => 'suspended']);
        $this->push($this->event(['sub' => 'staff:5', 'email' => $staff->email, 'role' => 'devops']))->assertNoContent();
        $this->assertSame('suspended', $m()->status);
    }

    public function test_provision_disable_of_an_unknown_admin_touches_nobody(): void
    {
        [$admin] = $this->org('super_admin');
        $this->jwks();
        $this->push($this->event(['event' => 'disable', 'sub' => 'staff:77', 'email' => 'ghost@example.test']))->assertNoContent();
        $this->assertDatabaseMissing('users', ['email' => 'ghost@example.test']);
        $this->assertSame('active', \App\Models\Membership::allTenants()->where('user_id', $admin->id)->value('status'));
    }

    public function test_provision_refuses_bad_tokens(): void
    {
        $this->org('super_admin');
        $this->jwks();
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->push($this->event([], $other))->assertStatus(401);                    // bad signature
        $this->push($this->event(['aud' => 'staff-iot']))->assertStatus(401);         // wrong aud
        $this->push($this->event(['iss' => 'https://evil.test']))->assertStatus(401); // wrong iss
        $this->push($this->event(['exp' => time() - 600]))->assertStatus(401);       // expired
        $this->push($this->event(['event' => 'nuke']))->assertStatus(400);
        $this->push('not-a-jwt')->assertStatus(400);
        $this->push($this->event(), 'application/json')->assertStatus(415);
        $jwt = $this->event(['jti' => 'once']);
        $this->push($jwt)->assertNoContent();
        $this->push($jwt)->assertStatus(401);                                          // replayed jti
        $this->assertSame(1, \App\Models\User::where('wsc_sub', 'staff:5')->count());
    }

    public function test_provision_is_not_found_while_unset(): void
    {
        config(['services.wsc_oidc.issuer' => null]);
        $this->push($this->event())->assertStatus(404);
    }

    public function test_a_wsc_managed_role_cannot_be_changed_locally_but_can_be_suspended(): void
    {
        [, $org, $token] = $this->org('super_admin');
        [$staff] = $this->member($org, 'devops');
        $staff->forceFill(['managed_by_wsc' => true, 'wsc_sub' => 'staff:3'])->save();
        $this->withToken($token)->patchJson("/api/v1/users/{$staff->id}", ['role' => 'infra_admin'])->assertStatus(409);
        $this->withToken($token)->postJson("/api/v1/users/{$staff->id}/status", ['status' => 'suspended'])->assertOk();
        $this->withToken($token)->getJson('/api/v1/users')->assertOk()->assertJsonFragment(['managed_by_wsc' => true]);
    }

    public function test_bad_signature_is_refused(): void
    {
        [$staff] = $this->org('super_admin');
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        [$state] = $this->begin($staff->email, [], $other);
        $this->postJson('/api/v1/auth/sso/callback', ['state' => $state, 'code' => 'abc'])->assertStatus(401)
            ->assertJsonPath('error.message', 'The WorkSuite Cloud sign-in could not be verified.');
    }

    public function test_wrong_audience_nonce_expiry_or_non_staff_claim_are_refused(): void
    {
        [$staff] = $this->org('super_admin');
        foreach ([['aud' => 'someone-else'], ['nonce' => 'other'], ['exp' => time() - 3600], ['iss' => 'https://evil.test'], ['wsc_staff' => false], ['email_verified' => false]] as $bad) {
            \Illuminate\Support\Facades\Cache::flush(); // the sign-in throttle, not what is under test
            [$state] = $this->begin($staff->email, $bad);
            $this->postJson('/api/v1/auth/sso/callback', ['state' => $state, 'code' => 'abc'])->assertStatus(401);
        }
    }
}
