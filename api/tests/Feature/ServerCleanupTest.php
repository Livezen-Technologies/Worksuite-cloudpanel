<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\CleanupRun;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** CLN-1..5: policy levels, disk-threshold runs, protected releases, history and alerts. */
class ServerCleanupTest extends TestCase
{
    private function hot(string $secret, float $disk): array
    {
        return $this->heartbeat($secret, ['stats' => ['cpu_pct' => 10, 'ram_pct' => 30, 'disk_pct' => $disk, 'net_rx' => 0, 'net_tx' => 0]]);
    }

    public function test_manual_cleanup_protects_the_last_good_releases_and_records_history(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $appId = $this->api($token)->postJson('/api/v1/applications', ['name' => 'shop', 'source_type' => 'image', 'image' => 'ghcr.io/acme/shop:1.0', 'server_id' => $server->id, 'port' => 8080])->assertCreated()->json('data.id');
        $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->assertStatus(202);
        $create = $this->heartbeat($secret)['commands'][0];
        $this->commandResult($secret, $create['id'], 'succeeded', ['output' => ['container_id' => 'c1']])->assertOk();
        $this->passHealthChecks($secret);

        $page = $this->api($token)->getJson("/api/v1/servers/{$server->id}/cleanup")->assertOk()->json('data');
        $this->assertSame(['auto' => true, 'level' => 'safe', 'disk_threshold' => 85, 'keep_releases' => 2], $page['policy']);
        $this->assertContains('ghcr.io/acme/shop:1.0', $page['protected']);

        $this->api($token)->postJson("/api/v1/servers/{$server->id}/cleanup", ['level' => 'nuke'])->assertStatus(422);
        $this->api($token)->postJson("/api/v1/servers/{$server->id}/cleanup", ['level' => 'standard'])->assertStatus(202);
        $cmd = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'server.cleanup');
        $this->assertSame('standard', $cmd['payload']['level']);
        $this->assertContains('ghcr.io/acme/shop:1.0', $cmd['payload']['protect']);
        $this->commandResult($secret, $cmd['id'], 'succeeded', ['output' => ['freed_bytes' => 3 * 1024 * 1024 * 1024, 'images' => ['old:1', 'old:2'], 'dangling_images' => 1, 'containers' => 2, 'protected' => ['ghcr.io/acme/shop:1.0']]])->assertOk();

        $run = $this->api($token)->getJson("/api/v1/servers/{$server->id}/cleanup")->json('data.runs.0');
        $this->assertSame(['succeeded', 'manual', 'standard'], [$run['status'], $run['trigger'], $run['level']]);
        $this->assertSame('Standard: 3 GB freed, 3 images, 2 containers removed, 1 protected', $run['message']);

        $this->api($token)->patchJson("/api/v1/servers/{$server->id}/cleanup", ['level' => 'aggressive', 'disk_threshold' => 90, 'keep_releases' => 3])
            ->assertOk()->assertJsonPath('data.policy.level', 'aggressive')->assertJsonPath('data.policy.disk_threshold', 90);
        [, , $other] = $this->org();
        $this->api($other)->getJson("/api/v1/servers/{$server->id}/cleanup")->assertNotFound();
    }

    public function test_disk_over_the_threshold_starts_one_cleanup_then_alerts_if_disk_stays_high(): void
    {
        Carbon::setTestNow('2026-11-02 10:00:00');
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->hot($secret, 70);
        $this->artisan('cleanup:evaluate')->assertSuccessful();
        $this->assertSame(0, CleanupRun::allTenants()->count(), 'below the threshold nothing runs');

        $this->hot($secret, 91);
        $this->artisan('cleanup:evaluate')->assertSuccessful();
        $this->artisan('cleanup:evaluate')->assertSuccessful();
        $this->assertSame(1, CleanupRun::allTenants()->where('trigger', 'disk')->count(), 'one run per cooldown');
        $cmd = collect($this->hot($secret, 91)['commands'])->firstWhere('type', 'server.cleanup');
        $this->assertSame('safe', $cmd['payload']['level']);
        $this->commandResult($secret, $cmd['id'], 'succeeded', ['output' => ['freed_bytes' => 1024]])->assertOk();

        Carbon::setTestNow('2026-11-02 10:10:00');
        $this->hot($secret, 90);
        $this->artisan('cleanup:evaluate')->assertSuccessful();
        $this->artisan('cleanup:evaluate')->assertSuccessful();
        $alerts = Alert::allTenants()->where('resource_id', $server->id)->get();
        $this->assertCount(1, $alerts);
        $this->assertStringStartsWith('Disk still at 90', $alerts[0]->title);

        // A failed cleanup alerts right away.
        Carbon::setTestNow('2026-11-02 11:30:00');
        $this->hot($secret, 95);
        $this->artisan('cleanup:evaluate')->assertSuccessful();
        $cmd = collect($this->hot($secret, 95)['commands'])->firstWhere('type', 'server.cleanup');
        $this->commandResult($secret, $cmd['id'], 'failed', ['message' => 'docker daemon unreachable'])->assertOk();
        $this->assertTrue(Alert::allTenants()->where('title', 'like', 'Cleanup failed%')->where('severity', 'critical')->exists());
        Carbon::setTestNow();
    }
}
