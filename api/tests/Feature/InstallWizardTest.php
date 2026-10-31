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
}
