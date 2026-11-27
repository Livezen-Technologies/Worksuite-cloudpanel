<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Container;
use App\Models\Project;
use App\Models\Volume;
use App\Models\VolumeMount;
use App\Support\Tenant;
use Tests\TestCase;

/** The volume list names who a volume belongs to: owner, project and the apps that mount it. */
class VolumeOwnershipTest extends TestCase
{
    public function test_volumes_carry_owner_project_and_applications(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        app(Tenant::class)->as($org->id, function () use ($server) {
            $p = Project::create(['name' => 'Shop']);
            $app = Application::create(['name' => 'shop-web', 'source_type' => 'image', 'image' => 'nginx', 'project_id' => $p->id]);
            $c = Container::create(['server_id' => $server->id, 'application_id' => $app->id, 'name' => 'shop-web-1', 'image' => 'nginx:1', 'state' => 'running']);
            $v = Volume::create(['server_id' => $server->id, 'name' => 'shop_data', 'project_id' => $p->id]);
            VolumeMount::create(['volume_id' => $v->id, 'container_id' => $c->id, 'target' => '/data']);
            Volume::create(['server_id' => $server->id, 'name' => 'loose']);
        });

        $rows = collect($this->api($token)->getJson('/api/v1/volumes?per_page=200')->assertOk()->json('data'))->keyBy('name');
        $this->assertSame(['Shop', ['shop-web'], $org->name], [$rows['shop_data']['project_name'], $rows['shop_data']['applications'], $rows['shop_data']['owner_name']]);
        $this->assertSame([null, [], $org->name], [$rows['loose']['project_name'], $rows['loose']['applications'], $rows['loose']['owner_name']]);
        $this->assertArrayNotHasKey('mounts', $rows['shop_data']);
    }

    public function test_without_mount_records_the_owner_is_inferred_from_docker_names(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        app(Tenant::class)->as($org->id, function () use ($server) {
            $p = Project::create(['name' => 'Gateway']);
            $app = Application::create(['name' => 'apim', 'source_type' => 'image', 'image' => 'nginx', 'project_id' => $p->id]);
            Container::create(['server_id' => $server->id, 'application_id' => $app->id, 'name' => 'apim-pg-1', 'image' => 'postgres:16', 'state' => 'running',
                'labels' => ['com.docker.compose.project' => 'apim']]);
            Container::create(['server_id' => $server->id, 'project_id' => $p->id, 'name' => 'db-orders', 'image' => 'mariadb:11', 'state' => 'running']);
            Volume::create(['server_id' => $server->id, 'name' => 'apim_pg']);
            Volume::create(['server_id' => $server->id, 'name' => 'db-orders-data']);
            Volume::create(['server_id' => $server->id, 'name' => '0357a0b5c2380e8f1b8678']);
        });

        $rows = collect($this->api($token)->getJson('/api/v1/volumes?per_page=200')->assertOk()->json('data'))->keyBy('name');
        $this->assertSame(['Gateway', ['apim']], [$rows['apim_pg']['project_name'], $rows['apim_pg']['applications']]);
        $this->assertSame(['Gateway', []], [$rows['db-orders-data']['project_name'], $rows['db-orders-data']['applications']]);
        $this->assertSame([null, []], [$rows['0357a0b5c2380e8f1b8678']['project_name'], $rows['0357a0b5c2380e8f1b8678']['applications']]);
    }
}
