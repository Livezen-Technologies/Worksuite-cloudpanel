<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Container;
use App\Models\Mailbox;
use App\Models\MailServer;
use App\Services\MailGuardService;
use App\Services\MailService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** MCL: delivery log, sending holds, per-domain limits and per-mailbox spam settings. */
class MailGuardTest extends TestCase
{
    /** Answers every queued command until none is left; returns the fs.write payloads by path. */
    private function drain(string $secret): array
    {
        $writes = [];
        for ($i = 0; $i < 50; $i++) {
            $cmds = $this->heartbeat($secret)['commands'];
            if (! $cmds) {
                break;
            }
            foreach ($cmds as $c) {
                if ($c['type'] === 'fs.write') {
                    $writes[$c['payload']['path']] = $c['payload']['content'];
                }
                $this->commandResult($secret, $c['id'], 'succeeded', ['output' => []])->assertOk();
            }
        }

        return $writes;
    }

    private function setUpMail(): array
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['ip' => '198.51.100.20']);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $this->api($token)->postJson('/api/v1/mail/servers', ['server_id' => $server->id, 'hostname' => 'mail.example.com'])->assertStatus(202);
        $certs = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'mail.certs');
        $this->assertSame(['mail.example.com', 'mailserver-config', true], [$certs['payload']['hostname'], $certs['payload']['volume'], $certs['payload']['fallback']]);
        $this->commandResult($secret, $certs['id'], 'succeeded', ['output' => ['found' => false, 'source' => 'self-signed', 'changed' => true, 'not_after' => '2028-10-31T00:00:00Z']])->assertOk();
        $create = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'container.create');
        $this->assertSame(['manual', '/tmp/docker-mailserver/ssl/cert.pem'], [$create['payload']['env']['SSL_TYPE'], $create['payload']['env']['SSL_CERT_PATH']]);
        $this->assertSame([MailService::IMAGE, 'mail.example.com'], [$create['payload']['image'], $create['payload']['env']['OVERRIDE_HOSTNAME']]);
        $this->assertSame([25, 465, 587, 993], array_column($create['payload']['ports'], 'container_port'));
        $this->commandResult($secret, $create['id'], 'succeeded', ['output' => ['id' => 'abc']])->assertOk();
        $this->drain($secret);
        $mail = MailServer::allTenants()->sole();
        $this->assertSame(['running', 'self-signed'], [$mail->status, $mail->tls['source']]);

        return [$token, $secret, $mail];
    }

    private function log(int $sent, int $bounced = 0, string $user = 'sales@example.com', string $start = '2026-11-02T10:00:00Z'): string
    {
        $lines = [];
        $t = Carbon::parse($start);
        for ($i = 0; $i < $sent + $bounced; $i++) {
            $q = sprintf('A%05X', $i);
            $at = $t->copy()->addSeconds($i)->format('Y-m-d\TH:i:s.000\Z');
            $lines[] = "{$at} mail postfix/submission/smtpd[11]: {$q}: client=unknown[203.0.113.7], sasl_method=PLAIN, sasl_username={$user}";
            $lines[] = "{$at} mail postfix/qmgr[12]: {$q}: from=<{$user}>, size=900, nrcpt=1 (queue active)";
            $status = $i < $sent ? 'sent (250 2.0.0 OK)' : 'bounced (host said: 550 5.1.1 no such user)';
            $lines[] = "{$at} mail postfix/smtp[13]: {$q}: to=<user{$i}@gmail.com>, relay=gmail-smtp-in.l.google.com[142.250.1.1]:25, delay=1, dsn=2.0.0, status={$status}";
        }
        $lines[] = $t->format('Y-m-d\TH:i:s.000\Z').' mail postfix/lmtp[14]: B00001: to=<sales@example.com>, relay=mail[/var/run/dovecot/lmtp], delay=0.1, dsn=2.0.0, status=sent (250 2.0.0 Saved)';
        $lines[] = $t->format('Y-m-d\TH:i:s.000\Z').' mail postfix/smtpd[15]: NOQUEUE: reject: RCPT from bad.example[198.51.100.66]: 554 5.7.1 Service unavailable; Client host blocked; from=<spam@bad.example> to=<sales@example.com> proto=ESMTP helo=<x>';

        return implode("\n", $lines)."\n";
    }

    public function test_postfix_log_lines_become_entries(): void
    {
        $e = MailGuardService::parse($this->log(2, 1));
        $this->assertCount(5, $e);
        $this->assertSame(['out', 'sales@example.com', 'user0@gmail.com', 'sent', 'gmail-smtp-in.l.google.com[142.250.1.1]:25'], [$e[0]['direction'], $e[0]['sender'], $e[0]['recipient'], $e[0]['status'], $e[0]['relay']]);
        $this->assertSame('bounced', $e[2]['status']);
        $this->assertSame(['in', 'sent'], [$e[3]['direction'], $e[3]['status']]);
        $this->assertSame(['rejected', 'spam@bad.example'], [$e[4]['status'], $e[4]['sender']]);
        $this->assertCount(0, MailGuardService::parse($this->log(2), Carbon::parse('2026-11-02T11:00:00Z')), 'the cursor skips what was seen');
    }

    public function test_spammers_are_held_logs_are_searchable_and_limits_apply(): void
    {
        Carbon::setTestNow('2026-11-02 10:30:00');
        [$token, $secret, $mail] = $this->setUpMail();
        $d = $this->api($token)->postJson('/api/v1/mail/domains', ['mail_server_id' => $mail->id, 'name' => 'example.com'])->assertCreated()->json('data');
        $box = $this->api($token)->postJson("/api/v1/mail/domains/{$d['id']}/mailboxes", ['local_part' => 'sales', 'password' => 'correct horse battery'])->assertCreated()->json('data');
        $this->api($token)->patchJson("/api/v1/mail/servers/{$mail->id}", ['send_per_hour' => 50])->assertOk();
        $this->drain($secret);

        // 60 sends in the hour against a limit of 50: held, alerted, and blocked in Postfix.
        Container::allTenants()->where('name', MailService::CONTAINER)->update(['last_logs' => $this->log(60)]);
        $this->artisan('mail:watch')->assertSuccessful();
        $this->assertNotNull(Mailbox::allTenants()->find($box['id'])->held_at);
        $this->assertStringContainsString('Sent 60 messages in an hour (limit 50)', Alert::allTenants()->where('title', 'Outgoing mail held for sales@example.com')->value('message'));
        $files = $this->drain($secret);
        $this->assertStringContainsString('sales@example.com REJECT', $files['postfix-send-access.cf']);
        $this->artisan('mail:watch')->assertSuccessful();
        $this->assertSame(62, \App\Models\MailLogEntry::count(), 'nothing is stored twice');

        $holds = $this->api($token)->getJson('/api/v1/mail/holds')->assertOk()->json('data.mailboxes');
        $this->assertSame('sales@example.com', $holds[0]['address']);
        $this->api($token)->deleteJson("/api/v1/mail/domains/{$d['id']}/mailboxes/{$box['id']}/hold")->assertNoContent();
        $this->assertSame('', $this->drain($secret)['postfix-send-access.cf']);

        // Searchable log, for the organisation's own domains.
        $rows = $this->api($token)->getJson('/api/v1/mail/log?q=user7@gmail&direction=out')->assertOk()->json('data');
        $this->assertSame(['user7@gmail.com'], array_column($rows, 'recipient'));
        $this->assertCount(1, $this->api($token)->getJson('/api/v1/mail/log?status=rejected')->json('data'));
        [, , $other] = $this->org();
        $this->assertSame([], $this->api($other)->getJson('/api/v1/mail/log')->json('data'));

        // Per-domain limits.
        $this->api($token)->patchJson("/api/v1/mail/domains/{$d['id']}/limits", ['max_mailboxes' => 1, 'quota_total_mb' => 1500, 'send_per_hour' => 10])->assertOk();
        $this->api($token)->postJson("/api/v1/mail/domains/{$d['id']}/mailboxes", ['local_part' => 'info', 'password' => 'correct horse battery'])->assertStatus(402);
        $this->api($token)->patchJson("/api/v1/mail/domains/{$d['id']}/mailboxes/{$box['id']}", ['quota_mb' => 2048])->assertStatus(402);
        Carbon::setTestNow('2026-11-02 11:20:00');
        Container::allTenants()->where('name', MailService::CONTAINER)->update(['last_logs' => $this->log(20, 0, 'sales@example.com', '2026-11-02T11:10:00Z')]);
        $this->artisan('mail:watch')->assertSuccessful();
        $this->assertSame('sent 20 messages in an hour (limit 10)', \App\Models\MailDomain::allTenants()->find($d['id'])->held_reason);
        $this->assertStringContainsString("example.com REJECT", $this->drain($secret)['postfix-send-access.cf']);
        Carbon::setTestNow();
    }

    public function test_bounce_storms_are_held_and_spam_levels_become_sieve(): void
    {
        Carbon::setTestNow('2026-11-02 10:30:00');
        [$token, $secret, $mail] = $this->setUpMail();
        $d = $this->api($token)->postJson('/api/v1/mail/domains', ['mail_server_id' => $mail->id, 'name' => 'example.com'])->assertCreated()->json('data');
        $box = $this->api($token)->postJson("/api/v1/mail/domains/{$d['id']}/mailboxes", ['local_part' => 'sales', 'password' => 'correct horse battery'])->assertCreated()->json('data');
        $this->drain($secret);
        Container::allTenants()->where('name', MailService::CONTAINER)->update(['last_logs' => $this->log(5, 20)]);
        $this->artisan('mail:watch')->assertSuccessful();
        $this->assertSame('20 of 25 messages bounced in an hour', Mailbox::allTenants()->find($box['id'])->held_reason);

        $this->api($token)->patchJson("/api/v1/mail/domains/{$d['id']}/mailboxes/{$box['id']}/spam", ['level' => 'strict', 'allow' => ['Boss@Partner.com'], 'block' => ['spammy.example']])->assertOk();
        $this->api($token)->patchJson("/api/v1/mail/domains/{$d['id']}/mailboxes/{$box['id']}/spam", ['level' => 'paranoid'])->assertStatus(422);
        $sieve = $this->drain($secret)['sales@example.com.dovecot.sieve'];
        $this->assertStringContainsString('require ["fileinto", "mailbox", "relational", "comparator-i;ascii-numeric"];', $sieve);
        $this->assertStringContainsString('if anyof(address :is "from" ["boss@partner.com"]) { keep; stop; }', $sieve);
        $this->assertStringContainsString('if anyof(address :domain :is "from" ["spammy.example"]) { fileinto :create "Junk"; stop; }', $sieve);
        $this->assertStringContainsString('"X-Rspamd-Score" "3"', $sieve);
        $this->assertStringContainsString('not header :matches "X-Rspamd-Score" "-*"', $sieve);
        $this->assertStringContainsString('extended_spam_headers = true;', MailServer::allTenants()->get()->map(fn ($m) => app(MailService::class)->files($m)['rspamd/override.d/milter_headers.conf'])->first());

        // The auto-reply and the spam rules share one script.
        $this->api($token)->patchJson("/api/v1/mail/domains/{$d['id']}/mailboxes/{$box['id']}/autoresponder", ['enabled' => true, 'subject' => 'Away', 'body' => 'Back Monday'])->assertOk();
        $sieve = $this->drain($secret)['sales@example.com.dovecot.sieve'];
        $this->assertStringContainsString('"vacation"', $sieve);
        $this->assertTrue(strpos($sieve, 'Junk') < strpos($sieve, 'vacation :days'), 'spam is filed before any auto-reply is sent');
        Carbon::setTestNow();
    }
}
