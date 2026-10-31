<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Security audit, phase 6: headers on API responses. */
class SecurityHeadersTest extends TestCase
{
    public function test_json_responses_carry_security_headers_and_cannot_be_framed(): void
    {
        [, , $token] = $this->org();
        $r = $this->api($token)->getJson('/api/v1/me')->assertOk();
        $r->assertHeader('X-Content-Type-Options', 'nosniff');
        $r->assertHeader('X-Frame-Options', 'DENY');
        $r->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $r->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        $this->assertStringContainsString('camera=()', $r->headers->get('Permissions-Policy'));
        $r->assertHeaderMissing('Strict-Transport-Security'); // plain HTTP in tests
        $r->assertHeaderMissing('X-Powered-By');

        // Errors carry them too (a fresh, signed-out request).
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/servers')->assertUnauthorized()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_https_responses_add_hsts(): void
    {
        [, , $token] = $this->org();
        $this->api($token)->getJson('https://localhost/api/v1/me')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
