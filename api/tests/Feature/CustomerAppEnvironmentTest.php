<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Project;
use Tests\TestCase;

/**
 * Every customer application belongs to a project and an environment.
 *
 * Customer apps used to be created with `environment` set to the string
 * 'production' and `environment_id` left null, while the whole environment
 * subsystem keys on the foreign key. That one omission cut them off from
 * push-deploys, `${{service.HOST}}` discovery, promotion and previews — so
 * these tests are mostly about those four, not about the column itself.
 */
class CustomerAppEnvironmentTest extends TestCase
{
    private function host(string $token, string $name, array $extra = [])
    {
        return $this->api($token)->postJson('/api/v1/app/apps', array_merge([
            'name' => $name, 'source' => ['type' => 'image', 'image' => 'nginx:1.27'],
            'port' => 80, 'region' => 'eu-central', 'size' => 'small',
        ], $extra));
    }

    public function test_an_app_hosted_without_a_project_lands_in_the_default_one(): void
    {
        [, $org, $token] = $this->org();
        $this->server($org, ['region' => 'eu-central', 'ram_mb' => 32768, 'ram_pct' => 10]);

        $id = $this->host($token, 'shop')->assertStatus(202)->json('data.app.id');
        $app = Application::allTenants()->find($id);

        $this->assertNotNull($app->environment_id, 'the app must be bound to an environment, not just the string');
        $env = Environment::allTenants()->find($app->environment_id);
        $this->assertSame('production', $env->kind);
        $this->assertSame($env->kind, $app->environment, 'the legacy string stays in step with the environment');
        $this->assertSame($env->project_id, $app->project_id);

        $project = Project::allTenants()->find($app->project_id);
        $this->assertTrue((bool) $project->is_default);

        // A second app joins the same default project rather than making another.
        $this->host($token, 'blog')->assertStatus(202);
        $this->assertSame(1, Project::allTenants()->where('organization_id', $org->id)->count());
    }

    public function test_an_existing_project_is_adopted_instead_of_adding_a_default_beside_it(): void
    {
        [, $org, $token] = $this->org();
        $this->server($org, ['region' => 'eu-central', 'ram_mb' => 32768, 'ram_pct' => 10]);
        $mine = $this->api($token)->postJson('/api/v1/app/projects', ['name' => 'Storefront', 'environment' => 'staging'])
            ->assertCreated()->json('data');

        $id = $this->host($token, 'shop')->assertStatus(202)->json('data.app.id');
        $app = Application::allTenants()->find($id);

        $this->assertSame($mine['id'], $app->project_id, 'the only project becomes the default');
        $this->assertSame('staging', $app->environment, 'and its only environment is used when there is no production one');
        $this->assertSame(1, Project::allTenants()->where('organization_id', $org->id)->count());
    }

    public function test_an_app_can_be_hosted_straight_into_a_chosen_project_and_environment(): void
    {
        [, $org, $token] = $this->org();
        $this->server($org, ['region' => 'eu-central', 'ram_mb' => 32768, 'ram_pct' => 10]);
        $p = $this->api($token)->postJson('/api/v1/app/projects', ['name' => 'Storefront', 'environment' => 'production'])->assertCreated()->json('data');
        $this->api($token)->postJson('/api/v1/app/projects', ['name' => 'Other'])->assertCreated();

        $id = $this->host($token, 'api', ['project_id' => $p['id'], 'environment' => 'production'])->assertStatus(202)->json('data.app.id');
        $app = Application::allTenants()->find($id);
        $this->assertSame($p['id'], $app->project_id);
        $this->assertSame('production', $app->environment);

        // An environment kind the project does not have yet is created in it.
        $id2 = $this->host($token, 'api-staging', ['project_id' => $p['id'], 'environment' => 'staging'])->assertStatus(202)->json('data.app.id');
        $this->assertSame('staging', Application::allTenants()->find($id2)->environment);
        $this->assertSame(2, Environment::allTenants()->where('project_id', $p['id'])->count());

        $this->host($token, 'bad', ['environment' => 'nowhere'])->assertStatus(422);
    }

    public function test_moving_an_app_between_projects_moves_its_environment_too(): void
    {
        [, $org, $token] = $this->org();
        $this->server($org, ['region' => 'eu-central', 'ram_mb' => 32768, 'ram_pct' => 10]);
        $id = $this->host($token, 'shop')->assertStatus(202)->json('data.app.id');
        $target = $this->api($token)->postJson('/api/v1/app/projects', ['name' => 'Storefront', 'environment' => 'production'])->assertCreated()->json('data');

        $this->api($token)->postJson("/api/v1/app/projects/{$target['id']}/apps", ['app_id' => $id])->assertOk();
        $app = Application::allTenants()->find($id);
        $this->assertSame($target['id'], $app->project_id);
        $this->assertSame($target['id'], Environment::allTenants()->find($app->environment_id)->project_id,
            'the environment must belong to the project the app is now in');

        // Taking it out never leaves it with no environment: it returns to the default project.
        $this->api($token)->deleteJson("/api/v1/app/projects/{$target['id']}/apps/{$id}")->assertOk();
        $app = $app->fresh();
        $this->assertNotNull($app->environment_id);
        $this->assertNotNull($app->project_id);
        $this->assertNotSame($target['id'], $app->project_id);
    }

    public function test_the_default_project_cannot_be_deleted(): void
    {
        [, $org, $token] = $this->org();
        $this->server($org, ['region' => 'eu-central', 'ram_mb' => 32768, 'ram_pct' => 10]);
        $this->host($token, 'shop')->assertStatus(202);
        $list = $this->api($token)->getJson('/api/v1/app/projects')->assertOk()->json('data.projects');
        $this->assertTrue($list[0]['is_default']);
        $this->assertStringContainsString('/api/v1/hooks/git/', $list[0]['webhook_url']);

        $this->api($token)->deleteJson("/api/v1/app/projects/{$list[0]['id']}")->assertStatus(409);
    }

    public function test_a_push_now_deploys_a_customer_app_and_obeys_the_auto_deploy_toggle(): void
    {
        [, $org, $token] = $this->org();
        $this->server($org, ['region' => 'eu-central', 'ram_mb' => 32768, 'ram_pct' => 10]);
        $id = $this->host($token, 'shop', ['source' => ['type' => 'git', 'repo' => 'https://github.com/acme/shop.git', 'branch' => 'main']])
            ->assertStatus(202)->json('data.app.id');
        $app = Application::allTenants()->find($id);
        $this->assertTrue((bool) $app->auto_deploy, 'deploying from a repository opts in to deploying when it changes');

        $projectId = $app->project_id;
        $secret = Project::allTenants()->find($projectId)->webhook_secret;
        $push = function (string $branch) use ($projectId, $secret) {
            $body = json_encode(['ref' => "refs/heads/{$branch}", 'repository' => ['clone_url' => 'https://github.com/Acme/shop']]);

            return $this->call('POST', "/api/v1/hooks/git/{$projectId}", [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_X_GITHUB_EVENT' => 'push',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
            ], $body);
        };

        $ids = $push('main')->assertStatus(202)->json('data.deployments');
        $this->assertCount(1, $ids, 'a push to the app\'s own branch deploys it');
        $this->assertSame($id, Deployment::allTenants()->find($ids[0])->application_id);

        // A different branch is not this app's branch, and the environment names none.
        $this->assertSame([], $push('other')->assertStatus(202)->json('data.deployments'));

        // And the toggle in the portal now actually turns it off.
        $this->api($token)->patchJson("/api/v1/app/apps/{$id}", ['auto_deploy' => false])->assertOk();
        $this->assertSame([], $push('main')->assertStatus(202)->json('data.deployments'));
    }

    public function test_two_apps_in_one_environment_can_find_each_other_by_name(): void
    {
        [, $org, $token] = $this->org();
        $this->server($org, ['region' => 'eu-central', 'ram_mb' => 32768, 'ram_pct' => 10]);
        $this->host($token, 'api')->assertStatus(202);
        $webId = $this->host($token, 'web', ['env' => [['key' => 'API_URL', 'value' => 'http://${{api.HOST}}:8080']]])
            ->assertStatus(202)->json('data.app.id');

        $web = Application::allTenants()->find($webId);
        [$resolved] = app(\App\Services\EnvironmentService::class)->resolve($web, ['API_URL' => 'http://${{api.HOST}}:8080']);
        $this->assertSame('http://api:8080', $resolved['API_URL'],
            'sibling discovery works only when both apps share an environment_id');
    }
}
