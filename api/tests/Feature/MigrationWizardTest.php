<?php

namespace Tests\Feature;

use App\Models\AgentCommand;
use App\Models\Application;
use App\Models\Database;
use App\Models\Job;
use App\Models\Secret;
use App\Models\SiteMigration;
use App\Services\MigrationService;
use App\Support\Tenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** SRS MIG-001..011, UMIG: a cPanel backup into apps, cut over by DNS. */
class MigrationWizardTest extends TestCase
{
    private int $siteStatus = 200;

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

    public function test_plesk_and_hestia_backups_keep_their_source_and_virtual_document_roots(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        foreach (['plesk' => 'domains/shop.lk/httpdocs', 'hestia' => 'web/shop.lk/public_html'] as $source => $root) {
            $m = $this->api($token)->postJson('/api/v1/migrations', ['server_id' => $server->id, 'archive_url' => "https://backups.example.com/{$source}.tar"])->assertStatus(202)->json('data');
            $this->step($secret, 'migrate.fetch', ['bytes' => 1024]);
            $this->step($secret, 'migrate.inspect', ['source' => $source, 'user' => 'shop', 'files' => 3, 'bytes' => 1024, 'databases' => ['wpdb'],
                'sites' => [['domain' => 'shop.lk', 'kind' => 'main', 'docroot' => $root, 'wordpress' => true]]]);
            $show = $this->api($token)->getJson("/api/v1/migrations/{$m['id']}")->assertOk()->json('data');
            $this->assertSame([$source, 'ready', $root, 'wpdb'], [$show['source'], $show['status'], $show['sites'][0]['docroot'], $show['sites'][0]['database']]);
        }
        // An unknown source from an agent is not stored.
        $m = $this->api($token)->postJson('/api/v1/migrations', ['server_id' => $server->id, 'archive_url' => 'https://backups.example.com/x.tar'])->assertStatus(202)->json('data');
        $this->step($secret, 'migrate.fetch', ['bytes' => 1]);
        $this->step($secret, 'migrate.inspect', ['source' => '<script>', 'sites' => [], 'databases' => []]);
        $this->assertSame('cpanel', SiteMigration::allTenants()->find($m['id'])->source);
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
    /** A migration with one copied site, its app on an ingress server, and a Cloudflare zone holding the old A record. */
    private function migratedSite(array &$remote): array
    {
        $next = 1;
        Http::fake(function (Request $request) use (&$remote, &$next) {
            if (str_contains($request->url(), 'shoplk.')) {
                return Http::response('<html>ok</html>', $this->siteStatus);
            }
            $path = substr(parse_url($request->url(), PHP_URL_PATH), strlen('/client/v4'));
            $ok = fn ($r, $extra = []) => Http::response(['success' => true, 'errors' => [], 'result' => $r] + $extra);

            return match (true) {
                $path === '/user/tokens/verify' => $ok(['id' => 'abcdef1234567890', 'status' => 'active']),
                $path === '/zones' && $request->method() === 'GET' => $ok([]),
                $path === '/zones' => $ok(['id' => 'z1', 'status' => 'active', 'name_servers' => ['ada.ns.cloudflare.com']]),
                $path === '/zones/z1/dns_records' && $request->method() === 'GET' => $ok(array_values($remote), ['result_info' => ['total_pages' => 1]]),
                $path === '/zones/z1/dns_records' => $ok($remote[$id = 'r'.$next++] = ['id' => $id] + $request->data()),
                str_starts_with($path, '/zones/z1/dns_records/') && $request->method() === 'DELETE' => (function () use (&$remote, $path, $ok) { unset($remote[basename($path)]); return $ok(['id' => basename($path)]); })(),
                default => Http::response(['success' => false, 'errors' => [['message' => 'not found']]], 404),
            };
        });
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['ip' => '198.51.100.9', 'tags' => ['ingress']]);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $provider = $this->api($token)->postJson('/api/v1/dns/providers', ['name' => 'CF', 'type' => 'cloudflare', 'token' => 'zone-token'])->assertCreated()->json('data');
        $zone = $this->api($token)->postJson('/api/v1/dns/zones', ['dns_provider_id' => $provider['id'], 'name' => 'shop.lk'])->assertCreated()->json('data');
        $this->api($token)->postJson("/api/v1/dns/zones/{$zone['id']}/records", ['type' => 'A', 'name' => 'shop.lk', 'content' => '203.0.113.50', 'ttl' => 300])->assertCreated();
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'shoplk', 'kind' => 'admin', 'source_type' => 'image', 'image' => 'wordpress:6', 'server_id' => $server->id, 'port' => 80, 'volume_path' => '/var/www/html']));
        $m = SiteMigration::allTenants()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'source' => 'cpanel', 'archive_url' => 'https://b.example.com/a.tar.gz', 'status' => 'migrated',
            'sites' => [['domain' => 'shop.lk', 'kind' => 'main', 'docroot' => 'public_html', 'wordpress' => true, 'enabled' => true, 'app_name' => 'shoplk', 'database' => null, 'target_database_id' => null,
                'status' => 'migrated', 'app_id' => $app->id, 'temp_url' => 'https://shoplk.'.config('platform.domain')]]]);

        return [$token, $secret, $m, $app];
    }

    public function test_cutover_replaces_the_dns_records_and_rollback_restores_them(): void
    {
        $remote = [];
        [$token, $secret, $m, $app] = $this->migratedSite($remote);
        $this->assertSame(['203.0.113.50'], collect($remote)->where('name', 'shop.lk')->where('type', 'A')->pluck('content')->values()->all());

        $site = $this->api($token)->postJson("/api/v1/migrations/{$m->id}/sites/0/cutover")->assertOk()->json('data.sites.0');
        $this->assertSame('live', $site['status']);
        $this->assertSame([['type' => 'A', 'content' => '203.0.113.50', 'ttl' => 300, 'proxied' => false]], $site['previous_records']);
        $this->assertSame(['198.51.100.9'], collect($remote)->where('name', 'shop.lk')->where('type', 'A')->pluck('content')->values()->all());
        $this->assertTrue(\App\Models\Domain::allTenants()->where('name', 'shop.lk')->where('application_id', $app->id)->exists());
        $this->api($token)->postJson("/api/v1/migrations/{$m->id}/sites/0/cutover")->assertStatus(409);

        $site = $this->api($token)->postJson("/api/v1/migrations/{$m->id}/sites/0/rollback")->assertOk()->json('data.sites.0');
        $this->assertSame('migrated', $site['status']);
        $this->assertSame(['203.0.113.50'], collect($remote)->where('name', 'shop.lk')->where('type', 'A')->pluck('content')->values()->all());
        $this->assertFalse(\App\Models\Domain::allTenants()->where('name', 'shop.lk')->exists());
        $this->assertNotNull(collect(SiteMigration::allTenants()->find($m->id)->timeline)->first(fn ($e) => str_contains($e['event'], 'Rolled back')));
    }

    public function test_cutover_waits_for_the_new_site_to_answer(): void
    {
        $remote = [];
        [$token, , $m] = $this->migratedSite($remote);
        $this->siteStatus = 500;
        $this->api($token)->postJson("/api/v1/migrations/{$m->id}/sites/0/cutover")->assertStatus(409)->assertJsonPath('error.message', fn ($msg) => str_contains($msg, 'HTTP 500'));
        $this->assertSame('migrated', SiteMigration::allTenants()->find($m->id)->sites[0]['status']);
    }

    public function test_delta_sync_fetches_a_newer_backup_and_copies_again(): void
    {
        $remote = [];
        [$token, $secret, $m] = $this->migratedSite($remote);
        $this->api($token)->postJson("/api/v1/migrations/{$m->id}/sites/0/cutover")->assertOk();
        $this->api($token)->postJson("/api/v1/migrations/{$m->id}/resync", ['archive_url' => 'https://b.example.com/newer.tar.gz'])->assertStatus(202);
        $this->step($secret, 'migrate.cleanup');
        $this->assertSame('https://b.example.com/newer.tar.gz', $this->step($secret, 'migrate.fetch', ['bytes' => 10])['payload']['url']);
        $this->step($secret, 'migrate.extract', ['files' => 3, 'resumed' => 900]);
        $m = SiteMigration::allTenants()->find($m->id);
        $this->assertSame('live', $m->sites[0]['status'], 'a live site stays live after a delta sync');
        $this->assertNotNull(collect($m->timeline)->firstWhere('event', 'Copied 3 files (900 already there)'));
    }

    public function test_bulk_and_customer_requests(): void
    {
        [$admin, $org, $token] = $this->org('super_admin');
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $this->api($token)->postJson('/api/v1/migrations/bulk', ['server_id' => $server->id, 'archives' => [['url' => 'https://b.example.com/a.tar.gz'], ['url' => 'https://b.example.com/b.tar.gz']]])
            ->assertStatus(202)->assertJsonPath('data.queued', 2);
        $this->assertCount(2, collect($this->heartbeat($secret)['commands'])->where('type', 'migrate.fetch'));

        [, $customer, $ctoken] = $this->org();
        $req = $this->api($ctoken)->postJson('/api/v1/app/migrations', ['archive_url' => 'https://b.example.com/mine.tar.gz', 'notes' => 'My shop'])->assertCreated()
            ->assertJsonPath('data.status', 'requested')->json('data');
        $this->assertArrayNotHasKey('archive_url', $req);
        $this->assertSame(0, AgentCommand::allTenants()->where('payload', 'like', '%mine.tar.gz%')->count(), 'nothing runs before approval');
        $this->api($token)->postJson("/api/v1/migrations/{$req['id']}/approve")->assertStatus(422);
        $this->api($token)->postJson("/api/v1/migrations/{$req['id']}/approve", ['server_id' => $server->id])->assertStatus(202)->assertJsonPath('data.status', 'fetching');
        $this->assertSame('https://b.example.com/mine.tar.gz', collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'migrate.fetch')['payload']['url']);
        $this->api($token)->postJson("/api/v1/migrations/{$req['id']}/reject")->assertStatus(409);
        $this->api($ctoken)->getJson('/api/v1/app/migrations')->assertOk()->assertJsonPath('data.0.status', 'fetching');

        $other = $this->api($ctoken)->postJson('/api/v1/app/migrations', ['archive_url' => 'https://b.example.com/x.tar.gz'])->json('data');
        $this->api($token)->postJson("/api/v1/migrations/{$other['id']}/reject", ['reason' => 'Backup is incomplete'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame('Backup is incomplete', collect($this->api($ctoken)->getJson('/api/v1/app/migrations')->json('data'))->firstWhere('id', $other['id'])['decision']);
    }
}
