<?php

namespace Tests\Feature;

use App\Services\EmailTemplateService;
use Illuminate\Support\Facades\Event;
use Illuminate\Mail\Events\MessageSent;
use Tests\TestCase;

/** SRS EML-1..5: branded transactional emails with validated variables. */
class EmailTemplatesTest extends TestCase
{
    public function test_the_catalogue_has_the_templates_in_seven_groups(): void
    {
        [, , $token] = $this->org('super_admin');
        $groups = $this->api($token)->getJson('/api/v1/email-templates')->assertOk()->json('data.groups');
        $this->assertSame(['account', 'security', 'deployments', 'domains', 'backups', 'alerts', 'billing'], array_column($groups, 'id'));
        $this->assertSame(26, collect($groups)->sum(fn ($g) => count($g['templates'])), '19, malware found, under attack (customer and staff), CrowdSec alert, application installed (customer and staff), sign-in paused');
        $this->assertTrue(collect($groups)->firstWhere('id', 'billing')['mandatory']);
    }

    public function test_every_template_renders_html_and_text_with_escaped_values(): void
    {
        $svc = app(EmailTemplateService::class);
        foreach (array_keys(config('email_templates.templates')) as $key) {
            $mail = $svc->render($key, $svc->sample($key));
            $this->assertNotSame('', $mail['subject'], $key);
            $this->assertStringNotContainsString('{{', $mail['html'].$mail['text'].$mail['subject'], "{$key} left a variable unfilled");
            $this->assertStringContainsString('max-width:600px', $mail['html']);
            $this->assertStringContainsString('prefers-color-scheme:dark', $mail['html']);
            $this->assertStringNotContainsString('<', $mail['text'], "{$key} plain text has no markup");
        }
        $mail = $svc->render('alert', ['title' => '<script>x</script>', 'message' => 'a & b', 'severity' => 'critical']);
        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $mail['html']);
        $this->assertStringNotContainsString('<script>x', $mail['html']);
        $this->assertSame('[critical] <script>x</script>', $mail['subject']);
        $this->assertStringContainsString('a & b', $mail['text']);
        // Unsafe links never become buttons.
        $mail = $svc->render('deploy_succeeded', ['app' => 'x', 'version' => '1', 'url' => 'javascript:alert(1)', 'duration' => '1s']);
        $this->assertStringNotContainsString('javascript:', $mail['html']);
    }

    public function test_admins_edit_copy_with_validated_variables_and_brand(): void
    {
        [, , $token] = $this->org('super_admin');
        $this->api($token)->patchJson('/api/v1/email-templates/deploy_failed', ['subject' => 'Oops {{app}} {{password}}'])->assertStatus(422)
            ->assertJsonPath('error.fields.subject.0', fn ($m) => str_contains($m, '{{password}}'));
        $t = $this->api($token)->patchJson('/api/v1/email-templates/deploy_failed', ['subject' => '{{app}} broke at {{stage}}', 'secondary_label' => 'Docs', 'secondary_url' => 'https://docs.example.com'])
            ->assertOk()->json('data');
        $this->assertSame([true, '{{app}} broke at {{stage}}'], [$t['customised'], $t['subject']]);
        $this->api($token)->patchJson('/api/v1/email-templates/password_reset', ['enabled' => false])->assertStatus(422);
        $this->api($token)->patchJson('/api/v1/email-templates/welcome', ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);

        $this->api($token)->patchJson('/api/v1/email-templates/brand', ['color' => 'red'])->assertStatus(422);
        $this->api($token)->patchJson('/api/v1/email-templates/brand', ['footer' => '{{secret}}'])->assertStatus(422);
        $this->api($token)->patchJson('/api/v1/email-templates/brand', ['sender_name' => 'Livezen Cloud', 'color' => '#0EA5E9', 'logo_url' => 'https://cdn.example.com/logo.png'])->assertOk();

        $p = $this->api($token)->postJson('/api/v1/email-templates/deploy_failed/preview')->assertOk()->json('data');
        $this->assertSame('shop broke at build', $p['subject']);
        $this->assertStringContainsString('#0EA5E9', $p['html']);
        $this->assertStringContainsString('https://cdn.example.com/logo.png', $p['html']);
        $this->assertStringContainsString('Docs: https://docs.example.com', $p['text']);

        $this->api($token)->postJson('/api/v1/email-templates/deploy_failed/reset')->assertOk()->assertJsonPath('data.customised', false);
        $this->api($token)->getJson('/api/v1/email-templates/nope')->assertNotFound();

        [, , $member] = $this->org('viewer');
        $this->api($member)->getJson('/api/v1/email-templates')->assertForbidden();
    }

    public function test_sending_respects_switches_and_user_preferences_but_not_for_security_and_billing(): void
    {
        $sent = [];
        Event::listen(MessageSent::class, function (MessageSent $e) use (&$sent) {
            $sent[] = $e->message;
        });
        [$user, , $token] = $this->org('super_admin');
        $svc = app(EmailTemplateService::class);
        $this->api($token)->patchJson('/api/v1/me/email-preferences', ['opt_out' => ['deploy_succeeded', 'password_reset', 'invoice']])->assertOk();
        $this->assertSame(['deploy_succeeded'], $user->fresh()->email_preferences['opt_out'], 'security and billing cannot be opted out of');

        $this->assertFalse($svc->send('deploy_succeeded', $user->fresh(), $svc->sample('deploy_succeeded')));
        $this->assertTrue($svc->send('password_reset', $user->fresh(), $svc->sample('password_reset')));
        $this->assertTrue($svc->send('deploy_succeeded', 'ops@example.com', $svc->sample('deploy_succeeded')), 'an address has no preferences');
        $this->assertCount(2, $sent);
        $msg = $sent[0];
        $this->assertSame('Reset your password', $msg->getSubject());
        $this->assertStringContainsString('<!doctype html>', $msg->getHtmlBody());
        $this->assertStringContainsString('Choose a new password: ', $msg->getTextBody());

        $this->api($token)->postJson('/api/v1/email-templates/invoice/test', ['to' => 'me@example.com'])->assertOk()->assertJsonPath('data.to', 'me@example.com');
        $this->assertSame('[Test] Invoice INV-2026-0042 for LKR 12,500.00', end($sent)->getSubject());
    }
}
