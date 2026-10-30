<?php

namespace Tests\Feature;

use App\Support\Smtp;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** SET-002: "Send test email" uses the saved SMTP settings and reports the server's answer. */
class SmtpTestEmailTest extends TestCase
{
    public function test_starttls_and_ssl_map_to_schemes_symfony_mailer_supports(): void
    {
        $this->assertSame(['smtp', 'smtp', 'smtps', 'smtp'], [Smtp::scheme('tls'), Smtp::scheme('none'), Smtp::scheme('ssl'), Smtp::scheme(null)]);
    }

    public function test_a_test_email_needs_a_host_and_reports_failures(): void
    {
        [$user, , $token] = $this->org('super_admin');
        $this->api($token)->patchJson('/api/v1/settings', ['smtp' => ['host' => '']])->assertOk();
        $this->api($token)->postJson('/api/v1/settings/smtp/test')->assertStatus(409);

        // Port 1 on loopback refuses the connection.
        $this->api($token)->patchJson('/api/v1/settings', ['smtp' => ['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'tls', 'password' => 'secret', 'from_address' => 'alerts@example.com']])->assertOk();
        $res = $this->api($token)->postJson('/api/v1/settings/smtp/test', ['to' => 'ops@example.com'])->assertStatus(502)->json('error.message');
        $this->assertStringStartsWith('The SMTP server refused the test email:', $res);
        $this->assertStringNotContainsString('secret', $res);

        Mail::fake();
        $this->api($token)->postJson('/api/v1/settings/smtp/test')->assertOk()->assertJsonPath('data.to', $user->email);
        $this->api($token)->postJson('/api/v1/settings/smtp/test', ['to' => 'not-an-email'])->assertStatus(422);
    }
}
