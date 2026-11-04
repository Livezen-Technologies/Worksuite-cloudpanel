<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Domain;
use Tests\TestCase;

/** The New application wizard: preflight checks, then create with a domain and a background deploy. */
class NewApplicationWizardTest extends TestCase
{
    private function checks(array $res): array
    {
        return collect($res['checks'])->keyBy('key')->map(fn ($c) => $c['status'])->all();
    }

    public function test_preflight_checks_source_build_and_deploy_without_creating_anything(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);

        $ok = $this->api($token)->postJson('/api/v1/applications/preflight', [
            'name' => 'api', 'source_type' => 'git', 'source' => ['repo' => 'https://git.example.com/acme/api.git', 'branch' => 'main', 'builder' => 'auto', 'git_token' => 'tok_secret'],
            'port' => 8080, 'domain_mode' => 'auto',
        ])->assertOk()->json('data');
        $this->assertTrue($ok['ok'], json_encode($ok['checks']));
        $this->assertSame(['name' => 'pass', 'repo' => 'pass', 'builder' => 'pass', 'registry' => 'warn', 'server' => 'pass', 'domain' => 'pass'], $this->checks($ok));
        $this->assertSame($server->id, $ok['detected']['server']['id'], 'automatic placement names the server it would pick');
        $this->assertStringNotContainsString('tok_secret', json_encode($ok), 'the token is used to test access, never echoed');

        $bad = $this->api($token)->postJson('/api/v1/applications/preflight', [
            'name' => 'Bad Name', 'source_type' => 'image', 'image' => 'acme/missing:1', 'domain_mode' => 'auto',
        ])->assertOk()->json('data');
        $this->assertFalse($bad['ok']);
        $this->assertSame('fail', $this->checks($bad)['name']);
        $this->assertSame('fail', $this->checks($bad)['image']);
        $this->assertSame('fail', $this->checks($bad)['domain'], 'a public address needs a port');
        $this->assertSame(0, Application::count());
    }

    public function test_preflight_reports_no_capacity_when_no_server_is_online(): void
    {
        [, , $token] = $this->org();
        $res = $this->api($token)->postJson('/api/v1/applications/preflight', ['name' => 'web', 'source_type' => 'image', 'image' => 'nginx:1.27'])->assertOk()->json('data');
        $this->assertSame('fail', $this->checks($res)['server']);
    }

    public function test_create_adds_the_domain_and_starts_the_first_deployment_as_a_job(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);

        $res = $this->api($token)->postJson('/api/v1/applications', [
            'name' => 'web', 'source_type' => 'image', 'image' => 'nginx:1.27', 'port' => 80, 'environment' => 'staging',
            'domain_mode' => 'auto', 'deploy' => true, 'env' => [['key' => 'APP_ENV', 'value' => 'staging']],
        ])->assertCreated()->json('data');
        $this->assertNotEmpty($res['job_id']);
        $this->assertNotEmpty($res['deployment_id']);
        $this->assertMatchesRegularExpression('#^https://web-[a-z0-9]{6}\.#', $res['url']);
        $this->assertTrue(Domain::where('application_id', $res['id'])->where('platform_subdomain', true)->exists());

        // A public address without a port is refused before anything is created.
        $this->api($token)->postJson('/api/v1/applications', ['name' => 'noport', 'source_type' => 'image', 'image' => 'nginx:1.27', 'domain_mode' => 'auto'])
            ->assertStatus(422)->assertJsonPath('error.fields.port.0', 'A public address needs the port the app listens on.');
        $this->assertFalse(Application::where('name', 'noport')->exists());
    }
}
