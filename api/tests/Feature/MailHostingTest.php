<?php

namespace Tests\Feature;

use App\Contracts\DnsResolver;
use App\Models\AgentCommand;
use App\Models\Job;
use App\Models\MailServer;
use App\Services\MailService;
use Tests\TestCase;

/** SRS MAIL-001..010, 012, 014: a mail stack per server, domains with DKIM, mailboxes, aliases, DNS. */
class MailHostingTest extends TestCase
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
        $create = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'container.create');
        $this->assertSame([MailService::IMAGE, 'mail.example.com'], [$create['payload']['image'], $create['payload']['env']['OVERRIDE_HOSTNAME']]);
        $this->assertSame([25, 465, 587, 993], array_column($create['payload']['ports'], 'container_port'));
        $this->commandResult($secret, $create['id'], 'succeeded', ['output' => ['id' => 'abc']])->assertOk();
        $this->drain($secret);
        $mail = MailServer::allTenants()->sole();
        $this->assertSame('running', $mail->status);

        return [$token, $secret, $mail];
    }

    public function test_domains_mailboxes_and_aliases_become_mailserver_config(): void
    {
        [$token, $secret, $mail] = $this->setUpMail();
        $this->api($token)->postJson('/api/v1/mail/servers', ['server_id' => $mail->server_id, 'hostname' => 'mail2.example.com'])->assertStatus(409);

        $d = $this->api($token)->postJson('/api/v1/mail/domains', ['mail_server_id' => $mail->id, 'name' => 'Example.com'])->assertCreated()->json('data');
        $this->assertSame('example.com', $d['name']);
        $records = collect($d['records'])->keyBy(fn ($r) => $r['type'].' '.$r['name']);
        $this->assertSame('mail.example.com', $records['MX example.com']['value']);
        $this->assertSame('v=spf1 mx a:mail.example.com ~all', $records['TXT example.com']['value']);
        $this->assertStringStartsWith('v=DKIM1; k=rsa; p=MIIB', $records['TXT mail._domainkey.example.com']['value']);
        $this->assertStringStartsWith('v=DMARC1; p=quarantine', $records['TXT _dmarc.example.com']['value']);
        $this->api($token)->postJson('/api/v1/mail/domains', ['mail_server_id' => $mail->id, 'name' => 'example.com'])->assertStatus(409);

        $box = $this->api($token)->postJson("/api/v1/mail/domains/{$d['id']}/mailboxes", ['local_part' => 'Sales', 'password' => 'correct horse battery', 'quota_mb' => 2048])->assertCreated()->json('data');
        $this->assertSame('sales', $box['local_part']);
        $this->api($token)->postJson("/api/v1/mail/domains/{$d['id']}/mailboxes", ['local_part' => 'bad name', 'password' => 'correct horse battery'])->assertStatus(422);
        $this->api($token)->postJson("/api/v1/mail/domains/{$d['id']}/aliases", ['local_part' => 'info', 'destinations' => ['sales@example.com', 'boss@gmail.com']])->assertCreated();
        $this->api($token)->postJson("/api/v1/mail/domains/{$d['id']}/aliases", ['local_part' => 'sales', 'destinations' => ['x@example.com']])->assertStatus(409);
        $this->api($token)->postJson("/api/v1/mail/domains/{$d['id']}/aliases", ['local_part' => 'x', 'destinations' => ['not-mail']])->assertStatus(422);
        $this->api($token)->patchJson("/api/v1/mail/domains/{$d['id']}", ['catch_all' => 'sales@example.com'])->assertOk();

        $files = $this->drain($secret);
        [$address, $hash] = explode('|', trim($files['postfix-accounts.cf']));
        $this->assertSame('sales@example.com', $address);
        $this->assertStringStartsWith('{SHA512-CRYPT}$6$', $hash);
        $this->assertTrue(hash_equals(substr($hash, 14), crypt('correct horse battery', substr($hash, 14))), 'Dovecot can verify the hash');
        $this->assertSame("info@example.com sales@example.com,boss@gmail.com\n@example.com sales@example.com\n", $files['postfix-virtual.cf']);
        $this->assertSame("sales@example.com:2048M\n", $files['dovecot-quotas.cf']);
        $this->assertSame("mail._domainkey.example.com example.com:mail:/etc/opendkim/keys/example.com/mail.private\n", $files['opendkim/KeyTable']);
        $this->assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $files['opendkim/keys/example.com/mail.private']);
        $this->assertStringNotContainsString('PRIVATE KEY', json_encode(Job::allTenants()->get()->pluck('payload')), 'keys are read at dispatch, never stored in jobs');
        $this->assertStringNotContainsString('SHA512', json_encode(Job::allTenants()->get()->pluck('payload')));

        // Disabling a mailbox drops it from the accounts file.
        $this->api($token)->patchJson("/api/v1/mail/domains/{$d['id']}/mailboxes/{$box['id']}", ['enabled' => false])->assertOk();
        $this->assertSame('', $this->drain($secret)['postfix-accounts.cf']);
        $this->assertArrayNotHasKey('password_hash', $this->api($token)->getJson("/api/v1/mail/domains/{$d['id']}")->json('data.mailbox_list.0'));

        [, , $other] = $this->org();
        $this->api($other)->getJson("/api/v1/mail/domains/{$d['id']}")->assertNotFound();
    }

    public function test_dns_records_are_checked(): void
    {
        [$token, , $mail] = $this->setUpMail();
        $d = $this->api($token)->postJson('/api/v1/mail/domains', ['mail_server_id' => $mail->id, 'name' => 'shop.lk'])->assertCreated()->json('data');
        $dns = app(DnsResolver::class);
        $dns->set('shop.lk', 'MX', ['10 mail.example.com.']);
        $dns->set('shop.lk', 'TXT', ['"v=spf1 mx a:mail.example.com ~all"']);
        $check = collect($this->api($token)->postJson("/api/v1/mail/domains/{$d['id']}/dns/check")->assertOk()->json('data'))->keyBy(fn ($r) => $r['type'].' '.$r['name']);
        $this->assertSame(['ok', 'ok', 'missing', 'missing'], [$check['MX shop.lk']['status'], $check['TXT shop.lk']['status'], $check['TXT mail._domainkey.shop.lk']['status'], $check['TXT _dmarc.shop.lk']['status']]);
        $this->api($token)->getJson('/api/v1/mail')->assertOk()->assertJsonPath('data.domains.0.dns_ok', false)->assertJsonPath('data.servers.0.status', 'running');
    }
    public function test_limits_spam_scores_and_relay_restart_the_mail_server(): void
    {
        [$token, $secret, $mail] = $this->setUpMail();
        $this->api($token)->patchJson("/api/v1/mail/servers/{$mail->id}", ['spam_header' => 20])->assertStatus(422);
        $this->api($token)->patchJson("/api/v1/mail/servers/{$mail->id}", ['relay' => ['host' => 'email-smtp.eu-north-1.amazonaws.com', 'username' => 'AKIA']])->assertStatus(422);
        $this->api($token)->patchJson("/api/v1/mail/servers/{$mail->id}", ['send_per_hour' => 50, 'spam_reject' => 12, 'relay' => ['host' => 'email-smtp.eu-north-1.amazonaws.com', 'port' => 587, 'username' => 'AKIA', 'password' => 's3cret-relay']])
            ->assertOk()->assertJsonPath('data.relay.password_set', true)->assertJsonMissingPath('data.relay.password');
        $files = $this->drain($secret);
        $this->assertSame("rates {\n  user = \"50 / 1h\";\n}\n", $files['rspamd/override.d/ratelimit.conf']);
        $this->assertStringContainsString('reject = 12;', $files['rspamd/override.d/actions.conf']);
        $this->assertStringContainsString('relayhost = [email-smtp.eu-north-1.amazonaws.com]:587', $files['postfix-main.cf']);
        $this->assertSame("[email-smtp.eu-north-1.amazonaws.com]:587 AKIA:s3cret-relay\n", $files['postfix-sasl-password.cf']);
        $this->assertStringNotContainsString('s3cret-relay', json_encode(Job::allTenants()->get()->pluck('payload')));
        $this->assertTrue(AgentCommand::allTenants()->where('type', 'container.restart')->exists(), 'settings changes restart the container');

        $this->api($token)->patchJson("/api/v1/mail/servers/{$mail->id}", ['relay' => null])->assertOk()->assertJsonPath('data.relay', null);
        $files = $this->drain($secret);
        $this->assertSame('', $files['postfix-main.cf']);
        $this->assertSame('', $files['postfix-sasl-password.cf']);
    }

    public function test_dkim_rotation_waits_for_dns(): void
    {
        [$token, $secret, $mail] = $this->setUpMail();
        $d = $this->api($token)->postJson('/api/v1/mail/domains', ['mail_server_id' => $mail->id, 'name' => 'example.com'])->json('data');
        $this->drain($secret);
        $this->api($token)->postJson("/api/v1/mail/domains/{$d['id']}/dkim/complete")->assertStatus(409);
        $r = $this->api($token)->postJson("/api/v1/mail/domains/{$d['id']}/dkim/rotate")->assertOk()->json('data');
        $selector = 'mail'.now()->format('Ym');
        $this->assertSame([$selector, 'mail'], [$r['dkim_next_selector'], $r['dkim_selector']]);
        $next = collect($r['records'])->firstWhere('next', true);
        $this->assertSame("{$selector}._domainkey.example.com", $next['name']);
        $files = $this->drain($secret);
        $this->assertArrayHasKey("opendkim/keys/example.com/{$selector}.private", $files);
        $this->assertStringNotContainsString($selector, $files['opendkim/SigningTable'], 'the new key does not sign yet');

        $this->api($token)->postJson("/api/v1/mail/domains/{$d['id']}/dkim/complete")->assertStatus(409);
        app(DnsResolver::class)->set($next['name'], 'TXT', ['"'.$next['value'].'"']);
        $done = $this->api($token)->postJson("/api/v1/mail/domains/{$d['id']}/dkim/complete")->assertOk()->json('data');
        $this->assertSame([$selector, 'mail', null], [$done['dkim_selector'], $done['dkim_previous_selector'], $done['dkim_next_selector']]);
        $this->assertSame("*@example.com {$selector}._domainkey.example.com\n", $this->drain($secret)['opendkim/SigningTable']);
    }

    public function test_auto_replies_health_and_logs(): void
    {
        [$token, $secret, $mail] = $this->setUpMail();
        $d = $this->api($token)->postJson('/api/v1/mail/domains', ['mail_server_id' => $mail->id, 'name' => 'example.com'])->json('data');
        $box = $this->api($token)->postJson("/api/v1/mail/domains/{$d['id']}/mailboxes", ['local_part' => 'ceo', 'password' => 'correct horse battery'])->json('data');
        $this->drain($secret);
        $until = now()->addDays(7)->toDateString();
        $this->api($token)->patchJson("/api/v1/mail/domains/{$d['id']}/mailboxes/{$box['id']}/autoresponder", ['enabled' => true, 'subject' => 'Away "until" Monday', 'body' => "Back soon.\nRegards", 'until' => $until])
            ->assertOk()->assertJsonPath('data.autoresponder.enabled', true);
        $sieve = $this->drain($secret)['ceo@example.com.dovecot.sieve'];
        $this->assertStringContainsString('require ["vacation", "date", "relational"];', $sieve);
        $this->assertStringContainsString('if currentdate :value "le" "date" "'.$until.'"', $sieve);
        $this->assertStringContainsString(':subject "Away \"until\" Monday"', $sieve);
        $this->api($token)->patchJson("/api/v1/mail/domains/{$d['id']}/mailboxes/{$box['id']}/autoresponder", ['enabled' => false])->assertOk();
        $this->assertSame("# Auto-reply is off.\n", $this->drain($secret)['ceo@example.com.dovecot.sieve']);

        $dns = app(DnsResolver::class);
        $dns->set('20.100.51.198.in-addr.arpa', 'PTR', ['mail.example.com.']);
        $dns->set('mail.example.com', 'A', ['198.51.100.20']);
        $dns->set('20.100.51.198.zen.spamhaus.org', 'A', ['127.0.0.2']);
        $dns->set('20.100.51.198.bl.spamcop.net', 'A', ['127.255.255.254']);
        $h = $this->api($token)->postJson("/api/v1/mail/servers/{$mail->id}/health")->assertOk()->json('data');
        $this->assertSame([true, true, ['zen.spamhaus.org']], [$h['ptr_ok'], $h['a_ok'], $h['listed']]);
        $this->assertTrue(\App\Models\AuditLog::where('action', 'mail.blocklisted')->exists());

        $this->api($token)->getJson("/api/v1/mail/servers/{$mail->id}/logs")->assertOk()->assertJsonStructure(['data' => ['lines', 'job_id']]);
        $this->assertSame('mailserver', collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'container.logs')['payload']['container_id']);
    }
}
