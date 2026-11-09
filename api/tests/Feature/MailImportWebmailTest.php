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
        // Live progress while imapsync runs.
        $this->commandResult($secret, $import['id'], 'running', ['progress' => 25, 'message' => '250 of 1000 messages copied'])->assertOk();
        $live = $this->api($token)->getJson("/api/v1/mail/domains/{$d['id']}/imports")->assertOk()->json('data.imports.0');
        $this->assertSame([25, '250 of 1000 messages copied'], [$live['progress'], $live['progress_message']]);
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
        $create = $this->runWebmailSteps($secret);
        $this->assertSame([MailService::WEBMAIL_IMAGE, 'ssl://mail.example.com', 'tls://mail.example.com'], [$create['payload']['image'], $create['payload']['env']['ROUNDCUBEMAIL_DEFAULT_HOST'], $create['payload']['env']['ROUNDCUBEMAIL_SMTP_SERVER']]);
        $this->assertContains('Host(`webmail.example.com`)', $create['payload']['labels']);
        $this->assertStringContainsString('dmp_sso', $create['payload']['env']['ROUNDCUBEMAIL_PLUGINS']);
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

    /** Answer the webmail install steps in order; returns the container.create command. */
    private function runWebmailSteps(string $secret, array &$writes = []): array
    {
        for ($i = 0; $i < 8; $i++) {
            foreach ($this->heartbeat($secret)['commands'] as $cmd) {
                if ($cmd['type'] === 'fs.write') {
                    $writes[$cmd['payload']['volume'].'/'.$cmd['payload']['path']] = $cmd['payload']['content'];
                }
                $failed = $cmd['type'] === 'container.remove';
                $this->commandResult($secret, $cmd['id'], $failed ? 'failed' : 'succeeded', $failed ? ['message' => 'No such container: webmail'] : ['output' => ['id' => 'w1']])->assertOk();
                if ($cmd['type'] === 'container.create') {
                    return $cmd;
                }
            }
        }
        $this->fail('webmail install never reached container.create');
    }

    public function test_one_click_webmail_sign_in_is_single_use_and_bearer_checked(): void
    {
        [$token, $secret, $mail] = $this->setUpMail();
        [$d, $box] = $this->mailbox($token, $mail);
        $this->api($token)->postJson("/api/v1/mail/domains/{$d['id']}/mailboxes/{$box['id']}/webmail")->assertStatus(409);
        $this->api($token)->postJson("/api/v1/mail/servers/{$mail->id}/webmail", ['hostname' => 'webmail.example.com'])->assertStatus(202);
        $writes = [];
        $create = $this->runWebmailSteps($secret, $writes);
        $plugin = collect($create['payload']['mounts'])->firstWhere('source', 'webmail-sso');
        $this->assertSame(['/var/www/html/plugins/dmp_sso', true], [$plugin['target'], $plugin['read_only']]);
        $this->assertStringContainsString('class dmp_sso extends rcube_plugin', $writes['webmail-sso/dmp_sso.php']);
        $bearer = app(MailService::class)->webmailSecret($mail->fresh());
        $this->assertStringContainsString($bearer, $writes['webmail-config/dmp_sso.inc.php']);
        $this->assertStringContainsString('/api/v1/webmail-sso', $writes['webmail-config/dmp_sso.inc.php']);

        $url = $this->api($token)->postJson("/api/v1/mail/domains/{$d['id']}/mailboxes/{$box['id']}/webmail")->assertOk()->json('data.url');
        $this->assertMatchesRegularExpression('#^https://webmail\.example\.com/\?_task=login&_sso=[A-Za-z0-9]{64}$#', $url);
        $sso = substr($url, strpos($url, '_sso=') + 5);

        $this->getJson("/api/v1/webmail-sso/{$sso}", ['Authorization' => 'Bearer wrong'])->assertNotFound();
        $login = $this->getJson("/api/v1/webmail-sso/{$sso}", ['Authorization' => 'Bearer '.$bearer])->assertOk()->json('data');
        $this->assertSame(['ann@example.com*'.MailService::MASTER_USER, app(MailService::class)->masterPassword($mail->fresh())], [$login['user'], $login['password']]);
        $this->getJson("/api/v1/webmail-sso/{$sso}", ['Authorization' => 'Bearer '.$bearer])->assertNotFound();

        [, , $other] = $this->org();
        $this->api($other)->postJson("/api/v1/mail/domains/{$d['id']}/mailboxes/{$box['id']}/webmail")->assertNotFound();
    }

    public function test_a_backup_mx_on_another_server_queues_mail_for_the_domains(): void
    {
        [$token, $secret, $mail] = $this->setUpMail();
        [$d] = $this->mailbox($token, $mail);
        $org = \App\Models\Organization::find($mail->organization_id);
        $standby = $this->server($org, ['ip' => '198.51.100.30']);
        [$standbySecret] = $this->enrollAgent($standby, $token);
        $this->heartbeat($standbySecret);

        $this->api($token)->postJson("/api/v1/mail/servers/{$mail->id}/backup", ['server_id' => $mail->server_id, 'hostname' => 'mx2.example.com'])->assertStatus(422);
        $this->api($token)->postJson("/api/v1/mail/servers/{$mail->id}/backup", ['server_id' => $standby->id, 'hostname' => 'mx2.example.com'])
            ->assertStatus(202)->assertJsonPath('data.backup_hostname', 'mx2.example.com');
        $cmds = $this->heartbeat($standbySecret)['commands'];
        $this->assertSame(['container.remove', 'container.create'], array_column($cmds, 'type'));
        $spec = $cmds[1]['payload'];
        $this->assertSame([MailService::BACKUP_CONTAINER, MailService::BACKUP_IMAGE, 'mx2.example.com'], [$spec['name'], $spec['image'], $spec['env']['POSTFIX_myhostname']]);
        $this->assertSame('example.com', $spec['env']['POSTFIX_relay_domains']);
        $this->assertSame('inline:{ example.com=smtp:[mail.example.com]:25 }', $spec['env']['POSTFIX_transport_maps']);
        $this->assertSame(['reject_unauth_destination', '5d'], [$spec['env']['POSTFIX_smtpd_relay_restrictions'], $spec['env']['POSTFIX_maximal_queue_lifetime']], 'never an open relay; mail waits up to five days');
        $this->assertSame([['container_port' => 587, 'host_port' => 25]], array_map(fn ($p) => array_intersect_key($p, array_flip(['container_port', 'host_port'])), $spec['ports']));

        // The second MX record, behind the primary.
        $records = collect(app(MailService::class)->records(\App\Models\MailDomain::allTenants()->find($d['id'])))->where('type', 'MX')->values();
        $this->assertSame([['mail.example.com', 10], ['mx2.example.com', 20]], $records->map(fn ($r) => [$r['value'], $r['priority']])->all());

        // A new domain re-renders the relay.
        $this->api($token)->postJson('/api/v1/mail/domains', ['mail_server_id' => $mail->id, 'name' => 'other.test'])->assertCreated();
        $create = collect($this->heartbeat($standbySecret)['commands'])->where('type', 'container.create')->last();
        $this->assertSame('example.com other.test', $create['payload']['env']['POSTFIX_relay_domains']);

        $this->api($token)->postJson("/api/v1/mail/servers/{$mail->id}/backup", ['server_id' => null])->assertOk()->assertJsonPath('data.backup_hostname', null);
        $this->assertSame('container.remove', collect($this->heartbeat($standbySecret)['commands'])->last()['type']);
        $this->assertCount(1, collect(app(MailService::class)->records(\App\Models\MailDomain::allTenants()->find($d['id'])))->where('type', 'MX'));
    }
}
