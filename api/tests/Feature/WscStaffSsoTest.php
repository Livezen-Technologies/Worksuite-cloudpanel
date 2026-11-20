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
            'nonce' => $q['nonce'], 'email' => $idTokenFor, 'email_verified' => true, 'name' => 'Staff', 'wsc_staff' => true, 'wsc_roles' => ['super_admin'],
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

    public function test_unknown_email_and_non_staff_are_refused_and_nobody_is_created(): void
    {
        [$state] = $this->begin('nobody@example.test');
        $this->postJson('/api/v1/auth/sso/callback', ['state' => $state, 'code' => 'abc'])->assertStatus(401)
            ->assertJsonPath('error.message', 'No CloudPanel admin account for nobody@example.test.');
        $this->assertDatabaseMissing('users', ['email' => 'nobody@example.test']);

        [$customer] = $this->org('owner');
        [$state] = $this->begin($customer->email);
        $this->postJson('/api/v1/auth/sso/callback', ['state' => $state, 'code' => 'abc'])->assertStatus(401)
            ->assertJsonPath('error.message', "No CloudPanel admin account for {$customer->email}.");
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
