<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Services\EmailTemplateService;
use Tests\TestCase;

/** First deployments reach staff for every app; customer deploy failures reach staff too; default branch is detected. */
class DeployNotificationsTest extends TestCase
{
    private function deployedApp(string $role = 'owner'): array
    {
        [, , $staffToken] = $this->org('super_admin');          // the platform organisation is the oldest
        [, $org, $token] = $this->org($role);
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $app = $this->api($token)->postJson('/api/v1/applications', ['name' => 'web', 'source_type' => 'image', 'image' => 'nginx:1.27', 'server_id' => $server->id, 'port' => 80, 'environment' => 'staging'])
            ->assertCreated()->json('data.id');
        $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $app])->assertSuccessful();

        return [$secret, $app];
    }

    public function test_the_first_successful_deployment_of_any_app_emails_staff(): void
    {
        $spy = $this->spy(EmailTemplateService::class);
        [$secret] = $this->deployedApp();
        $this->commandResult($secret, $this->heartbeat($secret)['commands'][0]['id'], 'succeeded', ['output' => ['container_id' => 'c1']]);
        $this->passHealthChecks($secret);

        $spy->shouldHaveReceived('notifyStaff')->withArgs(fn ($key, $vars) => $key === 'app_installed_admin' && $vars['app'] === 'web'
            && $vars['template'] === 'a Docker image' && str_contains($vars['logs_url'], '/admin/deployments/'))->once();
    }

    public function test_a_customer_deploy_failure_also_emails_staff(): void
    {
        $spy = $this->spy(EmailTemplateService::class);
        [$secret] = $this->deployedApp();
        $this->commandResult($secret, $this->heartbeat($secret)['commands'][0]['id'], 'failed', ['error' => 'image pull failed']);

        $spy->shouldHaveReceived('notifyStaff')->withArgs(fn ($key, $vars, $dedupe) => $key === 'deploy_failed' && $vars['app'] === 'web' && str_starts_with((string) $dedupe, 'failed:'))->once();
    }

    public function test_an_empty_branch_uses_the_repository_default(): void
    {
        [, , $token] = $this->org();
        $pre = $this->api($token)->postJson('/api/v1/applications/preflight', ['name' => 'old', 'source_type' => 'git', 'source' => ['repo' => 'https://git.example.com/acme/legacy.git']])->json('data');
        $this->assertSame('master', $pre['detected']['default_branch']);
        $this->assertStringContainsString('Branch master', collect($pre['checks'])->firstWhere('key', 'repo')['message']);

        $id = $this->api($token)->postJson('/api/v1/applications', ['name' => 'old', 'source_type' => 'git', 'source' => ['repo' => 'https://git.example.com/acme/legacy.git']])->assertCreated()->json('data.id');
        $this->assertSame('master', Application::find($id)->source['branch']);
        $id = $this->api($token)->postJson('/api/v1/applications', ['name' => 'pinned', 'source_type' => 'git', 'source' => ['repo' => 'https://git.example.com/acme/legacy.git', 'branch' => 'release']])->assertCreated()->json('data.id');
        $this->assertSame('release', Application::find($id)->source['branch'], 'a chosen branch is kept');
    }
}
