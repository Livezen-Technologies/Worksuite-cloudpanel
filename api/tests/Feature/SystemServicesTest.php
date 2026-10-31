<?php

namespace Tests\Feature;

use App\Models\Alert;
use Tests\TestCase;

/** SVC-1..5: list, act on, set up and create systemd services; watched services alert while down. */
class SystemServicesTest extends TestCase
{
    public function test_list_actions_setup_create_and_watch_alerts(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $base = "/api/v1/servers/{$server->id}/system-services";

        $this->api($token)->postJson("{$base}/refresh")->assertStatus(202);
        $cmd = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'svc.list');
        $this->commandResult($secret, $cmd['id'], 'succeeded', ['output' => ['services' => [
            ['unit' => 'nginx.service', 'active' => 'active', 'sub' => 'running', 'enabled' => 'enabled'],
            ['unit' => 'redis-server.service', 'active' => 'failed', 'sub' => 'failed', 'enabled' => 'enabled'],
        ]]])->assertOk();
        $page = $this->api($token)->getJson($base)->assertOk()->json('data');
        $this->assertSame([2, 1, 1], [$page['summary']['total'], $page['summary']['running'], $page['summary']['failed']]);
        $this->assertTrue(collect($page['catalog'])->firstWhere('key', 'nginx')['installed']);

        $this->api($token)->postJson("{$base}/action", ['unit' => 'ssh.service', 'action' => 'stop'])->assertStatus(409);
        $this->api($token)->postJson("{$base}/action", ['unit' => 'x;rm', 'action' => 'start'])->assertStatus(422);

        // Watching a failed service raises one alert; restarting it resolves the alert.
        $this->api($token)->patchJson("{$base}/watch", ['units' => ['redis-server.service', 'nginx.service']])->assertOk()->assertJsonPath('data.summary.watched_down', 1);
        $this->assertSame(1, Alert::allTenants()->where('status', 'open')->where('title', 'like', 'Service redis-server.service%')->count());
        $this->api($token)->postJson("{$base}/action", ['unit' => 'redis-server.service', 'action' => 'restart'])->assertStatus(202);
        $cmd = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'svc.action');
        $this->commandResult($secret, $cmd['id'], 'succeeded', ['output' => ['unit' => 'redis-server.service', 'active' => 'active', 'sub' => 'running']])->assertOk();
        $this->assertSame(0, Alert::allTenants()->where('status', 'open')->count());

        $this->api($token)->postJson("{$base}/install", ['key' => 'nope'])->assertStatus(422);
        $this->api($token)->postJson("{$base}/install", ['key' => 'postgresql'])->assertStatus(202);
        $this->api($token)->postJson($base, ['name' => 'Bad Name', 'exec_start' => '/bin/x'])->assertStatus(422);
        $this->api($token)->postJson($base, ['name' => 'worker', 'exec_start' => 'php artisan'])->assertStatus(422);
        $this->api($token)->postJson($base, ['name' => 'worker', 'exec_start' => '/usr/bin/php artisan queue:work', 'user' => 'root'])->assertStatus(422);
        $this->api($token)->postJson($base, ['name' => 'worker', 'exec_start' => '/usr/bin/php artisan queue:work', 'env' => ['APP_ENV' => 'production'], 'watch' => true])->assertStatus(202);
        $types = collect($this->heartbeat($secret)['commands'])->pluck('type')->all();
        $this->assertContains('svc.install', $types);
        $this->assertContains('svc.create', $types);
        $this->assertContains('ws-worker.service', $server->fresh()->services_watch);
        $this->api($token)->deleteJson($base, ['unit' => 'nginx.service'])->assertStatus(422);

        [, , $other] = $this->org();
        $this->api($other)->getJson($base)->assertNotFound();
    }
}
