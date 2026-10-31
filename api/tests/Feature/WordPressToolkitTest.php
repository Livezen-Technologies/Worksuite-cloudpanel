<?php

namespace Tests\Feature;

use App\Models\AgentCommand;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Job;
use App\Models\WpRestorePoint;
use App\Models\WpSite;
use App\Services\WordPressService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** SRS WP-001..012: the WordPress toolkit through WP-CLI on the agent. */
class WordPressToolkitTest extends TestCase
{
    private function setUpSite(): array
    {
        Http::fake([
            'www.wpvulnerability.net/core/*' => Http::response(['error' => 0, 'data' => ['vulnerability' => []]]),
            'www.wpvulnerability.net/plugin/contact-form-7/' => Http::response(['error' => 0, 'data' => ['vulnerability' => [
                ['name' => 'CF7 < 5.9.5 - Reflected XSS', 'operator' => ['max_version' => '5.9.5', 'max_operator' => 'lt']],
                ['name' => 'Old issue', 'operator' => ['max_version' => '4.0', 'max_operator' => 'lt']],
            ]]]),
            'www.wpvulnerability.net/*' => Http::response(['error' => 0, 'data' => ['vulnerability' => []]]),
        ]);
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $app = Application::allTenants()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'name' => 'blog', 'source_type' => 'image', 'image' => 'wordpress:6', 'volume_path' => '/var/www/html', 'status' => 'running']);
        Application::allTenants()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'name' => 'api', 'source_type' => 'image', 'image' => 'node:22', 'status' => 'running']);

        $this->api($token)->postJson('/api/v1/wordpress/detect')->assertStatus(202)->assertJsonPath('data.queued', 1);
        $c = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'wp.inventory');
        $this->assertSame('app-blog', $c['payload']['container']);
        $this->commandResult($secret, $c['id'], 'succeeded', ['output' => [
            'version' => '6.8.3', 'cli' => ['php_version' => '8.0.30'], 'siteurl' => 'https://blog.example.com', 'blog_public' => '1', 'wp_debug' => 'false', 'maintenance' => false,
            'plugins' => [['name' => 'contact-form-7', 'status' => 'active', 'version' => '5.9.3', 'update' => 'available', 'update_version' => '5.9.8'], ['name' => 'akismet', 'status' => 'inactive', 'version' => '5.3', 'update' => 'none']],
            'themes' => [['name' => 'twentytwentyfour', 'status' => 'active', 'version' => '1.2', 'update' => 'none']],
            'core_updates' => [],
        ]])->assertOk();

        return [$org, $token, $secret, WpSite::allTenants()->sole()];
    }

    private function step(string $secret, string $type, array $output = ['output' => 'Success']): array
    {
        $c = collect($this->heartbeat($secret)['commands'])->firstWhere('type', $type);
        $this->assertNotNull($c, "expected {$type}");
        $this->commandResult($secret, $c['id'], 'succeeded', ['output' => $output])->assertOk();

        return $c;
    }

    public function test_waiting_updates_email_the_organisation_once_per_change(): void
    {
        $sent = [];
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSent::class, function ($e) use (&$sent) {
            $sent[] = $e->message->getSubject();
        });
        [, $token, $secret, $site] = $this->setUpSite();
        $this->assertSame(['1 WordPress updates for blog.example.com'], array_values(array_filter($sent, fn ($s) => str_contains($s, 'WordPress updates'))));

        $inv = ['version' => '6.8.3', 'siteurl' => 'https://blog.example.com',
            'plugins' => [['name' => 'contact-form-7', 'status' => 'active', 'version' => '5.9.3', 'update' => 'available', 'update_version' => '5.9.8']], 'themes' => [], 'core_updates' => []];
        $this->api($token)->postJson("/api/v1/wordpress/{$site->id}/refresh")->assertStatus(202);
        $this->step($secret, 'wp.inventory', $inv);
        $this->assertCount(1, array_filter($sent, fn ($s) => str_contains($s, 'WordPress updates')), 'the same count is not emailed again');

        $inv['core_updates'] = [['version' => '6.9']];
        $this->api($token)->postJson("/api/v1/wordpress/{$site->id}/refresh")->assertStatus(202);
        $this->step($secret, 'wp.inventory', $inv);
        $this->assertContains('2 WordPress updates for blog.example.com', $sent);
    }

    public function test_sites_are_detected_inventoried_and_scanned(): void
    {
        [, $token, , $site] = $this->setUpSite();
        $data = $this->api($token)->getJson('/api/v1/wordpress')->assertOk()->json('data');
        $this->assertSame(['sites' => 1, 'updates' => 1, 'security' => 1, 'outdated_php' => 1, 'staging' => 0], $data['summary']);
        $row = $data['sites'][0];
        $this->assertSame(['https://blog.example.com', '6.8.3', '8.0.30', 1, 1], [$row['url'], $row['version'], $row['php_version'], $row['updates'], $row['security']]);
        $this->assertSame(100 - 25 - 5 - 10, $row['score']);
        $this->assertTrue($row['settings']['indexing']);
        $vulns = $this->api($token)->getJson("/api/v1/wordpress/{$site->id}")->json('data.vulnerabilities');
        $this->assertSame([['type' => 'plugin', 'slug' => 'contact-form-7', 'version' => '5.9.3', 'name' => 'CF7 < 5.9.5 - Reflected XSS', 'fixed_in' => '5.9.5']], $vulns);
    }

    public function test_settings_and_tools_run_wp_cli_in_order(): void
    {
        [, $token, $secret, $site] = $this->setUpSite();
        $this->api($token)->patchJson("/api/v1/wordpress/{$site->id}/settings", ['setting' => 'maintenance', 'on' => true])->assertStatus(202);
        $c = $this->step($secret, 'wp.cli');
        $this->assertSame(['maintenance-mode', 'activate'], $c['payload']['args']);
        $this->assertTrue($site->fresh()->settings['maintenance']);

        config(['platform.wordpress.redis_host' => 'redis:6379']);
        $job = $this->api($token)->patchJson("/api/v1/wordpress/{$site->id}/settings", ['setting' => 'redis', 'on' => true])->assertStatus(202)->json('data.job_id');
        $this->assertSame(['config', 'set', 'WP_REDIS_HOST', 'redis:6379'], $this->step($secret, 'wp.cli')['payload']['args']);
        $this->assertSame(['plugin', 'install', 'redis-cache', '--activate'], $this->step($secret, 'wp.cli')['payload']['args']);
        $this->assertSame(['redis', 'enable'], $this->step($secret, 'wp.cli')['payload']['args']);
        $this->assertSame('succeeded', Job::allTenants()->find($job)->status);

        $this->api($token)->postJson("/api/v1/wordpress/{$site->id}/tools/search_replace", ['search' => 'http://old.test', 'replace' => 'https://blog.example.com'])->assertStatus(202);
        $this->assertContains('--dry-run', $this->step($secret, 'wp.cli')['payload']['args'], 'search-and-replace is a dry run unless asked otherwise');
        $this->api($token)->postJson("/api/v1/wordpress/{$site->id}/tools/plugin_deactivate", ['plugin' => '../../evil'])->assertStatus(422);
        $this->api($token)->postJson("/api/v1/wordpress/{$site->id}/tools/plugin_deactivate", ['plugin' => 'akismet'])->assertStatus(202);
        $this->assertSame(['plugin', 'deactivate', 'akismet'], $this->step($secret, 'wp.cli')['payload']['args']);
        $this->step($secret, 'wp.inventory', ['version' => '6.8.3', 'siteurl' => 'https://blog.example.com']); // refreshed afterwards
        $this->api($token)->postJson("/api/v1/wordpress/{$site->id}/tools/explode")->assertNotFound();
    }

    public function test_reset_password_and_login_link_keep_secrets_out_of_jobs_and_logs(): void
    {
        [, $token, $secret, $site] = $this->setUpSite();
        $res = $this->api($token)->postJson("/api/v1/wordpress/{$site->id}/reset-password", ['user' => 'admin'])->assertStatus(202)->json('data');
        $this->assertSame(20, strlen($res['password']));
        $this->assertStringNotContainsString($res['password'], json_encode(Job::allTenants()->find($res['job_id'])->payload));
        $this->assertSame('--user_pass='.$res['password'], AgentCommand::allTenants()->where('job_id', $res['job_id'])->sole()->payload['args'][3]);
        $this->assertStringNotContainsString($res['password'], json_encode(AuditLog::all()->pluck('meta')));

        $link = $this->api($token)->postJson("/api/v1/wordpress/{$site->id}/login-link")->assertStatus(202)->assertJsonPath('data.expires_in', 60)->json('data');
        $this->assertMatchesRegularExpression('#^https://blog\.example\.com/\?ws_login=[A-Za-z0-9]{48}$#', $link['url']);
        $write = $this->step($secret, 'fs.write');
        $this->assertSame(['app-blog-data', 'wp-content/mu-plugins/ws-login.php'], [$write['payload']['volume'], $write['payload']['path']]);
        $this->assertStringContainsString('hash_equals', $write['payload']['content']);
        $opt = $this->step($secret, 'wp.cli');
        [$hash, $expires] = explode(':', $opt['payload']['args'][3]);
        $this->assertSame(hash('sha256', substr($link['url'], -48)), $hash);
        $this->assertEqualsWithDelta(time() + 60, (int) $expires, 5);
        $this->api($token)->postJson("/api/v1/wordpress/{$site->id}/reset-password", ['user' => 'admin; rm -rf'])->assertStatus(422);
    }

    public function test_version_ranges_and_tenant_isolation(): void
    {
        $this->assertTrue(WordPressService::affects('5.9.3', ['max_version' => '5.9.5', 'max_operator' => 'lt']));
        $this->assertFalse(WordPressService::affects('5.9.5', ['max_version' => '5.9.5', 'max_operator' => 'lt']));
        $this->assertTrue(WordPressService::affects('2.0', ['min_version' => '1.0', 'max_version' => '2.0', 'max_operator' => 'le']));
        $this->assertFalse(WordPressService::affects('0.9', ['min_version' => '1.0', 'max_version' => '2.0']));
        [, , , $site] = $this->setUpSite();
        [, , $other] = $this->org();
        $this->api($other)->getJson("/api/v1/wordpress/{$site->id}")->assertNotFound();
        $this->api($other)->postJson("/api/v1/wordpress/{$site->id}/login-link")->assertNotFound();
    }
    /** Walks the restore-point steps of a job: folder, guards, dump. */
    private function restorePointTaken(string $secret): string
    {
        $this->assertTrue($this->step($secret, 'fs.mkdir')['payload']['exist_ok']);
        $this->step($secret, 'fs.write');
        $this->step($secret, 'fs.write');
        $dump = $this->step($secret, 'wp.cli')['payload']['args'];
        $this->assertSame(['db', 'export'], array_slice($dump, 0, 2));

        return $dump[2];
    }

    public function test_update_takes_a_restore_point_and_checks_the_site(): void
    {
        [, $token, $secret, $site] = $this->setUpSite();
        Http::fake(['blog.example.com*' => Http::response('<html>ok</html>')]);
        $job = $this->api($token)->postJson("/api/v1/wordpress/{$site->id}/update", ['scope' => 'plugins'])->assertStatus(202)->json('data.job_id');
        $point = WpRestorePoint::allTenants()->sole();
        $this->assertSame('wp-content/ws-restore/'.$point->id.'.sql', $this->restorePointTaken($secret));
        $this->assertSame('ready', $point->fresh()->status);
        $this->assertSame(['plugin', 'update', 'contact-form-7'], $this->step($secret, 'wp.cli')['payload']['args'], 'only plugins with an update');
        $this->step($secret, 'wp.inventory', ['version' => '6.8.3', 'siteurl' => 'https://blog.example.com']);
        $this->assertSame('succeeded', Job::allTenants()->find($job)->status);
        $this->api($token)->getJson("/api/v1/wordpress/{$site->id}/restore-points")->assertOk()->assertJsonPath('data.0.status', 'ready')->assertJsonPath('data.0.reason', 'update');
        $this->api($token)->postJson("/api/v1/wordpress/{$site->id}/update", ['scope' => 'core'])->assertStatus(409);
    }

    public function test_a_failed_health_check_rolls_the_update_back(): void
    {
        [, $token, $secret, $site] = $this->setUpSite();
        Http::fake(['blog.example.com*' => Http::response('There has been a critical error on this website.', 500)]);
        $job = $this->api($token)->postJson("/api/v1/wordpress/{$site->id}/update", ['scope' => 'all'])->assertStatus(202)->json('data.job_id');
        $dump = $this->restorePointTaken($secret);
        $this->step($secret, 'wp.cli');
        $this->assertSame(['db', 'import', $dump], $this->step($secret, 'wp.cli')['payload']['args']);
        $this->assertSame(['plugin', 'install', 'contact-form-7', '--version=5.9.3', '--force'], $this->step($secret, 'wp.cli')['payload']['args']);
        $this->step($secret, 'wp.inventory', ['version' => '6.8.3', 'siteurl' => 'https://blog.example.com']);
        $job = Job::allTenants()->find($job);
        $this->assertSame('failed', $job->status);
        $this->assertSame('restored', WpRestorePoint::allTenants()->sole()->status);
    }

    public function test_a_failed_update_command_also_rolls_back_and_manual_restore_works(): void
    {
        [, $token, $secret, $site] = $this->setUpSite();
        $this->api($token)->postJson("/api/v1/wordpress/{$site->id}/update", ['scope' => 'plugins'])->assertStatus(202);
        $this->restorePointTaken($secret);
        $c = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'wp.cli');
        $this->commandResult($secret, $c['id'], 'failed', ['message' => 'download failed'])->assertOk();
        $this->assertSame('db', $this->step($secret, 'wp.cli')['payload']['args'][0]);

        // A manual restore reinstalls only what differs from the point.
        $point = WpRestorePoint::allTenants()->sole();
        $site->update(['plugins' => [['name' => 'contact-form-7', 'version' => '5.9.8'], ['name' => 'akismet', 'version' => '5.3']]]);
        foreach (collect($this->heartbeat($secret)['commands']) as $c) {
            $this->commandResult($secret, $c['id'], 'succeeded', ['output' => ['output' => 'ok']]);
        }
        $this->api($token)->postJson("/api/v1/wordpress/{$site->id}/restore-points/{$point->id}/restore")->assertStatus(202);
        $this->step($secret, 'wp.cli');
        $this->assertSame(['plugin', 'install', 'contact-form-7', '--version=5.9.3', '--force'], $this->step($secret, 'wp.cli')['payload']['args']);
    }

    public function test_update_all_and_restore_point_retention(): void
    {
        [, $token, $secret, $site] = $this->setUpSite();
        $this->api($token)->postJson('/api/v1/wordpress/update-all', ['scope' => 'plugins'])->assertStatus(202)->assertJsonPath('data.queued', 1);
        for ($i = 0; $i < WordPressService::KEEP_RESTORE_POINTS; $i++) {
            WpRestorePoint::allTenants()->create(['organization_id' => $site->organization_id, 'wp_site_id' => $site->id, 'reason' => 'manual', 'status' => 'ready', 'created_at' => now()->subDays(10 + $i)]);
        }
        $oldest = WpRestorePoint::allTenants()->orderBy('created_at')->first();
        $this->api($token)->postJson("/api/v1/wordpress/{$site->id}/restore-points")->assertStatus(202);
        $job = Job::allTenants()->where('payload->action', 'restore_point')->sole();
        $deletes = collect($job->payload['steps'])->where(0, 'fs.delete')->pluck('1.path')->all();
        $this->assertContains($oldest->dump(), $deletes);
        $this->assertCount(2, $deletes, 'six earlier points: four are kept beside the new one');
    }

    public function test_hardening_writes_one_mu_plugin(): void
    {
        [, $token, $secret, $site] = $this->setUpSite();
        $this->api($token)->patchJson("/api/v1/wordpress/{$site->id}/hardening", ['xmlrpc' => true, 'file_edit' => true])->assertStatus(202);
        $this->step($secret, 'fs.mkdir');
        $write = $this->step($secret, 'fs.write');
        $this->assertSame('wp-content/mu-plugins/ws-hardening.php', $write['payload']['path']);
        $this->assertStringContainsString("'xmlrpc' => true", $write['payload']['content']);
        $this->assertStringContainsString("'user_enum' => false", $write['payload']['content']);
        $this->assertSame(['config', 'set', 'DISALLOW_FILE_EDIT', 'true', '--raw'], $this->step($secret, 'wp.cli')['payload']['args']);
        $this->api($token)->getJson('/api/v1/wordpress')->assertJsonPath('data.sites.0.hardening.xmlrpc', true)->assertJsonPath('data.sites.0.hardening.limit_logins', false);
        $this->assertArrayNotHasKey('hardening', $this->api($token)->getJson('/api/v1/wordpress')->json('data.sites.0.settings'));

        $this->api($token)->postJson("/api/v1/wordpress/{$site->id}/tools/rotate_salts")->assertStatus(202);
        $this->assertSame(['config', 'shuffle-salts'], $this->step($secret, 'wp.cli')['payload']['args']);
        $this->assertSame(0, (new \Symfony\Component\Process\Process(['php', '-l']))->setInput(WordPressService::hardeningPlugin(array_fill_keys(WordPressService::HARDENING, true)))->run());
    }
    public function test_staging_is_created_filled_pushed_and_deleted(): void
    {
        [$org, $token, $secret, $site] = $this->setUpSite();
        $app = Application::allTenants()->find($site->application_id);
        $this->api($token)->postJson("/api/v1/wordpress/{$site->id}/staging")->assertStatus(409); // no WORDPRESS_DB_HOST yet
        \App\Models\EnvVar::allTenants()->create(['organization_id' => $org->id, 'application_id' => $app->id, 'key' => 'WORDPRESS_DB_HOST', 'value' => 'db-blog', 'secret' => false]);
        \App\Models\Domain::allTenants()->create(['organization_id' => $org->id, 'name' => 'www.blog.example.com', 'application_id' => $app->id, 'platform_subdomain' => false, 'dns_status' => 'verified']);

        $res = $this->api($token)->postJson("/api/v1/wordpress/{$site->id}/staging")->assertStatus(202)->json('data');
        $this->assertSame('https://staging.blog.example.com', $res['url']);
        $this->assertSame(16, strlen($res['password']));
        $stagingApp = Application::allTenants()->where('name', 'blog-staging')->sole();
        $this->assertSame(['staging', $app->server_id, 'wordpress:6'], [$stagingApp->environment, $stagingApp->server_id, $stagingApp->image]);
        $env = \App\Models\EnvVar::allTenants()->where('application_id', $stagingApp->id)->get()->pluck('value', 'key');
        $this->assertSame(['db-blog', 'stg_'], [$env['WORDPRESS_DB_HOST'], $env['WORDPRESS_TABLE_PREFIX']]);
        $this->assertStringContainsString(hash('sha256', $res['password']), $env['WORDPRESS_CONFIG_EXTRA']);
        $this->assertStringNotContainsString($res['password'], $env['WORDPRESS_CONFIG_EXTRA']);
        $this->assertTrue(\App\Models\Domain::allTenants()->where('name', 'staging.blog.example.com')->where('application_id', $stagingApp->id)->exists());
        $this->api($token)->postJson("/api/v1/wordpress/{$site->id}/staging")->assertStatus(409);
        $staging = WpSite::allTenants()->where('production_id', $site->id)->sole();
        $this->assertSame('provisioning', $staging->staging_status);

        // The deployment finished: the live site is copied in.
        AgentCommand::allTenants()->update(['status' => 'succeeded']);
        app(WordPressService::class)->afterDeployment($stagingApp);
        $copy = $this->step($secret, 'wp.copy_tables', ['tables' => 12]);
        $this->assertSame(['app-blog', 'wp_', 'stg_'], [$copy['payload']['container'], $copy['payload']['from'], $copy['payload']['to']]);
        $this->step($secret, 'fs.mkdir');
        $this->assertSame(['app-blog-staging-data', ['wp-content']], [($t = $this->step($secret, 'fs.trash'))['payload']['volume'], $t['payload']['paths']]);
        $cp = $this->step($secret, 'fs.copy');
        $this->assertSame(['app-blog-data', 'app-blog-staging-data'], [$cp['payload']['volume'], $cp['payload']['dest_volume']]);
        $this->step($secret, 'fs.delete');
        $sr = $this->step($secret, 'wp.cli');
        $this->assertSame(['search-replace', 'https://blog.example.com', 'https://staging.blog.example.com'], array_slice($sr['payload']['args'], 0, 3));
        $this->assertSame('app-blog-staging', $sr['payload']['container']);
        $this->assertSame(['option', 'update', 'blog_public', '0'], $this->step($secret, 'wp.cli')['payload']['args']);
        $this->step($secret, 'wp.inventory', ['version' => '6.8.3', 'siteurl' => 'https://staging.blog.example.com']);
        $this->assertSame('ready', $staging->fresh()->staging_status);
        $this->api($token)->getJson('/api/v1/wordpress')->assertJsonPath('data.summary.staging', 1);

        // Push to live: restore point on live first, then tables and files back.
        $this->api($token)->postJson("/api/v1/wordpress/{$staging->id}/staging/push")->assertStatus(202);
        $this->restorePointTaken($secret);
        $back = $this->step($secret, 'wp.copy_tables');
        $this->assertSame(['app-blog-staging', 'stg_', 'wp_'], [$back['payload']['container'], $back['payload']['from'], $back['payload']['to']]);
        $this->assertSame(['wp-content/ws-restore'], $this->step($secret, 'fs.move')['payload']['paths']);
        $this->assertSame('app-blog-data', $this->step($secret, 'fs.trash')['payload']['volume']);
        $this->step($secret, 'fs.mkdir');
        $this->assertSame('app-blog-data', $this->step($secret, 'fs.copy')['payload']['dest_volume']);
        $this->step($secret, 'fs.delete');
        $this->assertSame(['ws-restore'], $this->step($secret, 'fs.move')['payload']['paths']);
        $this->assertSame('https://blog.example.com', $this->step($secret, 'wp.cli')['payload']['args'][2]);
        $this->assertSame(['option', 'update', 'blog_public', '1'], $this->step($secret, 'wp.cli')['payload']['args']);
        $this->step($secret, 'wp.inventory', ['version' => '6.8.3', 'siteurl' => 'https://blog.example.com']);
        $this->assertSame('push', WpRestorePoint::allTenants()->where('wp_site_id', $site->id)->sole()->reason);

        $this->api($token)->postJson("/api/v1/wordpress/{$site->id}/staging/push")->assertStatus(409); // live is not a staging site
        $this->api($token)->postJson("/api/v1/wordpress/{$staging->id}/staging/delete")->assertStatus(202);
        $this->assertSame('stg_', $this->step($secret, 'wp.drop_tables', ['dropped' => 12])['payload']['from']);
        $this->assertNull(WpSite::allTenants()->find($staging->id));
        $this->assertSoftDeleted($stagingApp);
    }
}
