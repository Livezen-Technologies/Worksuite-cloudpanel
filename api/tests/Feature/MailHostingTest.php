<?php

namespace Tests\Feature;

use App\Contracts\DnsResolver;
use App\Models\AgentCommand;
use App\Models\Job;
use App\Models\MailServer;
use App\Services\MailService;
use Tests\TestCase;

/** SRS MAIL-001..005: a mail stack per server, domains with DKIM, mailboxes, aliases, DNS. */
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
}
