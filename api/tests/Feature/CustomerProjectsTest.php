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

    /**
     * The create form asks for an environment, a purpose and a description, as
     * well as a name. The environment matters: a project without one cannot
     * hold an app at all, so it is made with the project rather than later.
     */
    public function test_a_project_is_created_with_its_first_environment_purpose_and_description(): void
    {
        [, , $token] = $this->org();

        $p = $this->api($token)->postJson('/api/v1/app/projects', [
            'name' => 'Storefront', 'color' => 'violet', 'environment' => 'production',
            'purpose' => 'web_application', 'description' => 'The public shop',
        ])->assertCreated()->json('data');

        $this->assertSame('web_application', $p['purpose']);
        $this->assertSame('The public shop', $p['description']);
        // Reported even though the project has no apps yet.
        $this->assertSame(['production'], $p['environments']);
        $env = \App\Models\Environment::allTenants()->where('project_id', $p['id'])->firstOrFail();
        $this->assertSame(['Production', 'production', true], [$env->name, $env->kind, (bool) $env->requires_approval]);

        // Both extra fields are optional, and both are checked against a list.
        $this->api($token)->postJson('/api/v1/app/projects', ['name' => 'Plain'])->assertCreated()->assertJsonPath('data.environments', []);
        $this->api($token)->postJson('/api/v1/app/projects', ['name' => 'Bad env', 'environment' => 'qa'])->assertStatus(422);
        $this->api($token)->postJson('/api/v1/app/projects', ['name' => 'Bad purpose', 'purpose' => 'world domination'])->assertStatus(422);

        $list = $this->api($token)->getJson('/api/v1/app/projects')->assertOk()->json('data');
        $this->assertContains('web_application', $list['purposes']);
        $this->assertSame('Production', $list['environment_types']['production']);
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
        // The app survives the project. It is no longer left with no project at
        // all: an app outside an environment loses push-deploys, service
        // discovery and promotion, so it lands in the default project.
        $moved = $shop->fresh();
        $this->assertNotNull($moved->project_id);
        $this->assertNotNull($moved->environment_id);
        $this->assertTrue((bool) Project::allTenants()->find($moved->project_id)->is_default);
        $this->assertSame(1, Project::allTenants()->count());
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

    public function test_the_canvas_saves_a_layout_and_draws_and_removes_links(): void
    {
        [, $org, $token] = $this->org();
        $web = $this->app($org->id, 'web');
        $api = $this->app($org->id, 'billing-api');
        $outside = $this->app($org->id, 'elsewhere');
        $p = $this->api($token)->postJson('/api/v1/app/projects', ['name' => 'Shop', 'environment' => 'production'])->json('data');
        foreach ([$web, $api] as $a) {
            $this->api($token)->postJson("/api/v1/app/projects/{$p['id']}/apps", ['app_id' => $a->id])->assertOk();
        }
        $base = "/api/v1/app/projects/{$p['id']}/canvas";

        // Layout: only this project's apps, saved and returned.
        $this->api($token)->putJson("{$base}/layout", ['positions' => [$outside->id => ['x' => 1, 'y' => 1]]])->assertStatus(422);
        $this->api($token)->putJson("{$base}/layout", ['positions' => [$web->id => ['x' => -5, 'y' => 1]]])->assertStatus(422);
        $this->api($token)->putJson("{$base}/layout", ['positions' => [$web->id => ['x' => 300, 'y' => 40]]])->assertNoContent();
        $c = $this->api($token)->getJson($base)->json('data');
        $this->assertSame(['x' => 300, 'y' => 40], $c['layout'][$web->id]);
        $this->assertSame(['production'], array_column($c['environments'], 'kind'));
        $this->assertSame($c['environments'][0]['id'], $c['nodes'][0]['environment_id']);

        // Drawing a line gives the caller a variable the canvas reads back as that line.
        $l = $this->api($token)->postJson("{$base}/links", ['from' => $web->id, 'to' => $api->id])->assertCreated()->json('data');
        $this->assertSame(['BILLING_API_URL', true], [$l['via'], $l['redeploy_needed']]);
        $this->assertSame('http://billing-api:80', \App\Models\EnvVar::allTenants()->where('application_id', $web->id)->where('key', 'BILLING_API_URL')->value('value'));
        $this->assertSame([['from' => $web->id, 'to' => $api->id, 'via' => 'BILLING_API_URL']], $this->api($token)->getJson($base)->json('data.links'));
        $this->api($token)->postJson("{$base}/links", ['from' => $web->id, 'to' => $api->id])->assertStatus(422)->assertJsonPath('error.fields.key.0', fn ($m) => str_contains($m, 'already has'));
        $this->api($token)->postJson("{$base}/links", ['from' => $web->id, 'to' => $outside->id])->assertNotFound();
        $this->api($token)->postJson("{$base}/links", ['from' => $web->id, 'to' => $web->id])->assertStatus(422);

        // Removing it deletes only a variable that really points there.
        $this->api($token)->deleteJson("{$base}/links?from={$api->id}&to={$web->id}&key=BILLING_API_URL")->assertNotFound();
        $this->api($token)->deleteJson("{$base}/links?from={$web->id}&to={$api->id}&key=BILLING_API_URL")->assertNoContent();
        $this->assertSame([], $this->api($token)->getJson($base)->json('data.links'));

        [, , $other] = $this->org();
        $this->api($other)->putJson("{$base}/layout", ['positions' => []])->assertNotFound();
        $this->api($other)->postJson("{$base}/links", ['from' => $web->id, 'to' => $api->id])->assertNotFound();
    }

    public function test_customers_add_copy_promote_and_delete_environments(): void
    {
        [, $org, $token] = $this->org();
        $web = $this->app($org->id, 'shopweb');
        app(Tenant::class)->as($org->id, fn () => \App\Models\EnvVar::create(['application_id' => $web->id, 'key' => 'MODE', 'value' => 'live', 'secret' => false]));
        $p = $this->api($token)->postJson('/api/v1/app/projects', ['name' => 'Shop', 'environment' => 'production'])->json('data');
        $this->api($token)->postJson("/api/v1/app/projects/{$p['id']}/apps", ['app_id' => $web->id])->assertOk();
        $base = "/api/v1/app/projects/{$p['id']}/environments";
        $prod = $this->api($token)->getJson($base)->assertOk()->json('data.0');
        $this->assertSame(['production', ['shopweb']], [$prod['kind'], array_column($prod['apps'], 'name')]);
        $this->assertArrayNotHasKey('variables', $prod, 'variable values stay on the server');

        // A copy brings the apps and their variables, not deployed.
        $staging = $this->api($token)->postJson($base, ['name' => 'Staging', 'kind' => 'staging', 'copy_from' => $prod['id'], 'branch' => 'develop'])->assertCreated()->json('data');
        $this->assertSame(['shopweb-staging', 'created', 'develop', $prod['id']], [$staging['apps'][0]['name'], $staging['apps'][0]['status'], $staging['branch'], $staging['duplicated_from_id']]);
        $copy = Application::allTenants()->find($staging['apps'][0]['id']);
        $this->assertSame([$p['id'], 'customer', 'live'], [$copy->project_id, $copy->kind, $copy->envVars()->value('value')]);
        $this->api($token)->postJson($base, ['name' => 'Staging', 'kind' => 'staging'])->assertStatus(422);
        $this->api($token)->postJson($base, ['name' => 'Bad', 'kind' => 'preview'])->assertStatus(422);
        $dev = $this->api($token)->postJson($base, ['name' => 'Dev', 'kind' => 'development'])->assertCreated()->json('data');
        $this->assertSame([], $dev['apps']);

        // Nothing deployed yet, so promotion reports what it skipped.
        $r = $this->api($token)->postJson("/api/v1/app/environments/{$staging['id']}/promote", ['target_environment_id' => $prod['id']])->assertStatus(202)->json('data.results');
        $this->assertSame([null, 'not deployed in Staging'], [$r[0]['deployment_id'], $r[0]['skipped']]);

        $this->api($token)->deleteJson("/api/v1/app/environments/{$staging['id']}")->assertStatus(409);
        $this->api($token)->deleteJson("/api/v1/app/environments/{$dev['id']}")->assertNoContent();

        [, , $other] = $this->org();
        $this->api($other)->getJson($base)->assertNotFound();
        $this->api($other)->postJson("/api/v1/app/environments/{$staging['id']}/promote", ['target_environment_id' => $prod['id']])->assertNotFound();
        $this->api($other)->deleteJson("/api/v1/app/environments/{$prod['id']}")->assertNotFound();
    }

    public function test_a_project_keeps_at_least_one_environment_and_at_most_five(): void
    {
        [, , $token] = $this->org();
        $p = $this->api($token)->postJson('/api/v1/app/projects', ['name' => 'Shop', 'environment' => 'production'])->json('data');
        $base = "/api/v1/app/projects/{$p['id']}/environments";
        $only = $this->api($token)->getJson($base)->json('data.0.id');
        $this->api($token)->deleteJson("/api/v1/app/environments/{$only}")->assertStatus(409);
        foreach (range(1, 4) as $n) {
            $this->api($token)->postJson($base, ['name' => "Dev {$n}", 'kind' => 'development'])->assertCreated();
        }
        $this->api($token)->postJson($base, ['name' => 'Dev 5', 'kind' => 'development'])->assertStatus(409);
    }
}
