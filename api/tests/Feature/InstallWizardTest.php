<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Database;
use App\Models\EnvVar;
use App\Models\Secret;
use Tests\TestCase;

/** INST-2..4: install wizard options and wiring an existing managed database into a template. */
class InstallWizardTest extends TestCase
{
    public function test_wizard_options_and_existing_database(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        $secret = (new Secret)->forceFill(['organization_id' => $org->id, 'name' => 'shopdb-password', 'value' => 'aaaa-aaaa-dbpass']);
        $secret->save();
        $db = (new Database)->forceFill(['organization_id' => $org->id, 'server_id' => $server->id, 'name' => 'shopdb', 'engine' => 'mariadb', 'version' => '11', 'secret_id' => $secret->id, 'status' => 'running']);
        $db->save();

        $res = $this->api($token)->postJson('/api/v1/templates/phpmyadmin/install', [
            'name' => 'pma', 'database_id' => $db->id, 'size' => 'pro', 'auto_restart' => false, 'health_path' => '/healthz',
            'env' => [['key' => 'EXTRA_FLAG', 'value' => '1'], ['key' => 'API_KEY', 'value' => 'aaaa', 'secret' => true]],
        ])->assertCreated()->json('data');

        $app = Application::allTenants()->find($res['resource_id']);
        $this->assertSame([$server->id, 'pro', false, '/healthz'], [$app->server_id, $app->size, (bool) $app->auto_restart, $app->health_path]);
        $env = EnvVar::where('application_id', $app->id)->pluck('value', 'key');
        $this->assertSame('db-shopdb:3306', $env['PMA_HOST'], 'host:port of the managed database');
        $this->assertSame('1', $env['EXTRA_FLAG']);
        $this->assertTrue((bool) EnvVar::where('application_id', $app->id)->where('key', 'API_KEY')->value('secret'));

        $this->api($token)->postJson('/api/v1/templates/phpmyadmin/install', ['name' => 'pma2', 'size' => 'huge'])->assertStatus(422);
        $this->api($token)->postJson('/api/v1/templates/phpmyadmin/install', ['name' => 'pma3', 'variables' => ['PMA_HOST' => 'x'], 'env' => [['key' => '1bad', 'value' => 'x']]])->assertStatus(422);
    }

    public function test_preflight_reports_each_check_by_step(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        $off = $this->server($org, ['last_heartbeat_at' => now()->subHour(), 'name' => 'old-box']);
        Application::allTenants()->create(['organization_id' => $org->id, 'name' => 'taken', 'kind' => 'admin', 'source_type' => 'image', 'image' => 'nginx:1']);

        $ok = $this->api($token)->postJson('/api/v1/templates/wordpress/preflight', [
            'name' => 'blog', 'server_id' => $server->id, 'domain_mode' => 'auto',
            'variables' => ['WORDPRESS_DB_HOST' => 'db', 'WORDPRESS_DB_USER' => 'u', 'WORDPRESS_DB_PASSWORD' => 'aaaa', 'WORDPRESS_DB_NAME' => 'wp'],
        ])->assertOk()->json('data');
        $byKey = collect($ok['checks'])->keyBy('key');
        $this->assertSame('pass', $byKey['name']['status']);
        $this->assertSame('pass', $byKey['server']['status']);
        $this->assertSame('pass', $byKey['docker']['status']);
        $this->assertSame('pass', $byKey['domain']['status']);
        $this->assertStringContainsString(config('platform.domain'), $byKey['domain']['message']);

        $bad = $this->api($token)->postJson('/api/v1/templates/wordpress/preflight', [
            'name' => 'taken', 'server_id' => $off->id, 'domain_mode' => 'subdomain', 'subdomain' => '-nope-', 'env' => [['key' => '1bad', 'value' => 'x']],
        ])->assertOk()->json('data');
        $byKey = collect($bad['checks'])->keyBy('key');
        $this->assertFalse($bad['ok']);
        $this->assertSame(['fail', 'details'], [$byKey['name']['status'], $byKey['name']['step']]);
        $this->assertSame('fail', $byKey['server']['status']);
        $this->assertSame('fail', $byKey['domain']['status']);
        $this->assertSame(['fail', 'advanced'], [$byKey['env']['status'], $byKey['env']['step']]);
    }

    public function test_domain_modes_and_first_deployment(): void
    {
        [, $org, $token] = $this->org();
        $this->server($org);
        $vars = ['WORDPRESS_DB_HOST' => 'db', 'WORDPRESS_DB_USER' => 'u', 'WORDPRESS_DB_PASSWORD' => 'aaaa', 'WORDPRESS_DB_NAME' => 'wp'];
        $platform = config('platform.domain');

        $auto = $this->api($token)->postJson('/api/v1/templates/wordpress/install', ['name' => 'blog', 'domain_mode' => 'auto', 'variables' => $vars])->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('#^https://blog-[a-z0-9]{6}\.'.preg_quote($platform).'$#', $auto['url']);
        $this->assertNotEmpty($auto['job_id'], 'the first deployment starts with the install');
        $domain = \App\Models\Domain::allTenants()->where('application_id', $auto['resource_id'])->first();
        $this->assertTrue((bool) $domain->platform_subdomain);
        $this->assertSame(substr($auto['url'], 8), $domain->name);

        $sub = $this->api($token)->postJson('/api/v1/templates/wordpress/install', ['name' => 'shop', 'domain_mode' => 'subdomain', 'subdomain' => 'myshop', 'variables' => $vars])->assertCreated()->json('data');
        $this->assertSame("https://myshop.{$platform}", $sub['url']);
        $this->api($token)->postJson('/api/v1/templates/wordpress/install', ['name' => 'shop2', 'domain_mode' => 'subdomain', 'subdomain' => 'myshop', 'variables' => $vars])
            ->assertStatus(422)->assertJsonPath('error.fields.subdomain.0', "myshop.{$platform} is taken. Pick another name.");

        $none = $this->api($token)->postJson('/api/v1/templates/wordpress/install', ['name' => 'intranet', 'domain_mode' => 'none', 'variables' => $vars])->assertCreated()->json('data');
        $this->assertNull($none['url']);
        $this->assertSame(0, \App\Models\Domain::allTenants()->where('application_id', $none['resource_id'])->count());
    }

    public function test_install_emails_never_carry_secrets(): void
    {
        [, $org] = $this->org();
        $server = $this->server($org);
        $app = Application::allTenants()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'name' => 'blog', 'kind' => 'admin', 'source_type' => 'image', 'image' => 'wordpress:6', 'size' => 'pro', 'source' => ['template' => 'wordpress']]);
        EnvVar::allTenants()->create(['organization_id' => $org->id, 'application_id' => $app->id, 'key' => 'WORDPRESS_DB_PASSWORD', 'value' => 'aaaa-aaaa-secret', 'secret' => true]);
        $deployment = \App\Models\Deployment::allTenants()->create(['organization_id' => $org->id, 'application_id' => $app->id, 'status' => 'succeeded', 'source' => 'manual']);

        $sent = [];
        $mock = $this->mock(\App\Services\EmailTemplateService::class);
        $mock->shouldReceive('notifyOrganization')->andReturnUsing(function ($o, $key, $vars) use (&$sent) { $sent[$key] = $vars; return 1; });
        $mock->shouldReceive('notifyStaff')->andReturnUsing(function ($key, $vars) use (&$sent) { $sent[$key] = $vars; return 1; });
        app(\App\Services\DeploymentService::class)->notifyInstalled($app, $deployment);

        $this->assertSame(['app_installed', 'app_installed_admin'], array_keys($sent));
        $this->assertSame('blog', $sent['app_installed']['app']);
        $this->assertStringContainsString('4 GB RAM', $sent['app_installed']['resources']);
        $this->assertSame($deployment->id, $sent['app_installed_admin']['deployment_id']);
        $this->assertStringNotContainsString('aaaa-aaaa-secret', json_encode($sent));
    }
}
