<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Database;
use App\Models\EnvVar;
use App\Services\BuildService;
use Tests\TestCase;

/** WorkSuite Server and WorkSuite CMS: catalogue apps built from their own repositories with a new database. */
class FirstPartyCatalogueTest extends TestCase
{
    public function test_worksuite_server_builds_from_git_and_is_wired_to_a_new_database(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        $this->api($token)->getJson('/api/v1/templates')->assertOk();
        $res = $this->api($token)->postJson('/api/v1/templates/worksuite-server/install', ['name' => 'acme-ws', 'server_id' => $server->id,
            'create_database' => ['engine' => 'mariadb']])->assertCreated()->json('data');

        $app = Application::allTenants()->find($res['resource_id']);
        $this->assertSame('git', $app->source_type);
        $this->assertSame('https://github.com/Livezen-Technologies/worksuite.git', $app->source['repo']);
        $this->assertTrue($app->source['template_git']);
        $this->assertTrue(app(BuildService::class)->needsBuild($app));
        $db = Database::allTenants()->sole();
        $this->assertSame('worksuite', $db->database ?? 'worksuite');
        $env = EnvVar::allTenants()->where('application_id', $app->id)->get()->keyBy('key');
        $this->assertSame('db-acme-ws-db', $env['DB_HOST']->value);
        $this->assertSame('worksuite', $env['DB_NAME']->value);
        $this->assertTrue($env['DB_PASSWORD']->secret);
        $this->assertNotSame('', $env['DB_PASSWORD']->value);
    }

    public function test_worksuite_cms_fills_its_codeigniter_database_variables(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        $this->api($token)->getJson('/api/v1/templates')->assertOk();
        $res = $this->api($token)->postJson('/api/v1/templates/worksuite-cms/install', ['name' => 'site', 'server_id' => $server->id,
            'create_database' => ['engine' => 'mariadb']])->assertCreated()->json('data');
        $env = EnvVar::allTenants()->where('application_id', $res['resource_id'])->get()->keyBy('key');
        $this->assertSame('db-site-db', $env['database_default_hostname']->value);
        $this->assertSame('root', $env['database_default_username']->value);
        $this->assertSame('wscms', $env['database_default_database']->value);
        $this->assertTrue($env['database_default_password']->secret);
    }

    public function test_the_catalogue_token_goes_only_to_first_party_repositories(): void
    {
        config(['platform.templates.git_token' => 'ghp_test', 'platform.templates.git_owners' => ['https://github.com/Livezen-Technologies']]);
        $b = app(BuildService::class);
        $this->assertSame('ghp_test', $b->catalogueAuth('https://github.com/Livezen-Technologies/worksuite.git')['token']);
        $this->assertNull($b->catalogueAuth('https://github.com/Livezen-Technologies-evil/x.git'));
        $this->assertNull($b->catalogueAuth('https://github.com/Livezen-Technologies/../evil/x.git'));
        $this->assertNull($b->catalogueAuth('https://evil.test/Livezen-Technologies/x.git'));
        config(['platform.templates.git_token' => null]);
        $this->assertNull($b->catalogueAuth('https://github.com/Livezen-Technologies/worksuite.git'));
    }
}
