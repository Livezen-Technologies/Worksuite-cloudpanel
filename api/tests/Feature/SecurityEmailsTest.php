<?php

namespace Tests\Feature;

use App\Services\EmailTemplateService;
use App\Services\FirewallEventService;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** EML: the design's under-attack (customer and staff) and CrowdSec emails, with the new layout. */
class SecurityEmailsTest extends TestCase
{
    private function capture(): \ArrayObject
    {
        $sent = new \ArrayObject;
        Event::listen(MessageSent::class, fn (MessageSent $e) => $sent->append($e->message));

        return $sent;
    }

    public function test_under_attack_mails_the_customer_and_staff_once(): void
    {
        $sent = $this->capture();
        [, $staffOrg] = $this->org('super_admin');
        [$owner, $org, $token] = $this->org('owner', 'Acme Ltd');
        $this->api($token)->patchJson('/api/v1/firewall/under-attack', ['enabled' => true])->assertOk();
        $this->api($token)->patchJson('/api/v1/firewall/under-attack', ['enabled' => true])->assertOk();

        $subjects = collect($sent)->map->getSubject()->all();
        $this->assertContains('all your sites is under attack — protection is on', $subjects);
        $staff = collect($sent)->first(fn ($m) => str_starts_with($m->getSubject(), '[CRITICAL]'));
        $this->assertNotNull($staff, 'staff are told too');
        $this->assertStringContainsString('Acme Ltd', $staff->getHtmlBody());
        $this->assertCount(2, $sent, 'a second switch-on while it is on sends nothing');
    }

    public function test_crowdsec_bursts_mail_staff_at_most_hourly_and_render_the_log(): void
    {
        $sent = $this->capture();
        $this->org('super_admin');
        $svc = app(FirewallEventService::class);
        $few = array_fill(0, 10, ['decision' => 'ban', 'scenario' => 'crowdsecurity/http-probing', 'country' => 'BR']);
        $svc->maybeAlertStaff($few);
        $this->assertCount(0, $sent, 'below the threshold nothing is sent');

        $burst = array_merge(array_fill(0, 60, ['decision' => 'ban', 'scenario' => 'crowdsecurity/http-bf-wordpress_bf', 'country' => 'VN', 'host' => 'a.lk', 'path' => '/wp-login.php']), $few);
        $svc->maybeAlertStaff($burst);
        $svc->maybeAlertStaff($burst);
        $this->assertCount(1, $sent);
        $m = $sent[0];
        $this->assertSame('[CrowdSec] 70 bot requests blocked in 5 minutes', $m->getSubject());
        $this->assertStringContainsString('VN 86%', $m->getHtmlBody());
        $this->assertStringContainsString('60     crowdsecurity/http-bf-wordpress_bf', $m->getTextBody(), 'the log block is in the text part');
    }

    public function test_layout_has_the_tag_eyebrow_bar_and_code_block(): void
    {
        $svc = app(EmailTemplateService::class);
        $p = $svc->render('deploy_failed', $svc->sample('deploy_failed'));
        $this->assertStringContainsString('DEPLOYMENTS', $p['html'], 'category tag');
        $this->assertStringContainsString('linear-gradient(90deg,#fecaca', $p['html'], 'red tone bar');
        $this->assertStringContainsString('npm ERR! missing script: build</div>', $p['html'], 'the error sits in the code block');
        $p = $svc->render('under_attack', $svc->sample('under_attack'));
        $this->assertStringContainsString('Under attack</div>', $p['html'], 'eyebrow');
    }
}
