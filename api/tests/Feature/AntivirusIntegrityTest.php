<?php

namespace Tests\Feature;

use App\Models\Image;
use App\Models\IntegrityChange;
use App\Models\NotificationChannel;
use App\Models\Volume;
use App\Services\IntegrityService;
use App\Services\ScanService;
use App\Support\Tenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** SRS 4.25: file integrity monitoring (AV-10) and nightly image CVE scans with notifications (AV-11, AV-14). */
class AntivirusIntegrityTest extends TestCase
{
    private function setUpVolume(): array
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $volume = Volume::allTenants()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'name' => 'acme_blog_html', 'type' => 'volume', 'status' => 'active']);

        return [$org, $token, $secret, $volume, $server];
    }

    private function finish(string $secret, string $type, array $output): void
    {
        $c = collect($this->heartbeat($secret)['commands'])->firstWhere('type', $type);
        $this->assertNotNull($c, "expected a {$type} command");
        $this->commandResult($secret, $c['id'], 'succeeded', ['output' => $output])->assertOk();
    }

    public function test_changes_since_the_baseline_are_listed_until_accepted(): void
    {
        Http::fake();
        [$org, $token, $secret, $volume] = $this->setUpVolume();
        app(Tenant::class)->as($org->id, fn () => NotificationChannel::create(['name' => 'Ops', 'type' => 'webhook', 'config' => ['url' => 'https://hooks.example.test/x'], 'enabled' => true]));
        $base = "/api/v1/antivirus/integrity/{$volume->id}";

        $this->api($token)->postJson("{$base}/check")->assertStatus(409);
        $this->api($token)->postJson("{$base}/baseline")->assertStatus(202);
        $this->finish($secret, 'fim.baseline', ['files' => 120, 'truncated' => false]);
        $this->assertNotNull($volume->fresh()->integrity_baseline_at);

        $this->api($token)->postJson("{$base}/check")->assertStatus(202);
        $this->finish($secret, 'fim.check', ['added' => ['wp-content/uploads/x.php'], 'changed' => ['wp-includes/load.php'], 'removed' => [], 'files' => 121]);
        $data = $this->api($token)->getJson('/api/v1/antivirus/integrity')->assertOk()->json('data');
        $this->assertSame(2, $data['volumes'][0]['open']);
        $this->assertEqualsCanonicalizing(['added', 'changed'], array_column($data['changes'], 'change'));
        Http::assertSent(fn (Request $r) => str_contains($r['title'], 'Code changed outside a deployment'));
        $this->assertContains('Review the 2 code files changed outside a deployment.', array_column($this->api($token)->getJson('/api/v1/antivirus/score')->json('data.volumes.0.fixes'), 'fix'));

        // The same changes on the next check are not new.
        app(IntegrityService::class)->checkAll();
        $this->finish($secret, 'fim.check', ['added' => ['wp-content/uploads/x.php'], 'changed' => ['wp-includes/load.php'], 'removed' => []]);
        $this->assertSame(2, IntegrityChange::allTenants()->count());
        Http::assertSentCount(1);

        $this->api($token)->postJson("{$base}/accept")->assertStatus(202);
        $this->assertSame(0, IntegrityChange::allTenants()->where('status', 'open')->count());
        $this->assertSame('fim.baseline', collect($this->heartbeat($secret)['commands'])->last()['type']);

        [, , $other] = $this->org();
        $this->api($other)->postJson("{$base}/baseline")->assertNotFound();
    }

    public function test_images_are_rescanned_nightly_and_new_critical_cves_notify(): void
    {
        Http::fake();
        config(['platform.drivers.scanner' => 'trivy']);
        [$org, , $secret, , $server] = $this->setUpVolume();
        app(Tenant::class)->as($org->id, fn () => NotificationChannel::create(['name' => 'Ops', 'type' => 'webhook', 'config' => ['url' => 'https://hooks.example.test/x'], 'enabled' => true]));
        $image = Image::allTenants()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'docker_id' => 'sha256:abc', 'tags' => ['nginx:1.25'], 'scan_status' => 'scanned', 'scan_result' => ['critical' => 0], 'scanned_at' => now()->subDays(2)]);
        Image::allTenants()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'docker_id' => 'sha256:def', 'tags' => ['redis:7'], 'scan_status' => 'scanned', 'scanned_at' => now()]);

        $this->assertSame(1, app(ScanService::class)->scanStale(), 'only the image not scanned today');
        $this->finish($secret, 'image.scan', ['scanner' => 'trivy', 'total' => 1, 'counts' => ['critical' => 1], 'vulnerabilities' => [['id' => 'CVE-2026-1', 'severity' => 'critical', 'package' => 'openssl']]]);
        $this->assertGreaterThan(0, $image->fresh()->scan_result['critical']);
        Http::assertSent(fn (Request $r) => $r['title'] === 'Critical vulnerabilities in nginx:1.25');

        config(['platform.drivers.scanner' => 'fake']);
        $this->assertSame(0, app(ScanService::class)->scanStale(), 'the fake driver has nothing to rescan');
    }
}
