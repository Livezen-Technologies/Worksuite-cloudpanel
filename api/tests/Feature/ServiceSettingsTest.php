<?php

namespace Tests\Feature;

use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** SVC-1..4: third-party service settings with write-only secrets and connection tests. */
class ServiceSettingsTest extends TestCase
{
    public function test_configure_test_and_remove_a_service_without_leaking_secrets(): void
    {
        [, , $token] = $this->org();
        $list = $this->api($token)->getJson('/api/v1/services')->assertOk()->json('data');
        $this->assertArrayHasKey('captcha', $list['categories']);
        $stripe = collect($list['services'])->firstWhere('key', 'stripe');
        $this->assertFalse($stripe['configured']);
        $this->assertTrue($stripe['test']);

        $this->api($token)->patchJson('/api/v1/services/stripe', ['values' => ['mode' => 'nope']])->assertStatus(422);
        $saved = $this->api($token)->patchJson('/api/v1/services/stripe', ['enabled' => true, 'values' => ['publishable_key' => 'pk_test_1', 'secret_key' => 'sk_test_abcd1234', 'mode' => 'test']])
            ->assertOk()->json('data');
        $this->assertSame(['set' => true, 'hint' => '••••1234'], $saved['values']['secret_key']);
        $this->assertStringNotContainsString('sk_test_abcd1234', json_encode($this->api($token)->getJson('/api/v1/services')->json()));

        // A blank secret keeps the stored one; other fields update.
        $this->api($token)->patchJson('/api/v1/services/stripe', ['values' => ['secret_key' => '', 'mode' => 'live']])->assertOk()->assertJsonPath('data.values.mode', 'live');
        $this->assertSame('sk_test_abcd1234', Integration::allTenants()->where('provider', 'stripe')->first()->credentials['secret_key']);

        Http::fake(['api.stripe.com/*' => Http::response(['available' => []]), 'api.cloudflare.com/*' => Http::response(['errors' => [['message' => 'Invalid API Token']]], 401)]);
        $this->api($token)->postJson('/api/v1/services/stripe/test')->assertOk()->assertJsonPath('data.ok', true);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer sk_test_abcd1234'));
        $this->api($token)->postJson('/api/v1/services/cloudflare/test')->assertStatus(409);
        $this->api($token)->patchJson('/api/v1/services/cloudflare', ['values' => ['api_token' => 'bad']])->assertOk();
        $this->api($token)->postJson('/api/v1/services/cloudflare/test')->assertOk()->assertJsonPath('data.ok', false)->assertJsonPath('data.message', 'The service answered 401: Invalid API Token');
        $this->api($token)->patchJson('/api/v1/services/turnstile', ['values' => ['site_key' => 'x']])->assertOk();
        $this->api($token)->postJson('/api/v1/services/turnstile/test')->assertStatus(409);

        $this->api($token)->deleteJson('/api/v1/services/stripe')->assertOk()->assertJsonPath('data.configured', false);
        $this->api($token)->getJson('/api/v1/services/unknown')->assertStatus(405);
        $this->api($token)->patchJson('/api/v1/services/unknown', [])->assertNotFound();

        [, , $dev] = $this->org('developer');
        $this->api($dev)->getJson('/api/v1/services')->assertForbidden();
    }
}
