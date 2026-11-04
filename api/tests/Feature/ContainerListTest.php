<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Container;
use App\Support\Tenant;
use Tests\TestCase;

/** The container list carries what people search it by: the app and the Compose stack. */
class ContainerListTest extends TestCase
{
    public function test_the_list_names_the_app_and_compose_stack(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        app(Tenant::class)->as($org->id, function () use ($server) {
            $app = Application::create(['name' => 'tender', 'source_type' => 'image', 'image' => 'nginx']);
            Container::create(['server_id' => $server->id, 'application_id' => $app->id, 'name' => 'app-x1', 'image' => 'nginx:1', 'state' => 'running']);
            Container::create(['server_id' => $server->id, 'name' => 'eager_tesla', 'image' => 'postgres:16', 'state' => 'running',
                'labels' => ['com.docker.compose.project' => 'tenderbox', 'com.docker.compose.service' => 'db']]);
        });

        $rows = collect($this->api($token)->getJson('/api/v1/containers?per_page=200')->assertOk()->json('data'))->keyBy('name');
        $this->assertSame(['tender', null, null], [$rows['app-x1']['application_name'], $rows['app-x1']['compose_project'], $rows['app-x1']['compose_service']]);
        $this->assertSame([null, 'tenderbox', 'db'], [$rows['eager_tesla']['application_name'], $rows['eager_tesla']['compose_project'], $rows['eager_tesla']['compose_service']]);
        $this->assertArrayNotHasKey('application', $rows['app-x1']);
        $this->assertArrayNotHasKey('last_logs', $rows['app-x1']);
    }
}
