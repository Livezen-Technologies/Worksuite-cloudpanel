<?php

namespace Tests\Feature;

use App\Models\AntivirusAllow;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Threat;
use App\Models\Volume;
use Tests\TestCase;

/** SRS 4.25: scans, the threat list, quarantine and false positives. */
class AntivirusTest extends TestCase
{
    private function setUpVolume(): array
    {
        [$user, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $volume = Volume::allTenants()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'name' => 'acme_blog_html', 'type' => 'volume', 'status' => 'active']);

        return [$org, $token, $secret, $volume, $user];
    }

    private function scanFinding(string $token, string $secret, Volume $volume, array $threats): array
    {
        $this->api($token)->postJson('/api/v1/antivirus/scan', ['volume_id' => $volume->id])->assertStatus(202);
        $command = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'av.scan');
        $this->commandResult($secret, $command['id'], 'succeeded', ['output' => ['threats' => $threats]])->assertOk();

        return $command;
    }

    private const SHELL = ['path' => 'wp-content/uploads/shell.php', 'signature' => 'YARA.dmp_php_request_exec.UNOFFICIAL', 'sha256' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'size' => 31];

    public function test_a_detection_becomes_a_threat_and_is_quarantined_automatically(): void
    {
        [$org, $token, $secret, $volume] = $this->setUpVolume();
        $scan = $this->scanFinding($token, $secret, $volume, [self::SHELL]);
        $this->assertSame([], $scan['payload']['allowlist']);

        $threat = Threat::allTenants()->sole();
        $this->assertSame($org->id, $threat->organization_id);
        $this->assertSame('infected', $threat->status);

        $quarantine = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'av.quarantine');
        $this->assertSame(['volume' => 'acme_blog_html', 'path' => self::SHELL['path'], 'id' => $threat->id], $quarantine['payload']);
        $this->commandResult($secret, $quarantine['id'], 'succeeded', ['output' => ['id' => $threat->id]])->assertOk();
        $this->assertSame('quarantined', $threat->fresh()->status);

        // The weekly scan finding the same file again must not open a second threat.
        $this->scanFinding($token, $secret, $volume, [self::SHELL]);
        $this->assertSame(1, Threat::allTenants()->count());

        $this->api($token)->getJson('/api/v1/antivirus')->assertOk()->assertJsonPath('data.quarantined', 1);
        $this->api($token)->getJson('/api/v1/antivirus/threats')->assertOk()->assertJsonPath('data.0.signature', self::SHELL['signature']);
    }

    public function test_not_a_threat_allowlists_the_hash_and_restores_the_file(): void
    {
        config(['platform.antivirus.auto_quarantine' => true]);
        [$org, $token, $secret, $volume] = $this->setUpVolume();
        $this->scanFinding($token, $secret, $volume, [self::SHELL]);
        $threat = Threat::allTenants()->sole();
        $q = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'av.quarantine');
        $this->commandResult($secret, $q['id'], 'succeeded', ['output' => []]);

        $this->api($token)->postJson("/api/v1/antivirus/threats/{$threat->id}/ignore")->assertStatus(202);
        $this->assertSame('ignored', $threat->fresh()->status);
        $this->assertTrue(AntivirusAllow::allTenants()->where('organization_id', $org->id)->where('sha256', self::SHELL['sha256'])->exists());
        $this->assertSame('av.restore', collect($this->heartbeat($secret)['commands'])->last()['type']);
        $this->assertSame(1, AuditLog::where('organization_id', $org->id)->where('action', 'antivirus.ignore')->count());

        // The next scan sends the allow-list, so the agent skips that file.
        $scan = $this->scanFinding($token, $secret, $volume, []);
        $this->assertSame([self::SHELL['sha256']], $scan['payload']['allowlist']);
    }

    public function test_actions_follow_the_threat_state(): void
    {
        config(['platform.antivirus.auto_quarantine' => false]);
        [, $token, $secret, $volume] = $this->setUpVolume();
        $this->scanFinding($token, $secret, $volume, [self::SHELL]);
        $threat = Threat::allTenants()->sole();
        $this->assertNull(collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'av.quarantine'), 'auto-quarantine off');

        $this->api($token)->postJson("/api/v1/antivirus/threats/{$threat->id}/restore")->assertStatus(409);
        $this->api($token)->postJson("/api/v1/antivirus/threats/{$threat->id}/quarantine")->assertStatus(202);
        $this->api($token)->postJson("/api/v1/antivirus/threats/{$threat->id}/explode")->assertNotFound();
    }

    public function test_threats_in_an_app_volume_belong_to_the_apps_owner(): void
    {
        [, $platformToken, $secret, $volume, ] = $this->setUpVolume();
        [, $customer, $customerToken] = $this->org();
        $volume->update(['name' => 'app-shop-data']);
        Application::allTenants()->create(['organization_id' => $customer->id, 'server_id' => $volume->server_id, 'name' => 'shop', 'source_type' => 'image', 'volume_path' => '/data', 'status' => 'running']);

        $this->scanFinding($platformToken, $secret, $volume, [self::SHELL]);
        $this->assertSame($customer->id, Threat::allTenants()->sole()->organization_id);
        $this->api($customerToken)->getJson('/api/v1/antivirus/threats')->assertOk()->assertJsonCount(1, 'data');
        $this->api($platformToken)->getJson('/api/v1/antivirus/threats')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_fleet_scan_is_for_platform_staff(): void
    {
        [, $token] = $this->setUpVolume();
        $this->api($token)->postJson('/api/v1/antivirus/scan')->assertForbidden();
    }
}
