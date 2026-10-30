<?php

namespace Tests\Feature;

use App\Models\AgentCommand;
use App\Models\Application;
use App\Models\Database;
use App\Models\Job;
use App\Models\Secret;
use App\Models\SiteMigration;
use App\Services\MigrationService;
use Tests\TestCase;

/** SRS MIG-001..006: a cPanel backup into apps on a temporary hostname. */
class MigrationWizardTest extends TestCase
{
    private function step(string $secret, string $type, array $output = []): array
    {
        $c = collect($this->heartbeat($secret)['commands'])->firstWhere('type', $type);
        $this->assertNotNull($c, "expected {$type}");
        $this->commandResult($secret, $c['id'], 'succeeded', ['output' => $output])->assertOk();

        return $c;
    }

    public function test_a_cpanel_account_is_inspected_mapped_and_copied(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);

        $m = $this->api($token)->postJson('/api/v1/migrations', ['server_id' => $server->id, 'archive_url' => 'https://backups.example.com/cpmove-shop.tar.gz'])
            ->assertStatus(202)->assertJsonPath('data.status', 'fetching')->json('data');
        $this->assertArrayNotHasKey('archive_url', $m);
        $fetch = $this->step($secret, 'migrate.fetch', ['bytes' => 5242880, 'sha256' => str_repeat('a', 64)]);
        $this->assertSame([$m['id'], 'https://backups.example.com/cpmove-shop.tar.gz'], [$fetch['payload']['id'], $fetch['payload']['url']]);
        $this->step($secret, 'migrate.inspect', [
            'user' => 'shop', 'files' => 1200, 'bytes' => 5242880, 'databases' => ['shop_wp'],
            'sites' => [
                ['domain' => 'shop.lk', 'kind' => 'main', 'docroot' => 'public_html', 'wordpress' => true],
                ['domain' => 'blog.shop.lk', 'kind' => 'subdomain', 'docroot' => 'blog', 'wordpress' => false],
            ],
        ]);
        $show = $this->api($token)->getJson("/api/v1/migrations/{$m['id']}")->assertOk()->json('data');
        $this->assertSame('ready', $show['status']);
        $this->assertSame(['shoplk', true, 'shop_wp'], [$show['sites'][0]['app_name'], $show['sites'][0]['enabled'], $show['sites'][0]['database']]);
        $this->assertFalse($show['sites'][1]['enabled'], 'subdomains are off by default');
        $this->assertContains('Choose a MySQL or MariaDB database on the target server to import into.', $show['preflight'][0]['issues']);
        $this->api($token)->postJson("/api/v1/migrations/{$m['id']}/start")->assertStatus(409);

        $pw = Secret::create(['name' => 'db-password', 'value' => 'r00t-pw']);
        $db = Database::create(['server_id' => $server->id, 'name' => 'main', 'engine' => 'mariadb', 'version' => '11', 'secret_id' => $pw->id, 'status' => 'running']);
        $this->api($token)->patchJson("/api/v1/migrations/{$m['id']}/sites", ['sites' => [['domain' => 'shop.lk', 'app_name' => 'Bad Name']]])->assertStatus(422);
        $show = $this->api($token)->patchJson("/api/v1/migrations/{$m['id']}/sites", ['sites' => [['domain' => 'shop.lk', 'app_name' => 'shoplk', 'target_database_id' => $db->id]]])->assertOk()->json('data');
        $this->assertTrue($show['preflight'][0]['ok']);

        $this->api($token)->postJson("/api/v1/migrations/{$m['id']}/start")->assertStatus(202)->assertJsonPath('data.status', 'migrating')
            ->assertJsonPath('data.sites.0.temp_url', 'https://shoplk.'.config('platform.domain'));
        $app = Application::where('name', 'shoplk')->sole();
        $this->assertSame(['wordpress:6', $server->id], [$app->image, $app->server_id]);
        $this->assertSame('db-main', \App\Models\EnvVar::where('application_id', $app->id)->where('key', 'WORDPRESS_DB_HOST')->value('value'));

        // The deployment finished: the site is copied in.
        AgentCommand::allTenants()->update(['status' => 'succeeded']);
        app(MigrationService::class)->afterDeployment($app);
        $x = $this->step($secret, 'migrate.extract', ['files' => 1100, 'resumed' => 0]);
        $this->assertSame(['public_html', 'app-shoplk-data'], [$x['payload']['from'], $x['payload']['volume']]);
        $imp = $this->step($secret, 'migrate.import_db', ['database' => 'shoplk']);
        $this->assertSame(['shop_wp', 'db-main', 'shoplk', 'r00t-pw'], [$imp['payload']['sql'], $imp['payload']['db']['container'], $imp['payload']['db']['database'], $imp['payload']['db']['password']]);
        foreach (['DB_HOST' => 'db-main', 'DB_NAME' => 'shoplk', 'DB_USER' => 'root', 'DB_PASSWORD' => 'r00t-pw'] as $k => $v) {
            $this->assertSame(['config', 'set', $k, $v], $this->step($secret, 'wp.cli')['payload']['args']);
        }
        $done = SiteMigration::allTenants()->sole();
        $this->assertSame(['migrated', 'migrated'], [$done->status, $done->sites[0]['status']]);
        $this->assertStringNotContainsString('r00t-pw', json_encode(Job::allTenants()->get()->pluck('payload')), 'no job keeps the password');
        $this->assertNotEmpty(collect($done->timeline)->firstWhere('event', 'Imported database shoplk'));

        $this->api($token)->getJson('/api/v1/migrations')->assertOk()->assertJsonPath('data.0.migrated', 1);
        [, , $other] = $this->org();
        $this->api($other)->getJson("/api/v1/migrations/{$m['id']}")->assertNotFound();
    }

    public function test_a_failed_copy_is_reported_and_can_be_retried(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $m = $this->api($token)->postJson('/api/v1/migrations', ['server_id' => $server->id, 'archive_url' => 'https://x.example.com/a.tar.gz'])->json('data');
        $c = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'migrate.fetch');
        $this->commandResult($secret, $c['id'], 'failed', ['message' => 'download failed: HTTP 404'])->assertOk();
        $this->api($token)->getJson("/api/v1/migrations/{$m['id']}")->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error', 'download failed: HTTP 404');
    }
}
