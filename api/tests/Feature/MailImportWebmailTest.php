<?php

namespace Tests\Feature;

use App\Contracts\DnsResolver;
use App\Models\AgentCommand;
use App\Models\Job;
use App\Models\MailImport;
use App\Models\MailServer;
use App\Services\MailService;
use Tests\TestCase;

/** MCL-10 IMAP import and webmail (UMAIL). */
class MailImportWebmailTest extends TestCase
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

    private function mailbox(string $token, MailServer $mail): array
    {
        $d = $this->api($token)->postJson('/api/v1/mail/domains', ['mail_server_id' => $mail->id, 'name' => 'example.com'])->assertCreated()->json('data');
        $box = $this->api($token)->postJson("/api/v1/mail/domains/{$d['id']}/mailboxes", ['local_part' => 'ann', 'password' => 'correct horse battery'])->assertCreated()->json('data');

        return [$d, $box];
    }

    public function test_an_import_signs_in_as_the_master_user_and_records_the_result(): void
    {
        [$token, $secret, $mail] = $this->setUpMail();
        [$d, $box] = $this->mailbox($token, $mail);
        $writes = $this->drain($secret);

        $this->api($token)->postJson("/api/v1/mail/domains/{$d['id']}/mailboxes/{$box['id']}/import", ['host' => 'not a host', 'username' => 'a', 'password' => 'b'])->assertStatus(422);
        $res = $this->api($token)->postJson("/api/v1/mail/domains/{$d['id']}/mailboxes/{$box['id']}/import", [
            'host' => 'imap.gmail.com', 'username' => 'ann@gmail.com', 'password' => 'app-password-123',
        ])->assertStatus(202)->json('data');
        $this->assertSame('ann@gmail.com @ imap.gmail.com:993', $res['import']['source']);
        $this->api($token)->postJson("/api/v1/mail/domains/{$d['id']}/mailboxes/{$box['id']}/import", ['host' => 'imap.gmail.com', 'username' => 'x', 'password' => 'y'])->assertStatus(409);

        $import = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'mail.import');
        $master = MailServer::allTenants()->find($mail->id)->master_password;
        $this->assertNotEmpty($master);
        $this->assertStringStartsWith('dmpimport|{SHA512-CRYPT}$6$', $writes['dovecot-masters.cf'], 'the master user reaches Dovecot');
        $this->assertStringNotContainsString($master, $writes['dovecot-masters.cf']);
        $this->assertSame('ann@example.com*dmpimport', $import['payload']['dest']['user']);
        $this->assertSame($master, $import['payload']['dest']['password']);
        $this->assertSame(['imap.gmail.com', 993, 'ssl', 'ann@gmail.com', 'app-password-123'], array_values($import['payload']['source']));

        $this->commandResult($secret, $import['id'], 'succeeded', ['output' => ['exit_code' => 0, 'messages' => 1234, 'folders' => 12, 'bytes' => 98765, 'errors' => 0, 'output' => 'ok']])->assertOk();
        $row = MailImport::allTenants()->sole();
        $this->assertSame(['succeeded', 1234, 12], [$row->status, $row->messages, $row->folders]);
        $this->assertSame('', $row->source_password, 'the source password is forgotten once the import ends');
        $this->assertSame('succeeded', Job::allTenants()->find($res['job_id'])->status);

        $list = $this->api($token)->getJson("/api/v1/mail/domains/{$d['id']}/imports")->assertOk()->json('data');
        $this->assertSame(1234, $list['imports'][0]['messages']);
        $this->assertArrayHasKey('gmail', $list['presets']);
        $this->assertArrayNotHasKey('source_password', $list['imports'][0]);
    }

    public function test_a_failed_login_is_reported_plainly(): void
    {
        [$token, $secret, $mail] = $this->setUpMail();
        [$d, $box] = $this->mailbox($token, $mail);
        $this->drain($secret);
        $res = $this->api($token)->postJson("/api/v1/mail/domains/{$d['id']}/mailboxes/{$box['id']}/import", ['host' => 'imap.example.net', 'username' => 'x', 'password' => 'wrong'])->assertStatus(202)->json('data');
        $import = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'mail.import');
        $this->commandResult($secret, $import['id'], 'succeeded', ['output' => ['exit_code' => 16, 'output' => "Host1 failure: Error login on [imap.example.net] with user [x]: AUTHENTICATIONFAILED\n"]])->assertOk();
        $this->assertSame('failed', MailImport::allTenants()->sole()->status);
        $this->assertStringContainsString('Error login', Job::allTenants()->find($res['job_id'])->error);
    }

    public function test_webmail_runs_beside_the_mail_server_and_customers_get_a_link_and_app_settings(): void
    {
        [$token, $secret, $mail] = $this->setUpMail();
        $this->api($token)->postJson("/api/v1/mail/servers/{$mail->id}/webmail", ['hostname' => 'webmail.example.com'])->assertStatus(202)->assertJsonPath('data.webmail_status', 'installing');
        $remove = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'container.remove');
        $this->commandResult($secret, $remove['id'], 'failed', ['message' => 'No such container: webmail'])->assertOk();
        $create = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'container.create');
        $this->assertSame([MailService::WEBMAIL_IMAGE, 'ssl://mail.example.com', 'tls://mail.example.com'], [$create['payload']['image'], $create['payload']['env']['ROUNDCUBEMAIL_DEFAULT_HOST'], $create['payload']['env']['ROUNDCUBEMAIL_SMTP_SERVER']]);
        $this->assertContains('Host(`webmail.example.com`)', $create['payload']['labels']);
        $this->commandResult($secret, $create['id'], 'succeeded', ['output' => ['id' => 'w1']])->assertOk();
        $this->assertSame('running', MailServer::allTenants()->find($mail->id)->webmail_status);
        $this->api($token)->getJson('/api/v1/mail')->assertJsonPath('data.servers.0.webmail_hostname', 'webmail.example.com');

        $settings = MailService::clientSettings($mail->fresh(), 'ann@example.com');
        $this->assertSame('https://webmail.example.com/?_user=ann%40example.com', $settings['webmail_url']);
        $this->assertSame([993, 587], [$settings['imap']['port'], $settings['smtp']['port']]);
    }

    public function test_another_organisation_cannot_import_into_these_mailboxes(): void
    {
        [$token, $secret, $mail] = $this->setUpMail();
        [$d, $box] = $this->mailbox($token, $mail);
        [, , $other] = $this->org();
        $this->api($other)->postJson("/api/v1/mail/domains/{$d['id']}/mailboxes/{$box['id']}/import", ['host' => 'imap.gmail.com', 'username' => 'x', 'password' => 'y'])->assertNotFound();
        $this->api($other)->getJson("/api/v1/mail/domains/{$d['id']}/imports")->assertNotFound();
    }
}
