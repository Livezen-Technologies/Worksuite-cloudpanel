<?php

namespace Tests\Feature;

use App\Models\NotificationChannel;
use App\Models\Threat;
use App\Models\Volume;
use App\Services\AntivirusService;
use App\Support\Tenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** SRS 4.25: scan schedule (AV-3), WordPress core cleaning (AV-6), security score (AV-13) and notifications (AV-14). */
class AntivirusOperationsTest extends TestCase
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

    private function scanResult(string $secret, array $threats): void
    {
        $command = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'av.scan');
        $this->commandResult($secret, $command['id'], 'succeeded', ['output' => ['threats' => $threats]])->assertOk();
    }

    private const SHELL = ['path' => 'wp-includes/load.php', 'signature' => 'YARA.php_eval_post.UNOFFICIAL', 'sha256' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'size' => 64];

    public function test_scans_start_on_each_organisations_schedule_once_per_period(): void
    {
        [$org, $token, $secret] = $this->setUpVolume();
        $av = app(AntivirusService::class);
        $sunday = Carbon::parse('2026-11-01 02:05'); // a Sunday

        $this->assertSame(0, $av->scanDue($sunday->copy()->setTime(1, 50)), 'not before the time');
        $this->assertSame(1, $av->scanDue($sunday));
        $this->assertSame(0, $av->scanDue($sunday->copy()->addMinutes(15)), 'once per week');
        $this->assertSame(0, $av->scanDue(Carbon::parse('2026-11-02 02:05')), 'weekly means Sunday only');

        $this->api($token)->patchJson('/api/v1/settings', ['antivirus' => ['schedule' => 'daily', 'time' => '03:30']])->assertOk()
            ->assertJsonPath('data.antivirus.schedule', 'daily')->assertJsonPath('data.antivirus.time', '03:30');
        $this->assertSame(1, $av->scanDue(Carbon::parse('2026-11-02 03:31')));
        $this->api($token)->patchJson('/api/v1/settings', ['antivirus' => ['schedule' => 'off']])->assertOk();
        $this->assertSame(0, $av->scanDue(Carbon::parse('2026-11-03 03:31')));
        $this->api($token)->patchJson('/api/v1/settings', ['antivirus' => ['schedule' => 'hourly']])->assertStatus(422);
        $this->assertCount(2, collect($this->heartbeat($secret)['commands'])->where('type', 'av.scan'));
    }

    public function test_new_detections_notify_the_owning_organisation_once_per_scan(): void
    {
        Http::fake();
        [$org, $token, $secret, $volume] = $this->setUpVolume();
        app(Tenant::class)->as($org->id, fn () => NotificationChannel::create(['name' => 'Ops', 'type' => 'webhook', 'config' => ['url' => 'https://hooks.example.test/av'], 'enabled' => true]));

        $this->api($token)->postJson('/api/v1/antivirus/scan', ['volume_id' => $volume->id])->assertStatus(202);
        $this->scanResult($secret, [self::SHELL, ['path' => 'x.php'] + self::SHELL]);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r['title'] === '2 malware detections on acme_blog_html' && str_contains($r['message'], 'quarantined automatically'));

        // The same files found again are not news.
        $this->api($token)->postJson('/api/v1/antivirus/scan', ['volume_id' => $volume->id])->assertStatus(202);
        $this->scanResult($secret, [self::SHELL]);
        Http::assertSentCount(1);
    }

    public function test_security_score_lists_what_to_fix(): void
    {
        [$org, $token, $secret, $volume] = $this->setUpVolume();
        $fresh = $this->api($token)->getJson('/api/v1/antivirus/score')->assertOk()->json('data');
        $this->assertSame(['Run a first scan.'], array_values(array_diff(array_column($fresh['volumes'][0]['fixes'], 'fix'), array_column($fresh['fixes'], 'fix'))));

        config(['platform.antivirus.auto_quarantine' => false]);
        $this->api($token)->postJson('/api/v1/antivirus/scan', ['volume_id' => $volume->id])->assertStatus(202);
        $this->scanResult($secret, [self::SHELL]);
        $after = $this->api($token)->getJson('/api/v1/antivirus/score')->json('data');
        $this->assertSame($fresh['volumes'][0]['score'] + 15 - 30, $after['volumes'][0]['score']);
        $this->assertContains('Quarantine or clean the 1 infected file.', array_column($after['volumes'][0]['fixes'], 'fix'));
        $this->assertSame($after['volumes'][0]['score'], $after['score']);
    }

    public function test_a_wordpress_core_file_is_replaced_with_the_verified_official_copy(): void
    {
        Storage::fake('local');
        $official = "<?php\n// official load.php\n";
        Http::fake([
            'api.wordpress.org/core/checksums/*' => Http::response(['checksums' => ['wp-includes/load.php' => md5($official)]]),
            'core.svn.wordpress.org/tags/6.8.3/wp-includes/load.php' => Http::response($official),
        ]);
        [, $token, $secret, $volume] = $this->setUpVolume();
        $this->api($token)->postJson('/api/v1/antivirus/scan', ['volume_id' => $volume->id])->assertStatus(202);
        $this->scanResult($secret, [self::SHELL]);
        $threat = Threat::allTenants()->firstOrFail();
        $this->heartbeat($secret); // the auto-quarantine command
        $threat->update(['status' => 'quarantined']);

        $jobId = $this->api($token)->postJson("/api/v1/antivirus/threats/{$threat->id}/clean")->assertStatus(202)->json('data.job_id');
        $read = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'fs.read');
        $this->assertSame('wp-includes/version.php', $read['payload']['path']);
        $this->commandResult($secret, $read['id'], 'succeeded', ['output' => ['content' => "<?php\n\$wp_version = '6.8.3';\n"]])->assertOk();

        $upload = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'fs.upload');
        $this->assertSame([['wp-includes/load.php'], true], [array_column($upload['payload']['files'], 'path'), $upload['payload']['overwrite']]);
        $url = substr($upload['payload']['files'][0]['url'], strlen(rtrim(config('backups.agent_api_url'), '/')));
        $this->withHeaders(['Authorization' => ''])->get($url)->assertOk()->assertStreamedContent($official);
        $this->commandResult($secret, $upload['id'], 'succeeded', ['output' => ['files' => 1]])->assertOk();

        $this->api($token)->getJson("/api/v1/jobs/{$jobId}")->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.result.version', '6.8.3');
        $this->assertSame('cleaned', $threat->fresh()->status);
        $this->assertSame([], Storage::disk('local')->allFiles('file-transfers'));
    }

    public function test_only_core_files_can_be_cleaned_and_a_bad_download_is_refused(): void
    {
        Http::fake([
            'api.wordpress.org/core/checksums/*' => Http::response(['checksums' => ['wp-includes/load.php' => md5('the real one')]]),
            'core.svn.wordpress.org/*' => Http::response('tampered'),
        ]);
        [, $token, $secret, $volume] = $this->setUpVolume();
        config(['platform.antivirus.auto_quarantine' => false]);
        $this->api($token)->postJson('/api/v1/antivirus/scan', ['volume_id' => $volume->id])->assertStatus(202);
        $this->scanResult($secret, [self::SHELL, ['path' => 'wp-content/uploads/shell.php'] + self::SHELL]);
        $upload = Threat::allTenants()->where('path', 'wp-content/uploads/shell.php')->firstOrFail();
        $this->api($token)->postJson("/api/v1/antivirus/threats/{$upload->id}/clean")->assertStatus(409)
            ->assertJsonPath('error.message', 'Only WordPress core files can be cleaned automatically. Restore this file from a backup.');

        $core = Threat::allTenants()->where('path', 'wp-includes/load.php')->firstOrFail();
        $jobId = $this->api($token)->postJson("/api/v1/antivirus/threats/{$core->id}/clean")->assertStatus(202)->json('data.job_id');
        $read = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'fs.read');
        $this->commandResult($secret, $read['id'], 'succeeded', ['output' => ['content' => "<?php \$wp_version = '6.8.3';"]])->assertOk();
        $this->api($token)->getJson("/api/v1/jobs/{$jobId}")->assertJsonPath('data.status', 'failed');
        $this->assertSame([], collect($this->heartbeat($secret)['commands'])->where('type', 'fs.upload')->all());
        $this->assertSame('infected', $core->fresh()->status);
    }
}
