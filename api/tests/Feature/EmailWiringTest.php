<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Services\EmailTemplateService;
use App\Support\Totp;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** EML: templates go out when their events happen, to the right people, once. */
class EmailWiringTest extends TestCase
{
    /** @var array<int, array{to: string, subject: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Event::listen(MessageSent::class, function (MessageSent $e) {
            $this->sent[] = ['to' => $e->message->getTo()[0]->getAddress(), 'subject' => (string) $e->message->getSubject()];
        });
    }

    public function test_register_sends_welcome_and_a_new_address_sends_a_sign_in_alert(): void
    {
        $this->postJson('/api/v1/auth/register', ['name' => 'Ann', 'email' => 'ann@example.test', 'password' => 'secret-pass', 'organization' => 'Acme'])->assertCreated();
        $this->assertSame('ann@example.test', $this->sent[0]['to']);
        $this->assertCount(1, $this->sent, 'welcome only');

        $login = fn (string $ip) => $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/v1/auth/login', ['email' => 'ann@example.test', 'password' => 'secret-pass'])->assertOk();
        $login('192.0.2.1');
        $this->assertCount(1, $this->sent, 'the first sign-in has nothing to compare with');
        $login('192.0.2.1');
        $this->assertCount(1, $this->sent, 'a known address sends nothing');
        $login('198.51.100.7');
        $this->assertCount(2, $this->sent);
        $this->assertStringNotContainsString('{{', $this->sent[1]['subject']);
    }

    public function test_turning_off_two_factor_sends_a_security_email(): void
    {
        [$user, , $token] = $this->org();
        $secret = $this->api($token)->postJson('/api/v1/me/mfa/enable')->json('data.secret');
        $this->api($token)->postJson('/api/v1/me/mfa/confirm', ['otp' => Totp::code($secret)])->assertOk();
        $this->sent = [];
        $this->api($token)->postJson('/api/v1/me/mfa/disable', ['otp' => Totp::code($secret)])->assertOk();
        $this->assertSame([$user->email], array_column($this->sent, 'to'));
    }

    public function test_organisation_emails_go_to_the_roles_for_the_group_once(): void
    {
        [$owner, $org] = $this->org();
        [$billing] = $this->member($org, 'billing');
        [$developer] = $this->member($org, 'developer');
        $svc = app(EmailTemplateService::class);

        $this->assertSame(2, $svc->notifyOrganization($org->id, 'deploy_failed', $svc->sample('deploy_failed')));
        $this->assertEqualsCanonicalizing([$owner->email, $developer->email], array_column($this->sent, 'to'));

        $this->sent = [];
        $this->assertSame(2, $svc->notifyOrganization($org->id, 'invoice', $svc->sample('invoice')));
        $this->assertEqualsCanonicalizing([$owner->email, $billing->email], array_column($this->sent, 'to'));

        $this->assertSame(1, $svc->notifyOrganization($org->id, 'server_unreachable', $svc->sample('server_unreachable'), 'srv-1', 60));
        $this->assertSame(0, $svc->notifyOrganization($org->id, 'server_unreachable', $svc->sample('server_unreachable'), 'srv-1', 60), 'deduplicated');
    }

    public function test_a_mail_outage_never_fails_the_event(): void
    {
        [$owner, $org] = $this->org();
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('SMTP down'));
        $this->assertSame(0, app(EmailTemplateService::class)->notifyOrganization($org->id, 'alert', ['title' => 't', 'message' => 'm', 'severity' => 'high']));
    }

    public function test_deployments_email_on_success_and_on_failure(): void
    {
        [$owner, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $appId = $this->api($token)->postJson('/api/v1/applications', ['name' => 'shop', 'source_type' => 'image', 'image' => 'ghcr.io/acme/shop:1.0', 'server_id' => $server->id, 'port' => 8080])->assertCreated()->json('data.id');

        $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->assertStatus(202);
        $cmd = $this->heartbeat($secret)['commands'][0];
        $this->commandResult($secret, $cmd['id'], 'succeeded', ['output' => ['container_id' => 'd-1']])->assertOk();
        $this->passHealthChecks($secret);
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('shop', $this->sent[0]['subject']);

        $this->sent = [];
        $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->assertStatus(202);
        for ($i = 0; $i < 4 && ! $this->sent; $i++) {
            foreach ($this->heartbeat($secret)['commands'] as $c) {
                $this->commandResult($secret, $c['id'], 'failed', ['message' => 'exit 1'])->assertOk();
            }
        }
        $this->assertNotEmpty($this->sent, 'a failed deployment emails the team');
        $this->assertSame($owner->email, $this->sent[0]['to']);
    }

    public function test_a_server_that_stops_reporting_emails_once(): void
    {
        [$owner, $org] = $this->org();
        $server = $this->server($org);
        Server::allTenants()->whereKey($server->id)->update(['last_heartbeat_at' => now()->subMinutes(10)]);

        $this->artisan('servers:check-heartbeats')->assertSuccessful();
        $this->artisan('servers:check-heartbeats')->assertSuccessful();
        $this->assertSame([$owner->email], array_column($this->sent, 'to'));
    }
}
