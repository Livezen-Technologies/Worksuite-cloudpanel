<?php

namespace Tests\Feature;

use App\Models\GitConnection;
use App\Models\Project;
use App\Models\SshKey;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** GIT: deploy keys, provider connections, builds over SSH and the webhook log. */
class GitSourcesTest extends TestCase
{
    private function fleet(): array
    {
        [, $org, $token] = $this->org();
        $target = $this->server($org, ['name' => 'prod-1']);
        [$targetSecret] = $this->enrollAgent($target, $token);
        $this->heartbeat($targetSecret);
        $builder = $this->server($org, ['name' => 'builder-1', 'environment' => 'staging']);
        [$builderSecret] = $this->enrollAgent($builder, $token);
        $this->heartbeat($builderSecret);
        $this->api($token)->patchJson("/api/v1/servers/{$builder->id}", ['roles' => ['builder']])->assertOk();

        return [$token, $org, $target, $builderSecret];
    }

    public function test_a_deploy_key_builds_an_ssh_repository(): void
    {
        [$token, , $target, $builderSecret] = $this->fleet();
        $key = $this->api($token)->postJson('/api/v1/git/keys', ['name' => 'deploy-shop'])->assertCreated()->json('data');
        $this->assertStringStartsWith('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5', $key['public_key']);
        $this->assertStringStartsWith('SHA256:', $key['fingerprint']);
        $this->assertArrayNotHasKey('private_key', $key);
        $this->api($token)->postJson('/api/v1/git/keys', ['name' => 'deploy-shop'])->assertStatus(409);
        $this->assertStringContainsString('BEGIN OPENSSH PRIVATE KEY', SshKey::allTenants()->sole()->private_key);

        $registry = $this->api($token)->postJson('/api/v1/registries', ['name' => 'reg', 'type' => 'private', 'url' => 'https://reg.example.com:5000', 'username' => 'ci', 'password' => 'pw'])->assertCreated()->json('data.id');
        $appId = $this->api($token)->postJson('/api/v1/applications', [
            'name' => 'shop', 'source_type' => 'git', 'server_id' => $target->id, 'port' => 8080, 'environment' => 'staging',
            'source' => ['repo' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'git_ssh_key_id' => $key['id'], 'push_registry_id' => $registry],
        ])->assertCreated()->json('data.id');
        $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->assertStatus(202);
        [$build] = $this->heartbeat($builderSecret)['commands'];
        $this->assertSame('git@github.com:acme/shop.git', $build['payload']['repo']);
        $this->assertStringContainsString('BEGIN OPENSSH PRIVATE KEY', $build['payload']['git_ssh_key']);

        $page = $this->api($token)->getJson('/api/v1/git')->assertOk()->json('data');
        $this->assertSame(['shop'], $page['keys'][0]['used_by']);
        $this->assertStringNotContainsString('PRIVATE KEY', json_encode($page));
        $this->api($token)->deleteJson("/api/v1/git/keys/{$key['id']}")->assertStatus(409);

        // Another organisation's key is unknown here.
        [, , $other] = $this->org();
        $theirs = $this->api($other)->postJson('/api/v1/git/keys', ['name' => 'theirs'])->json('data.id');
        $this->api($token)->patchJson("/api/v1/applications/{$appId}", ['source' => ['repo' => 'git@github.com:acme/shop.git', 'git_ssh_key_id' => $theirs]])->assertStatus(422);
        $this->api($other)->deleteJson("/api/v1/git/keys/{$key['id']}")->assertNotFound();
    }

    public function test_provider_connections_are_verified_and_list_repositories(): void
    {
        [, , $token] = $this->org();
        // Placeholder credentials, built at run time so secret scanners don't flag them.
        $gh = 'fake-'.str_repeat('g', 12);
        $gl = 'fake-'.str_repeat('l', 12);
        Http::fake([
            'api.github.com/user/repos*' => Http::response([['full_name' => 'acme/shop', 'clone_url' => 'https://github.com/acme/shop.git', 'ssh_url' => 'git@github.com:acme/shop.git', 'default_branch' => 'main', 'private' => true]]),
            'api.github.com/user' => Http::response(['login' => 'acme-bot']),
            'gitlab.com/api/v4/user' => Http::response(['message' => '401 Unauthorized'], 401),
        ]);
        $conn = $this->api($token)->postJson('/api/v1/git/connections', ['provider' => 'github', 'token' => $gh])->assertCreated()
            ->assertJsonPath('data.status', 'connected')->assertJsonPath('data.account', 'acme-bot')->json('data');
        $this->assertSame($gh, GitConnection::allTenants()->find($conn['id'])->token);
        $this->assertStringNotContainsString($gh, json_encode($this->api($token)->getJson('/api/v1/git')->json()));
        Http::assertSent(fn ($r) => $r->url() === 'https://api.github.com/user' && $r->hasHeader('Authorization', 'Bearer '.$gh));

        $this->api($token)->getJson("/api/v1/git/connections/{$conn['id']}/repositories")->assertOk()
            ->assertJsonPath('data.0.name', 'acme/shop')->assertJsonPath('data.0.ssh_url', 'git@github.com:acme/shop.git');

        $this->api($token)->postJson('/api/v1/git/connections', ['provider' => 'gitlab', 'token' => $gl])->assertCreated()
            ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error', 'The token was rejected.');

        // Self-hosted addresses must be public https.
        foreach (['http://git.example.com', 'https://127.0.0.1', 'https://10.0.0.5', 'https://169.254.169.254'] as $bad) {
            $this->api($token)->postJson('/api/v1/git/connections', ['provider' => 'gitea', 'base_url' => $bad, 'token' => $gl])->assertStatus(422);
        }
        $this->api($token)->postJson('/api/v1/git/connections', ['provider' => 'bitbucket', 'token' => $gl])->assertStatus(422); // needs a username
    }

    public function test_webhook_deliveries_are_logged(): void
    {
        [, , $token] = $this->org();
        $project = $this->api($token)->postJson('/api/v1/projects', ['name' => 'Shop'])->assertCreated()->json('data');
        $secret = Project::allTenants()->find($project['id'])->webhook_secret;
        $body = json_encode(['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'acme/shop', 'clone_url' => 'https://github.com/acme/shop.git']]);
        $send = fn (string $sig) => $this->call('POST', "/api/v1/hooks/git/{$project['id']}", [], [], [],
            ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $sig), 'HTTP_X_GITHUB_EVENT' => 'push', 'CONTENT_TYPE' => 'application/json'], $body);

        $send('wrong')->assertStatus(401);
        $send($secret)->assertStatus(202);
        $log = WebhookDelivery::allTenants()->orderBy('created_at')->get();
        $this->assertEqualsCanonicalizing(['rejected', 'ignored'], $log->pluck('outcome')->all());
        $this->assertSame('acme/shop', $log->first()->repo);
        $this->assertSame('github', $log->first()->provider);
        $this->assertCount(2, $this->api($token)->getJson('/api/v1/git')->json('data.deliveries'));
    }
}
