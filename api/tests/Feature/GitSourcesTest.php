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

    public function test_customers_host_a_private_repository_through_their_connection(): void
    {
        [, , $token] = $this->org();
        $cred = 'fake-'.str_repeat('c', 12);
        Http::fake([
            'api.github.com/user' => Http::response(['login' => 'shop-owner']),
            'api.github.com/repos/me/shop/branches/main' => Http::response(['commit' => ['sha' => str_repeat('ab', 20)]]),
            'api.github.com/repos/me/shop/branches/nope' => Http::response([], 404),
        ]);
        $c = $this->api($token)->postJson('/api/v1/app/git/connections', ['provider' => 'github', 'token' => $cred])->assertCreated()->json('data');
        $check = fn (array $src) => $this->api($token)->postJson('/api/v1/app/apps/validate-source', ['source' => ['type' => 'git'] + $src]);

        $check(['repo' => 'https://github.com/me/shop.git', 'branch' => 'main', 'git_connection_id' => $c['id']])->assertOk()
            ->assertJsonPath('data.ok', true)->assertJsonPath('data.commit', str_repeat('ab', 20));
        $check(['repo' => 'https://github.com/me/shop', 'branch' => 'nope', 'git_connection_id' => $c['id']])->assertJsonPath('data.ok', false);
        // The token only ever goes to github.com.
        $check(['repo' => 'https://evil.example.com/me/shop.git', 'branch' => 'main', 'git_connection_id' => $c['id']])->assertJsonPath('data.ok', false);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'evil.example.com'));

        $key = $this->api($token)->postJson('/api/v1/app/git/keys', ['name' => 'shop-key'])->assertCreated()->json('data.id');
        $check(['repo' => 'git@github.com:me/shop.git', 'branch' => 'main', 'git_ssh_key_id' => $key])->assertJsonPath('data.ok', true);
        $check(['repo' => 'https://github.com/me/shop.git', 'branch' => 'main', 'git_ssh_key_id' => $key])->assertJsonPath('data.ok', false);

        // Another customer's connection is unknown.
        [, , $other] = $this->org();
        $this->api($other)->postJson('/api/v1/app/apps/validate-source', ['source' => ['type' => 'git', 'repo' => 'https://github.com/me/shop.git', 'git_connection_id' => $c['id']]])->assertJsonPath('data.ok', false);
    }

    public function test_an_admin_app_cannot_send_a_connection_token_to_another_host(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        Http::fake(['api.github.com/user' => Http::response(['login' => 'acme'])]);
        $c = $this->api($token)->postJson('/api/v1/git/connections', ['provider' => 'github', 'token' => 'fake-'.str_repeat('d', 12)])->json('data.id');
        $this->api($token)->postJson('/api/v1/applications', ['name' => 'leak', 'source_type' => 'git', 'server_id' => $server->id, 'port' => 80,
            'source' => ['repo' => 'https://evil.example.com/a/b.git', 'branch' => 'main', 'git_connection_id' => $c]])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['source.repo']]]);
        $this->assertSame(['github.com', 'acme/shop'], \App\Services\GitProviderService::parseRepo('git@github.com:acme/shop.git'));
        $this->assertSame(['gitlab.example.com', 'grp/sub/app'], \App\Services\GitProviderService::parseRepo('ssh://git@gitlab.example.com:2222/grp/sub/app.git'));
        $this->assertNull(\App\Services\GitProviderService::parseRepo('https://github.com/../etc'));
    }

    public function test_sign_in_with_github_creates_a_connection_once(): void
    {
        [$user, , $token] = $this->org();
        $this->api($token)->getJson('/api/v1/git')->assertJsonPath('data.oauth.github', false);
        $this->api($token)->postJson('/api/v1/git/oauth/github/start')->assertStatus(409);

        config(['platform.git_oauth.github.client_id' => 'cid-1', 'platform.git_oauth.github.client_secret' => 'fake-'.str_repeat('s', 12), 'platform.panel_url' => 'https://panel.test']);
        $url = $this->api($token)->postJson('/api/v1/git/oauth/github/start')->assertOk()->json('data.url');
        $this->assertStringStartsWith('https://github.com/login/oauth/authorize?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('cid-1', $q['client_id']);
        $this->assertSame(url('/api/v1/git/oauth/github/callback'), $q['redirect_uri']);

        $granted = 'fake-'.str_repeat('o', 12);
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response(['access_token' => $granted, 'token_type' => 'bearer']),
            'api.github.com/user' => Http::response(['login' => 'oauth-user']),
        ]);
        app(\App\Support\Tenant::class)->set(null);
        $this->get('/api/v1/git/oauth/github/callback?code=abc&state='.rawurlencode($q['state']))->assertRedirect('https://panel.test/admin/git?connected=github');
        $conn = GitConnection::allTenants()->sole();
        $this->assertSame($user->current_organization_id, $conn->organization_id);
        $this->assertSame('oauth-user', $conn->account);
        $this->assertSame($granted, $conn->token);

        // The same state can't be used twice, and a forged one is refused.
        $this->get('/api/v1/git/oauth/github/callback?code=abc&state='.rawurlencode($q['state']))->assertRedirect();
        $this->get('/api/v1/git/oauth/github/callback?code=abc&state=forged')->assertRedirect();
        $this->assertSame(1, GitConnection::allTenants()->count());
    }
}
