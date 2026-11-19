<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Container;
use App\Models\Database;
use App\Models\EnvVar;
use Tests\TestCase;

/** Catalogue install together with a new database: the database is created first and the app wired to it. */
class InstallWithDatabaseTest extends TestCase
{
    public function test_install_creates_the_database_and_links_the_app(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        $this->api($token)->getJson('/api/v1/templates')->assertOk();
        // phpMyAdmin needs a database host: with a new MariaDB it is filled in on the server.
        $res = $this->api($token)->postJson('/api/v1/templates/phpmyadmin/install', ['name' => 'pma', 'server_id' => $server->id,
            'create_database' => ['engine' => 'mariadb']])->assertCreated()->json('data');

        $db = Database::allTenants()->sole();
        $this->assertSame(['pma-db', 'mariadb', $server->id], [$db->name, $db->engine, $db->server_id]);
        $dbContainer = Container::allTenants()->where('name', 'db-pma-db')->sole();
        $this->assertSame('mariadb:11', $dbContainer->image);

        $app = Application::allTenants()->find($res['resource_id']);
        $this->assertSame($db->id, $app->source['database_id']);
        $this->assertSame($server->id, $app->server_id);
        $this->assertSame('db-pma-db', EnvVar::allTenants()->where('application_id', $app->id)->where('key', 'PMA_HOST')->value('value') !== null ? explode(':', EnvVar::allTenants()->where('application_id', $app->id)->where('key', 'PMA_HOST')->value('value'))[0] : null);

        $this->api($token)->postJson('/api/v1/templates/phpmyadmin/install', ['name' => 'pma2', 'create_database' => ['engine' => 'oracle']])->assertStatus(422);
    }
}
