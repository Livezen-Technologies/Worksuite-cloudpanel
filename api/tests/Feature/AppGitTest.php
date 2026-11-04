<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\SshKey;
use Tests\TestCase;

/** GIT-TAB: set up an app to deploy from Git on its own, from one tab. */
class AppGitTest extends TestCase
{
    private function setUpApp(): array
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        $this->enrollAgent($server, $token);
        $project = $this->api($token)->postJson('/api/v1/projects', ['name' => 'Shop'])->assertCreated()->json('data');
        $prod = collect($project['environments'])->firstWhere('kind', 'production');
        $app = $this->api($token)->postJson('/api/v1/applications', ['name' => 'web', 'environment_id' => $prod['id'], 'source_type' => 'image', 'image' => 'nginx:1', 'server_id' => $server->id, 'port' => 80])
            ->assertCreated()->json('data');

        return [$token, $app['id'], $project];
    }

    public function test_generate_a_key_connect_a_repo_test_it_and_deploy_on_push(): void
    {
        [$token, $id, $project] = $this->setUpApp();
        $s = $this->api($token)->getJson("/api/v1/applications/{$id}/git")->assertOk()->json('data');
        $this->assertSame([null, null, 'Shop', true], [$s['repo'], $s['deploy_key'], $s['project']['name'], $s['deploy_on_push']]);
        $this->assertStringEndsWith("/api/v1/hooks/git/{$project['id']}", $s['webhook']['url']);

        // A deploy key made for this app: only the public half is ever shown.
        $s = $this->api($token)->postJson("/api/v1/applications/{$id}/git/key")->assertCreated()->json('data');
        $this->assertSame('web-deploy', $s['deploy_key']['name']);
        $this->assertStringStartsWith('ssh-ed25519 ', $s['deploy_key']['public_key']);
        $this->assertStringNotContainsString('PRIVATE KEY', json_encode($s));
        $this->assertSame('web-deploy-2', $this->api($token)->postJson("/api/v1/applications/{$id}/git/key")->json('data.deploy_key.name'));
        $keyId = $s['deploy_key']['id'];

        // An SSH address without a key cannot be tested; with the key it can.
        $this->api($token)->postJson("/api/v1/applications/{$id}/git/test", ['repo' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'git_ssh_key_id' => null])
            ->assertOk()->assertJsonPath('data.ok', false)->assertJsonPath('data.message', fn ($m) => str_contains($m, 'deploy key'));
        $this->api($token)->postJson("/api/v1/applications/{$id}/git/test", ['repo' => 'git@github.com:acme/denied.git', 'branch' => 'main', 'git_ssh_key_id' => $keyId])
            ->assertOk()->assertJsonPath('data.ok', false);
        $this->api($token)->postJson("/api/v1/applications/{$id}/git/test", ['repo' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'git_ssh_key_id' => $keyId])
            ->assertOk()->assertJsonPath('data.ok', true);

        // Save: the image app now builds from the repository.
        $this->api($token)->patchJson("/api/v1/applications/{$id}/git", ['repo' => 'not a repo', 'branch' => 'main', 'deploy_on_push' => true])->assertStatus(422);
        $this->api($token)->patchJson("/api/v1/applications/{$id}/git", ['repo' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'git_ssh_key_id' => '00000000-0000-0000-0000-000000000000', 'deploy_on_push' => true])->assertStatus(422);
        $s = $this->api($token)->patchJson("/api/v1/applications/{$id}/git", ['repo' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'git_ssh_key_id' => $keyId, 'deploy_on_push' => true])->assertOk()->json('data');
        $this->assertSame(['git', 'git@github.com:acme/shop.git', 'main', $keyId], [$s['source_type'], $s['repo'], $s['branch'], $s['deploy_key']['id']]);
        $this->assertNotNull(SshKey::allTenants()->find($keyId));

        // A signed push to the project webhook deploys it; with the switch off it does not.
        $secret = Project::allTenants()->find($project['id'])->webhook_secret;
        $push = function () use ($project, $secret) {
            $body = json_encode(['ref' => 'refs/heads/main', 'repository' => ['clone_url' => 'https://github.com/acme/shop.git', 'full_name' => 'acme/shop']]);
            $h = ['HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $secret), 'CONTENT_TYPE' => 'application/json', 'HTTP_X-GitHub-Event' => 'push'];

            return $this->call('POST', "/api/v1/hooks/git/{$project['id']}", [], [], [], $h, $body);
        };
        $push()->assertStatus(202);
        $this->assertSame(1, Deployment::allTenants()->where('application_id', $id)->where('source', 'webhook')->count());
        $this->api($token)->patchJson("/api/v1/applications/{$id}/git", ['repo' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'git_ssh_key_id' => $keyId, 'deploy_on_push' => false])->assertOk()->assertJsonPath('data.deploy_on_push', false);
        $push();
        $this->assertSame(1, Deployment::allTenants()->where('application_id', $id)->where('source', 'webhook')->count());
        $this->assertNotEmpty($this->api($token)->getJson("/api/v1/applications/{$id}/git")->json('data.deliveries'));

        // New webhook secret, shown once.
        $new = $this->api($token)->postJson("/api/v1/applications/{$id}/git/webhook-secret")->assertOk()->json('data.webhook_secret');
        $this->assertSame($new, Project::allTenants()->find($project['id'])->webhook_secret);
        $this->assertArrayNotHasKey('webhook_secret', $this->api($token)->getJson("/api/v1/applications/{$id}/git")->json('data.webhook'));
    }

    public function test_viewers_read_but_cannot_change_and_other_orgs_see_nothing(): void
    {
        [$token, $id] = $this->setUpApp();
        $org = \App\Models\Organization::find(Application::allTenants()->find($id)->organization_id);
        [, $viewer] = $this->member($org, 'viewer');
        $this->api($viewer)->getJson("/api/v1/applications/{$id}/git")->assertOk();
        $this->api($viewer)->postJson("/api/v1/applications/{$id}/git/key")->assertForbidden();
        $this->api($viewer)->patchJson("/api/v1/applications/{$id}/git", ['repo' => 'https://github.com/a/b', 'branch' => 'main', 'deploy_on_push' => true])->assertForbidden();
        [, , $other] = $this->org();
        $this->api($other)->getJson("/api/v1/applications/{$id}/git")->assertNotFound();
    }
}
