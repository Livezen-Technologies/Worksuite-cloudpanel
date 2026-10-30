<?php

namespace Tests\Feature;

use App\Models\AgentCommand;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Job;
use App\Models\WpSite;
use App\Services\WordPressService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** SRS WP-001..004, 007, 008, 012: the WordPress toolkit through WP-CLI on the agent. */
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
}
