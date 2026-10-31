<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** ENV-1..7: projects and environments, duplication, references, branch mapping, promotion and sleep. */
class EnvironmentsTest extends TestCase
{
    /** @return array{0:string,1:string,2:array,3:\App\Models\Server} [token, secret, project, server] */
    private function setUpProject(): array
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $project = $this->api($token)->postJson('/api/v1/projects', ['name' => 'Shop'])->assertCreated()->json('data');

        return [$token, $secret, $project, $server];
    }

    private function env(array $project, string $kind): array
    {
        return collect($project['environments'])->firstWhere('kind', $kind);
    }

    private function deploy(string $token, string $secret, string $appId): array
    {
        $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->assertStatus(202);
        $create = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'container.create');
        $this->commandResult($secret, $create['id'], 'succeeded', ['output' => ['container_id' => 'c-'.$appId]])->assertOk();
        $this->passHealthChecks($secret);

        return $create;
    }

    public function test_environments_share_variables_and_resolve_references_between_services(): void
    {
        [$token, $secret, $project, $server] = $this->setUpProject();
        $this->assertSame(['production', 'staging'], array_column($project['environments'], 'kind'));
        $this->assertTrue($this->env($project, 'production')['requires_approval']);
        $staging = $this->env($project, 'staging');
        $this->api($token)->patchJson("/api/v1/environments/{$staging['id']}", ['variables' => ['REGION' => 'eu', 'API_PATH' => '/v1']])->assertOk()->assertJsonPath('data.variables.REGION', 'eu');

        $this->api($token)->postJson('/api/v1/applications', ['name' => 'api', 'environment_id' => $staging['id'], 'source_type' => 'image', 'image' => 'acme/api:1', 'server_id' => $server->id, 'port' => 9000,
            'env' => [['key' => 'DB_URL', 'value' => 'postgres://db/shop']]])->assertCreated()->assertJsonPath('data.environment', 'staging');
        $webId = $this->api($token)->postJson('/api/v1/applications', ['name' => 'web', 'environment_id' => $staging['id'], 'source_type' => 'image', 'image' => 'acme/web:1', 'server_id' => $server->id, 'port' => 8080,
            'env' => [['key' => 'API_URL', 'value' => '${{api.URL}}${{shared.API_PATH}}'], ['key' => 'DATABASE', 'value' => '${{ api.DB_URL }}'], ['key' => 'MISSING', 'value' => 'x${{cache.HOST}}']]])->assertCreated()->json('data.id');

        $create = $this->deploy($token, $secret, $webId);
        $env = $create['payload']['env'];
        $this->assertSame('http://app-api:9000/v1', $env['API_URL']);
        $this->assertSame('postgres://db/shop', $env['DATABASE']);
        $this->assertSame('eu', $env['REGION'], 'shared variables are inherited');
        $this->assertSame('x', $env['MISSING']);
        $release = $this->api($token)->getJson("/api/v1/applications/{$webId}/releases")->json('data.0');
        $this->assertSame(['${{cache.HOST}}: no service cache in this environment'], $release['config']['env_warnings']);
        $this->assertSame(['api', 'web'], array_column($this->api($token)->getJson("/api/v1/environments/{$staging['id']}")->json('data.apps'), 'service'));
    }

    public function test_duplicate_then_promote_the_live_image_without_rebuilding(): void
    {
        [$token, $secret, $project, $server] = $this->setUpProject();
        $staging = $this->env($project, 'staging');
        $prod = $this->env($project, 'production');
        $webId = $this->api($token)->postJson('/api/v1/applications', ['name' => 'web', 'environment_id' => $staging['id'], 'source_type' => 'image', 'image' => 'acme/web:2', 'server_id' => $server->id, 'port' => 8080,
            'env' => [['key' => 'MODE', 'value' => 'staging']]])->assertCreated()->json('data.id');

        $copy = $this->api($token)->postJson("/api/v1/environments/{$staging['id']}/duplicate", ['name' => 'Preview 42', 'kind' => 'preview', 'branch' => 'feature/x'])->assertCreated()->json('data');
        $this->assertSame(['preview-42', 'feature/x', ['web-preview-42']], [$copy['slug'], $copy['branch'], array_column($copy['apps'], 'name')]);
        $this->assertSame('web', $copy['apps'][0]['service']);
        $this->assertSame('staging', Application::allTenants()->find($copy['apps'][0]['id'])->envVars()->value('value'));
        $this->api($token)->postJson("/api/v1/environments/{$staging['id']}/duplicate", ['name' => 'Preview 42'])->assertStatus(422);

        // production gets the same service with its own variables, then the staging image is promoted.
        $this->api($token)->patchJson("/api/v1/environments/{$prod['id']}", ['requires_approval' => false])->assertOk();
        $prodWeb = $this->api($token)->postJson('/api/v1/applications', ['name' => 'web-prod', 'service' => 'web', 'environment_id' => $prod['id'], 'source_type' => 'image', 'image' => 'acme/web:1', 'server_id' => $server->id, 'port' => 8080,
            'env' => [['key' => 'MODE', 'value' => 'production']]])->assertCreated()->json('data.id');
        $this->deploy($token, $secret, $webId);

        $result = $this->api($token)->postJson("/api/v1/environments/{$staging['id']}/promote", ['target_environment_id' => $prod['id']])->assertStatus(202)->json('data');
        $this->assertSame('web', $result[0]['service']);
        $create = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'container.create');
        $this->assertSame('app-web-prod', $create['payload']['name']);
        $this->assertSame('acme/web:2', $create['payload']['image']);
        $this->assertSame('production', $create['payload']['env']['MODE']);

        $this->api($token)->postJson("/api/v1/environments/{$prod['id']}/promote", ['target_environment_id' => $prod['id']])->assertStatus(422);
    }

    public function test_branch_pushes_deploy_mapped_environments_and_require_a_valid_signature(): void
    {
        [$token, , $project, $server] = $this->setUpProject();
        $staging = $this->env($project, 'staging');
        $this->api($token)->patchJson("/api/v1/environments/{$staging['id']}", ['branch' => 'develop'])->assertOk();
        $appId = $this->api($token)->postJson('/api/v1/applications', ['name' => 'api', 'environment_id' => $staging['id'], 'source_type' => 'image', 'image' => 'acme/api:1', 'server_id' => $server->id,
            'source' => ['repo' => 'https://github.com/acme/shop.git']])->assertCreated()->json('data.id');
        $secret = Project::allTenants()->find($project['id'])->webhook_secret;
        $body = json_encode(['ref' => 'refs/heads/develop', 'repository' => ['clone_url' => 'https://github.com/Acme/shop']]);
        $sign = fn ($b, $s) => ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $b, $s), 'Content-Type' => 'application/json', 'X-GitHub-Event' => 'push'];

        $this->call('POST', "/api/v1/hooks/git/{$project['id']}", [], [], [], $this->server_($sign($body, 'wrong')), $body)->assertStatus(401);
        $ids = $this->call('POST', "/api/v1/hooks/git/{$project['id']}", [], [], [], $this->server_($sign($body, $secret)), $body)->assertStatus(202)->json('data.deployments');
        $this->assertCount(1, $ids);
        $this->assertSame($appId, Deployment::allTenants()->find($ids[0])->application_id);

        $other = json_encode(['ref' => 'refs/heads/main', 'repository' => ['clone_url' => 'https://github.com/acme/shop.git']]);
        $this->assertSame([], $this->call('POST', "/api/v1/hooks/git/{$project['id']}", [], [], [], $this->server_($sign($other, $secret)), $other)->json('data.deployments'));
        $this->call('POST', "/api/v1/hooks/git/{$project['id']}", [], [], [], ['HTTP_X_GITLAB_TOKEN' => $secret, 'CONTENT_TYPE' => 'application/json'], $body)->assertStatus(202);
    }

    private function server_(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $out[$k === 'Content-Type' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return $out;
    }

    public function test_idle_environments_sleep_and_wake(): void
    {
        Carbon::setTestNow('2026-11-02 10:00:00');
        [$token, $secret, $project, $server] = $this->setUpProject();
        $staging = $this->env($project, 'staging');
        $this->api($token)->patchJson("/api/v1/environments/{$staging['id']}", ['sleep_after_minutes' => 30])->assertOk();
        $appId = $this->api($token)->postJson('/api/v1/applications', ['name' => 'web', 'environment_id' => $staging['id'], 'source_type' => 'image', 'image' => 'acme/web:1', 'server_id' => $server->id, 'port' => 8080])->assertCreated()->json('data.id');
        $this->deploy($token, $secret, $appId);
        $this->heartbeat($secret);

        $this->artisan('environments:sleep')->assertSuccessful();
        $this->assertNull(Application::allTenants()->find($appId)->asleep_at, 'still inside the window');
        Carbon::setTestNow('2026-11-02 10:45:00');
        $this->heartbeat($secret);
        $this->artisan('environments:sleep')->assertSuccessful();
        $this->assertSame('sleeping', Application::allTenants()->find($appId)->status);
        $this->assertSame(['container.stop'], array_column($this->heartbeat($secret)['commands'], 'type'));

        $this->api($token)->postJson("/api/v1/environments/{$staging['id']}/wake")->assertOk()->assertJsonPath('data.woken', 1);
        $this->assertSame(['container.start'], array_column($this->heartbeat($secret)['commands'], 'type'));
        $this->assertSame('running', Application::allTenants()->find($appId)->status);
        Carbon::setTestNow();
    }

    public function test_pull_requests_open_update_and_close_preview_environments(): void
    {
        [$token, $agentSecret, $project, $server] = $this->setUpProject();
        $staging = $this->env($project, 'staging');
        $appId = $this->api($token)->postJson('/api/v1/applications', ['name' => 'web', 'environment_id' => $staging['id'], 'source_type' => 'image', 'image' => 'acme/web:1', 'server_id' => $server->id, 'port' => 8080,
            'env' => [['key' => 'MODE', 'value' => 'staging']]])->assertCreated()->json('data.id');
        $this->api($token)->patchJson("/api/v1/projects/{$project['id']}", ['preview_environment_id' => $staging['id']])->assertOk()->assertJsonPath('data.preview_environment_id', $staging['id']);
        $secret = Project::allTenants()->find($project['id'])->webhook_secret;
        $send = function (array $body) use ($project, $secret) {
            $json = json_encode($body);

            return $this->call('POST', "/api/v1/hooks/git/{$project['id']}", [], [], [], ['HTTP_X_GITHUB_EVENT' => 'pull_request', 'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, $secret)], $json);
        };

        $r = $send(['action' => 'opened', 'number' => 7, 'pull_request' => ['number' => 7, 'head' => ['ref' => 'feature/cart']]])->assertStatus(202)->json('data');
        $env = \App\Models\Environment::allTenants()->find($r['environment_id']);
        $this->assertSame(['PR #7', 'pr-7', 'preview', 'feature/cart', 7], [$env->name, $env->slug, $env->kind, $env->branch, $env->preview_number]);
        $preview = Application::allTenants()->where('environment_id', $env->id)->sole();
        $this->assertSame(['web-pr-7', 'web'], [$preview->name, $preview->service]);
        $this->assertSame(['https://web-pr-7.'.config('platform.domain')], $r['urls']);
        $this->assertCount(1, $r['deployments']);

        $again = $send(['action' => 'synchronize', 'number' => 7, 'pull_request' => ['number' => 7, 'head' => ['ref' => 'feature/cart']]])->assertStatus(202)->json('data');
        $this->assertSame($env->id, $again['environment_id'], 'a push redeploys the same preview');
        $send(['action' => 'labeled', 'number' => 7, 'pull_request' => ['number' => 7, 'head' => ['ref' => 'feature/cart']]])->assertOk()->assertJsonPath('data.ignored', 'labeled');

        $send(['action' => 'closed', 'number' => 7, 'pull_request' => ['number' => 7, 'head' => ['ref' => 'feature/cart']]])->assertOk()->assertJsonPath('data.closed', true);
        $this->assertNull(\App\Models\Environment::allTenants()->find($env->id));
        $this->assertSoftDeleted('applications', ['id' => $preview->id]);
        $this->assertFalse(\App\Models\Domain::allTenants()->where('name', 'web-pr-7.'.config('platform.domain'))->exists());
        $this->assertNotNull(Application::allTenants()->find($appId), 'the source environment is untouched');
    }

    public function test_preview_addresses_are_commented_on_the_pull_request(): void
    {
        [$token, , $project, $server] = $this->setUpProject();
        $staging = $this->env($project, 'staging');
        $this->api($token)->postJson('/api/v1/applications', ['name' => 'web', 'environment_id' => $staging['id'], 'source_type' => 'image', 'image' => 'acme/web:1', 'server_id' => $server->id, 'port' => 8080])->assertCreated();
        $res = $this->api($token)->patchJson("/api/v1/projects/{$project['id']}", ['preview_environment_id' => $staging['id'], 'preview_comment_token' => 'ghp_secret'])
            ->assertOk()->assertJsonPath('data.preview_comments', true);
        $this->assertStringNotContainsString('ghp_secret', $res->getContent());
        $posted = [];
        \Illuminate\Support\Facades\Http::fake(function ($r) use (&$posted) {
            $posted[] = [$r->url(), $r->header('Authorization')[0] ?? ($r->header('PRIVATE-TOKEN')[0] ?? null), $r['body']];

            return \Illuminate\Support\Facades\Http::response(['id' => 1], 201);
        });
        $secret = Project::allTenants()->find($project['id'])->webhook_secret;
        $send = function (array $body, array $headers) use ($project, $secret) {
            $json = json_encode($body);

            return $this->call('POST', "/api/v1/hooks/git/{$project['id']}", [], [], [], $headers + ['CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, $secret)], $json);
        };
        $gh = ['HTTP_X_GITHUB_EVENT' => 'pull_request'];
        $pr = ['number' => 9, 'pull_request' => ['number' => 9, 'head' => ['ref' => 'feature/x']], 'repository' => ['full_name' => 'acme/web', 'html_url' => 'https://github.com/acme/web']];

        $send(['action' => 'opened'] + $pr, $gh)->assertStatus(202)->assertJsonPath('data.commented', true);
        $this->assertSame('https://api.github.com/repos/acme/web/issues/9/comments', $posted[0][0]);
        $this->assertSame('Bearer ghp_secret', $posted[0][1]);
        $this->assertStringContainsString('https://web-pr-9.'.config('platform.domain'), $posted[0][2]);
        $send(['action' => 'synchronize'] + $pr, $gh)->assertStatus(202)->assertJsonPath('data.commented', false);
        $this->assertCount(1, $posted, 'one comment per opened pull request, not one per push');
        $this->api($token)->patchJson("/api/v1/projects/{$project['id']}", ['preview_comment_token' => null])->assertOk()->assertJsonPath('data.preview_comments', false);
        $this->assertSame(0, \App\Models\Secret::allTenants()->where('name', 'like', 'preview-comments-%')->count());

        // Gitea and GitLab address their own APIs.
        $gitea = \App\Services\PreviewCommentService::target(\Illuminate\Http\Request::create('/', 'POST', ['repository' => ['full_name' => 'acme/web', 'html_url' => 'https://git.acme.test/acme/web']], [], [], ['HTTP_X_GITEA_EVENT' => 'pull_request']), 4);
        $this->assertSame('https://git.acme.test/api/v1/repos/acme/web/issues/4/comments', $gitea['url']);
        $gitlab = \App\Services\PreviewCommentService::target(\Illuminate\Http\Request::create('/', 'POST', ['project' => ['id' => 42, 'web_url' => 'https://gitlab.acme.test/acme/web']], [], [], ['HTTP_X_GITLAB_EVENT' => 'Merge Request Hook']), 3);
        $this->assertSame('https://gitlab.acme.test/api/v4/projects/42/merge_requests/3/notes', $gitlab['url']);
    }

    public function test_a_request_to_a_sleeping_app_wakes_it(): void
    {
        config(['platform.drivers.certificates' => 'traefik']);
        [$token, $secret, $project, $server] = $this->setUpProject();
        $staging = $this->env($project, 'staging');
        $appId = $this->api($token)->postJson('/api/v1/applications', ['name' => 'web', 'environment_id' => $staging['id'], 'source_type' => 'image', 'image' => 'acme/web:1', 'server_id' => $server->id, 'port' => 8080])->assertCreated()->json('data.id');
        $this->api($token)->postJson('/api/v1/domains', ['name' => 'web.acme.test', 'application_id' => $appId, 'target_port' => 8080])->assertCreated();
        \App\Models\Domain::allTenants()->where('name', 'web.acme.test')->update(['dns_status' => 'verified']);
        $this->deploy($token, $secret, $appId);
        $this->heartbeat($secret);
        Application::allTenants()->whereKey($appId)->update(['asleep_at' => now(), 'status' => 'sleeping']);
        $pages = app(\App\Services\PublicPagesService::class);
        $this->assertSame('sleeping', $pages->takeover(\App\Models\Domain::allTenants()->where('name', 'web.acme.test')->first()));
        $this->assertStringContainsString('<img src="/__wake"', $pages->render('sleeping'));
        $this->assertStringContainsString('location = /__page/sleeping', $pages->nginxConf());

        $this->call('GET', '/api/v1/hooks/wake', [], [], [], ['HTTP_X_FORWARDED_HOST' => 'web.acme.test'])->assertStatus(202)->assertJsonPath('data.woken', true);
        $app = Application::allTenants()->find($appId);
        $this->assertSame(['running', null], [$app->status, $app->asleep_at]);
        $this->assertContains('container.start', array_column($this->heartbeat($secret)['commands'], 'type'));
        $this->call('POST', '/api/v1/hooks/wake', [], [], [], ['HTTP_X_FORWARDED_HOST' => 'web.acme.test'])->assertStatus(202)->assertJsonPath('data.woken', false);
        $this->call('POST', '/api/v1/hooks/wake', [], [], [], ['HTTP_X_FORWARDED_HOST' => 'nope.example'])->assertJsonPath('data.woken', false);
    }
}
