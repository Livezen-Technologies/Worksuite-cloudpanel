<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Domain;
use App\Models\MailServer;
use App\Models\Threat;
use App\Models\Volume;
use App\Support\Tenant;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** UCRON-1..4, USEC-1..3/5, UMAIL-001/002/004..006: the customer's own pages. */
class CustomerPanelPagesTest extends TestCase
{
    private function customerWithApp(): array
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'shop', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'wordpress:6', 'server_id' => $server->id, 'port' => 80, 'status' => 'running']));

        return [$org, $token, $secret, $app, $server];
    }

    public function test_cron_jobs_run_in_the_app_as_a_non_root_user_and_keep_history(): void
    {
        [$org, $token, $secret, $app] = $this->customerWithApp();
        $this->api($token)->postJson('/api/v1/app/cron', ['name' => 'x', 'action' => 'app_command', 'application_id' => $app->id, 'command' => 'php -v', 'cron' => 'not a cron'])->assertStatus(422);
        $this->api($token)->postJson('/api/v1/app/cron', ['name' => 'x', 'action' => 'app_command', 'application_id' => 'nope', 'command' => 'php -v', 'cron' => '*/5 * * * *'])->assertStatus(422);
        $job = $this->api($token)->postJson('/api/v1/app/cron', ['name' => 'WP cron', 'action' => 'app_command', 'application_id' => $app->id, 'command' => 'wp cron event run --due-now', 'cron' => '*/5 * * * *'])
            ->assertCreated()->assertJsonPath('data.app', 'shop')->json('data');
        $this->assertNotNull($job['next_run_at']);

        $this->api($token)->postJson("/api/v1/app/cron/{$job['id']}/run")->assertStatus(202);
        $c = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'cron.exec');
        $this->assertSame(['app-shop', 'wp cron event run --due-now', '33:33'], [$c['payload']['container'], $c['payload']['command'], $c['payload']['user']]);
        $this->commandResult($secret, $c['id'], 'succeeded', ['output' => ['exit_code' => 2, 'output' => 'Error: no events', 'duration_ms' => 840]])->assertOk();
        $runs = $this->api($token)->getJson("/api/v1/app/cron/{$job['id']}/runs")->assertOk()->json('data');
        $this->assertSame(['failed', 2, 'Error: no events', 'manual'], [$runs[0]['status'], $runs[0]['exit_code'], $runs[0]['output'], $runs[0]['source']]);
        $this->api($token)->getJson('/api/v1/app/cron')->assertJsonPath('data.jobs.0.last_result', 'Exit 2')->assertJsonPath('data.used', 1)->assertJsonPath('data.limit', 10);

        Http::fake(['shop.example.com/*' => Http::response('ok')]);
        $url = $this->api($token)->postJson('/api/v1/app/cron', ['name' => 'Ping', 'action' => 'url', 'url' => 'https://shop.example.com/cron.php', 'cron' => '0 * * * *'])->assertCreated()->json('data');
        $this->api($token)->postJson("/api/v1/app/cron/{$url['id']}/run")->assertStatus(202);
        $this->assertSame('succeeded', $this->api($token)->getJson("/api/v1/app/cron/{$url['id']}/runs")->json('data.0.status'));
        $this->api($token)->patchJson("/api/v1/app/cron/{$url['id']}", ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.next_run_at', null);

        // Package limits (UCRON-4 / CRON-6).
        $sub = app(\App\Services\BillingService::class);
        app(Tenant::class)->as($org->id, function () use ($sub) {
            $plan = $sub->plan();
            $plan->update(['limits' => array_merge($plan->limits ?? [], ['cron_jobs' => 2, 'cron_min_minutes' => 15])]);
        });
        $this->api($token)->postJson('/api/v1/app/cron', ['name' => 'Third', 'action' => 'url', 'url' => 'https://shop.example.com/', 'cron' => '0 * * * *'])->assertStatus(402);
        $this->api($token)->patchJson("/api/v1/app/cron/{$url['id']}", ['cron' => '*/5 * * * *'])->assertStatus(422);

        [, , $other] = $this->org();
        $this->api($other)->postJson("/api/v1/app/cron/{$job['id']}/run")->assertNotFound();
    }

    public function test_security_shows_only_the_customers_own_sites(): void
    {
        [$org, $token, $secret, , $server] = $this->customerWithApp();
        $mine = Volume::allTenants()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'name' => 'app-shop-data', 'type' => 'local']);
        $theirs = Volume::allTenants()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'name' => 'mailserver-data', 'type' => 'local']);
        foreach ([[$mine, 'wp-content/uploads/x.php'], [$theirs, 'secret/y.php']] as [$v, $path]) {
            Threat::allTenants()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'volume_id' => $v->id, 'volume_name' => $v->name, 'path' => $path, 'signature' => 'YARA.PHP.WebShell', 'sha256' => str_repeat('a', 64), 'status' => 'infected', 'detected_at' => now()]);
        }
        $data = $this->api($token)->getJson('/api/v1/app/security')->assertOk()->json('data');
        $this->assertSame([1, 'shop', 'wp-content/uploads/x.php'], [$data['open_threats'], $data['threats'][0]['site'], $data['threats'][0]['path']]);
        $this->assertCount(1, $data['threats']);

        $this->api($token)->postJson('/api/v1/app/security/scan')->assertStatus(202)->assertJsonPath('data.queued', 1);
        $this->assertSame('app-shop-data', collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'av.scan')['payload']['volume']);
        $this->api($token)->postJson('/api/v1/app/security/scan')->assertStatus(409);

        $other = Threat::allTenants()->where('volume_id', $theirs->id)->sole();
        $this->api($token)->postJson("/api/v1/app/security/threats/{$other->id}/quarantine")->assertNotFound();
        $t = $data['threats'][0]['id'];
        $this->api($token)->postJson("/api/v1/app/security/threats/{$t}/delete")->assertNotFound();
        $this->api($token)->postJson("/api/v1/app/security/threats/{$t}/ignore")->assertOk()->assertJsonPath('data.status', 'ignored');
    }

    public function test_customer_mail_on_own_domains_within_the_plan(): void
    {
        [$org, $token, $secret] = $this->customerWithApp();
        [, $admin] = $this->org('super_admin');
        MailServer::allTenants()->create(['organization_id' => $admin->id, 'server_id' => \App\Models\Server::allTenants()->first()->id, 'hostname' => 'mail.platform.test', 'status' => 'running']);

        $this->api($token)->postJson('/api/v1/app/mail/domains', ['name' => 'shop.lk'])->assertStatus(422);
        Domain::allTenants()->create(['organization_id' => $org->id, 'name' => 'shop.lk', 'platform_subdomain' => false, 'dns_status' => 'verified']);
        $d = $this->api($token)->postJson('/api/v1/app/mail/domains', ['name' => 'shop.lk'])->assertCreated()->json('data');
        $box = $this->api($token)->postJson("/api/v1/app/mail/domains/{$d['id']}/mailboxes", ['local_part' => 'info', 'password' => 'correct horse battery'])->assertCreated()->json('data');
        $this->assertSame('info@shop.lk', $box['address']);
        $this->api($token)->patchJson("/api/v1/app/mail/domains/{$d['id']}/mailboxes/{$box['id']}/autoresponder", ['enabled' => true, 'subject' => 'Away', 'body' => 'Back Monday'])->assertOk();
        $this->api($token)->postJson("/api/v1/app/mail/domains/{$d['id']}/aliases", ['local_part' => 'sales', 'destinations' => ['info@shop.lk']])->assertCreated();

        $page = $this->api($token)->getJson('/api/v1/app/mail')->assertOk()->json('data');
        $this->assertSame([true, 1, 5, 'mail.platform.test', 993], [$page['available'], $page['used'], $page['limit'], $page['settings']['imap']['host'], $page['settings']['imap']['port']]);
        $this->assertSame(['Sends your email to this platform', 'not checked'], [$page['domains'][0]['records'][0]['purpose'], $page['domains'][0]['records'][0]['status']]);
        $this->assertArrayNotHasKey('password_hash', $page['domains'][0]['mailboxes'][0]);

        for ($i = 0; $i < 4; $i++) {
            $this->api($token)->postJson("/api/v1/app/mail/domains/{$d['id']}/mailboxes", ['local_part' => "u{$i}", 'password' => 'correct horse battery'])->assertCreated();
        }
        $this->api($token)->postJson("/api/v1/app/mail/domains/{$d['id']}/mailboxes", ['local_part' => 'one-too-many', 'password' => 'correct horse battery'])->assertStatus(402);

        [, , $other] = $this->org();
        $this->api($other)->postJson("/api/v1/app/mail/domains/{$d['id']}/mailboxes", ['local_part' => 'x', 'password' => 'correct horse battery'])->assertNotFound();
        $this->assertSame(0, \App\Models\MailDomain::allTenants()->where('organization_id', '!=', $org->id)->count());
    }
}
