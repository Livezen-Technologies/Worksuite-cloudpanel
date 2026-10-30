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
}
