<?php

namespace Tests\Feature;

use App\Models\FirewallCountry;
use App\Models\FirewallEvent;
use App\Models\FirewallRule;
use App\Support\Tenant;
use Tests\TestCase;

/** FW-001 step 4: the API behind the Firewall screen. */
class FirewallApiTest extends TestCase
{
    public function test_rule_crud_and_the_rate_is_presented_for_the_table(): void
    {
        [, , $token] = $this->org();

        $created = $this->api($token)->postJson('/api/v1/firewall-rules', [
            'name' => 'Login brute force', 'paths' => ['/login', '/wp-login.php'],
            'methods' => ['POST'], 'limit' => 5, 'window_seconds' => 60,
            'counted_by' => 'ip', 'action' => 'block', 'priority' => 10,
        ])->assertCreated()->json('data');

        $this->assertSame('5 / minute', $created['rate']);
        $this->assertSame('all domains', $created['scope']);
        $this->assertTrue($created['enforced']);

        $this->api($token)->getJson('/api/v1/firewall-rules')->assertOk()->assertJsonPath('data.0.name', 'Login brute force');

        $this->api($token)->patchJson("/api/v1/firewall-rules/{$created['id']}", ['limit' => 20, 'window_seconds' => 600])
            ->assertOk()->assertJsonPath('data.rate', '20 / 10 min');

        $this->api($token)->deleteJson("/api/v1/firewall-rules/{$created['id']}")->assertNoContent();
        $this->assertSame(0, FirewallRule::allTenants()->count());
    }

    public function test_an_action_the_proxy_cannot_enforce_is_reported_as_such(): void
    {
        [, , $token] = $this->org();
        $rule = $this->api($token)->postJson('/api/v1/firewall-rules', [
            'name' => 'Checkout abuse', 'paths' => ['/checkout'], 'limit' => 20,
            'window_seconds' => 600, 'action' => 'recaptcha',
        ])->assertCreated()->json('data');

        $this->assertFalse($rule['enforced'], 'the table must not imply a reCAPTCHA rule is in force');

        $this->api($token)->getJson('/api/v1/firewall')->assertOk()
            ->assertJsonPath('data.unsupported.0.name', 'Checkout abuse')
            ->assertJsonPath('data.challenge_enforcement', 'unavailable')
            ->assertJsonPath('data.country_enforcement', 'unavailable');
    }

    public function test_validation_rejects_a_bad_rule(): void
    {
        [, , $token] = $this->org();
        // This API wraps validation errors in its own envelope: error.fields.
        $this->api($token)->postJson('/api/v1/firewall-rules', ['name' => 'x', 'paths' => [], 'limit' => 0, 'action' => 'nope'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['fields' => ['paths', 'limit', 'action']]]);
    }

    public function test_country_policy_is_replaced_wholesale(): void
    {
        [, , $token] = $this->org();
        $this->api($token)->putJson('/api/v1/firewall/countries', ['countries' => [
            ['code' => 'ru', 'state' => 'block'],
            ['code' => 'cn', 'state' => 'challenge'],
        ]])->assertOk()->assertJsonCount(2, 'data.countries');

        // The codes are stored upper-case whatever the caller sent.
        $this->assertEqualsCanonicalizing(['RU', 'CN'], FirewallCountry::allTenants()->pluck('code')->all());

        // A second call replaces the list rather than adding to it.
        $this->api($token)->putJson('/api/v1/firewall/countries', ['countries' => [
            ['code' => 'KP', 'state' => 'block'],
        ]])->assertOk()->assertJsonCount(1, 'data.countries');
        $this->assertSame(['KP'], FirewallCountry::allTenants()->pluck('code')->all());

        // Clearing it is an empty list, not a missing key.
        $this->api($token)->putJson('/api/v1/firewall/countries', ['countries' => []])->assertOk();
        $this->assertSame(0, FirewallCountry::allTenants()->count());
    }

    public function test_protected_paths_can_be_added_toggled_and_removed(): void
    {
        [, , $token] = $this->org();
        $path = $this->api($token)->postJson('/api/v1/firewall/paths', ['path' => '/login'])->assertCreated()->json('data');
        $this->assertTrue($path['enabled']);

        $this->api($token)->patchJson("/api/v1/firewall/paths/{$path['id']}", ['enabled' => false])
            ->assertOk()->assertJsonPath('data.enabled', false);

        $this->api($token)->getJson('/api/v1/firewall')->assertOk()->assertJsonPath('data.paths.0.scope', 'all domains');
        $this->api($token)->deleteJson("/api/v1/firewall/paths/{$path['id']}")->assertNoContent();
    }

    public function test_events_are_listed_newest_first_and_filterable(): void
    {
        [, $org, $token] = $this->org();
        app(Tenant::class)->as($org->id, function () {
            FirewallEvent::create(['occurred_at' => now()->subMinutes(5), 'action' => 'block', 'ip' => '185.220.101.4', 'country' => 'RU', 'method' => 'POST', 'path' => '/wp-login.php', 'rule_name' => 'Country block']);
            FirewallEvent::create(['occurred_at' => now(), 'action' => 'throttle', 'ip' => '34.89.12.200', 'country' => 'US', 'method' => 'GET', 'path' => '/api/v1/products', 'rule_name' => 'API burst']);
        });

        $this->api($token)->getJson('/api/v1/firewall/events')->assertOk()
            ->assertJsonPath('data.0.action', 'throttle')
            ->assertJsonPath('data.0.request', 'GET /api/v1/products')
            ->assertJsonPath('data.1.rule', 'Country block');

        $this->api($token)->getJson('/api/v1/firewall/events?action=block')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.ip', '185.220.101.4');
    }

    public function test_settings_never_return_the_challenge_secret(): void
    {
        [, , $token] = $this->org();
        $this->api($token)->patchJson('/api/v1/settings', ['firewall' => [
            'provider' => 'turnstile', 'site_key' => 'site-abc', 'secret_key' => 'super-secret-value', 'min_score' => 0.7,
        ]])->assertOk();

        $body = $this->api($token)->getJson('/api/v1/settings')->assertOk()->json('data.firewall');
        $this->assertTrue($body['secret_key_set']);
        $this->assertArrayNotHasKey('secret_key', $body);
        $this->assertArrayNotHasKey('secret_key_encrypted', $body);
        $this->assertSame('site-abc', $body['site_key']);
        $this->assertStringNotContainsString('super-secret-value', json_encode($body));
    }

    public function test_another_organisation_cannot_see_or_touch_these_rules(): void
    {
        [, $mine, $token] = $this->org();
        [, , $theirToken] = $this->org();
        $rule = app(Tenant::class)->as($mine->id, fn () => FirewallRule::create([
            'name' => 'Mine', 'paths' => ['*'], 'limit' => 10, 'action' => 'block',
        ]));

        $this->api($theirToken)->getJson('/api/v1/firewall-rules')->assertOk()->assertJsonCount(0, 'data');
        $this->api($theirToken)->getJson("/api/v1/firewall-rules/{$rule->id}")->assertNotFound();
        $this->api($theirToken)->deleteJson("/api/v1/firewall-rules/{$rule->id}")->assertNotFound();
        unset($token);
    }
}
