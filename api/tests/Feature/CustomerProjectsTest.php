<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Project;
use App\Support\Tenant;
use Tests\TestCase;

/** UPRJ: customers group their own apps into projects. */
class CustomerProjectsTest extends TestCase
{
    private function app(string $orgId, string $name): Application
    {
        return app(Tenant::class)->as($orgId, fn () => Application::create(['name' => $name, 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'size' => 'small', 'port' => 80, 'region' => 'eu-west']));
    }

    public function test_customers_create_fill_rename_and_delete_projects(): void
    {
        [, $org, $token] = $this->org();
        $shop = $this->app($org->id, 'shop');
        $this->app($org->id, 'blog');

        $p = $this->api($token)->postJson('/api/v1/app/projects', ['name' => 'Storefront', 'color' => 'violet'])->assertCreated()->json('data');
        $this->assertSame(0, $p['counts']['apps']);
        $this->api($token)->postJson('/api/v1/app/projects', ['name' => 'storefront'])->assertStatus(409);
        $this->api($token)->postJson('/api/v1/app/projects', ['name' => 'X', 'color' => 'neon'])->assertStatus(422);

        $this->api($token)->postJson("/api/v1/app/projects/{$p['id']}/apps", ['app_id' => $shop->id])->assertOk()
            ->assertJsonPath('data.counts.apps', 1)->assertJsonPath('data.regions.0', 'eu-west');
        $list = $this->api($token)->getJson('/api/v1/app/projects')->assertOk()->json('data');
        $this->assertSame(['blog'], array_column($list['unassigned'], 'name'));

        $this->api($token)->patchJson("/api/v1/app/projects/{$p['id']}", ['name' => 'Shop'])->assertOk()->assertJsonPath('data.name', 'Shop');
        $this->api($token)->deleteJson("/api/v1/app/projects/{$p['id']}/apps/{$shop->id}")->assertOk()->assertJsonPath('data.counts.apps', 0);

        $this->api($token)->postJson("/api/v1/app/projects/{$p['id']}/apps", ['app_id' => $shop->id])->assertOk();
        $this->api($token)->deleteJson("/api/v1/app/projects/{$p['id']}")->assertNoContent();
        $this->assertNull($shop->fresh()->project_id, 'the app survives with no project');
        $this->assertSame(0, Project::allTenants()->count());
    }

    public function test_projects_and_apps_of_another_customer_are_out_of_reach(): void
    {
        [, $a, $tokenA] = $this->org();
        [, $b, $tokenB] = $this->org();
        $other = $this->app($b->id, 'theirs');
        $p = $this->api($tokenA)->postJson('/api/v1/app/projects', ['name' => 'Mine'])->assertCreated()->json('data');

        $this->api($tokenA)->postJson("/api/v1/app/projects/{$p['id']}/apps", ['app_id' => $other->id])->assertNotFound();
        $this->api($tokenB)->patchJson("/api/v1/app/projects/{$p['id']}", ['name' => 'Hijack'])->assertNotFound();
        $this->api($tokenB)->deleteJson("/api/v1/app/projects/{$p['id']}")->assertNotFound();
        $this->assertSame([], $this->api($tokenB)->getJson('/api/v1/app/projects')->json('data.projects'));
    }

    public function test_the_canvas_links_apps_that_name_each_other(): void
    {
        [, $org, $token] = $this->org();
        \App\Models\Plan::firstOrCreate(['code' => 'small'], ['name' => 'Small', 'ram_mb' => 512, 'price_cents' => 500, 'limits' => []]);
        $web = $this->app($org->id, 'web');
        $api = $this->app($org->id, 'api');
        $this->app($org->id, 'lonely');
        app(Tenant::class)->as($org->id, function () use ($web, $api) {
            \App\Models\EnvVar::create(['application_id' => $web->id, 'key' => 'API_URL', 'value' => 'http://api:8080/v1', 'secret' => false]);
            \App\Models\EnvVar::create(['application_id' => $web->id, 'key' => 'NOTE', 'value' => 'rapid apis', 'secret' => false]);
            \App\Models\EnvVar::create(['application_id' => $api->id, 'key' => 'TOKEN', 'value' => 'secret-web-token', 'secret' => true]);
        });
        $p = $this->api($token)->postJson('/api/v1/app/projects', ['name' => 'Shop'])->json('data');
        foreach ([$web, $api] as $a) {
            $this->api($token)->postJson("/api/v1/app/projects/{$p['id']}/apps", ['app_id' => $a->id])->assertOk();
        }

        $c = $this->api($token)->getJson("/api/v1/app/projects/{$p['id']}/canvas")->assertOk()->json('data');
        $this->assertSame(['api', 'web'], array_column($c['nodes'], 'name'));
        $this->assertSame([['from' => $web->id, 'to' => $api->id, 'via' => 'API_URL']], $c['links'], 'only a host-like mention links two apps');
        $this->assertStringNotContainsString('8080', json_encode($c), 'variable values never leave the server');
        $this->assertSame(1000, $this->api($token)->getJson('/api/v1/app/projects')->json('data.projects.0.cost_cents'));

        [, , $other] = $this->org();
        $this->api($other)->getJson("/api/v1/app/projects/{$p['id']}/canvas")->assertNotFound();
    }
}
