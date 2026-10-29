<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Volume;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileManagerTest extends TestCase
{
    private function volume($org, $server): Volume
    {
        return Volume::allTenants()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'name' => 'acme_blog_html', 'type' => 'volume', 'status' => 'active']);
    }

    public function test_listing_runs_on_the_agent_and_returns_on_the_job(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $volume = $this->volume($org, $server);

        $jobId = $this->api($token)->getJson("/api/v1/volumes/{$volume->id}/files?path=/wp-content")->assertStatus(202)->json('data.job_id');

        $commands = $this->heartbeat($secret)['commands'];
        $this->assertCount(1, $commands);
        $this->assertSame('fs.list', $commands[0]['type']);
        $this->assertSame(['volume' => 'acme_blog_html', 'path' => '/wp-content'], $commands[0]['payload']);

        $entries = [['name' => 'themes', 'type' => 'dir', 'size' => 4096, 'mode' => '0755', 'modified' => '2026-10-29T10:00:00Z']];
        $this->commandResult($secret, $commands[0]['id'], 'succeeded', ['output' => ['path' => 'wp-content', 'entries' => $entries, 'truncated' => false]])->assertOk();

        $this->api($token)->getJson("/api/v1/jobs/{$jobId}")->assertOk()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.result.entries.0.name', 'themes');
    }

    public function test_writes_are_audited_with_the_path_but_never_the_content(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $volume = $this->volume($org, $server);

        $this->api($token)->putJson("/api/v1/volumes/{$volume->id}/files/content", ['path' => 'wp-config.php', 'content' => "define('DB_PASSWORD', 'hunter2');"])->assertStatus(202);

        $command = $this->heartbeat($secret)['commands'][0];
        $this->assertSame('fs.write', $command['type']);
        $log = AuditLog::where('organization_id', $org->id)->where('action', 'files.write')->sole();
        $this->assertSame('wp-config.php', $log->meta['path']);
        $this->assertSame(33, $log->meta['bytes']);
        $this->assertStringNotContainsString('hunter2', json_encode(AuditLog::where('organization_id', $org->id)->get()));
    }

    public function test_failures_name_the_problem_without_the_host_path(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $volume = $this->volume($org, $server);

        $jobId = $this->api($token)->getJson("/api/v1/volumes/{$volume->id}/files/content?path=../../etc/shadow")->assertStatus(202)->json('data.job_id');
        $command = $this->heartbeat($secret)['commands'][0];
        $this->commandResult($secret, $command['id'], 'failed', ['message' => 'openat /var/lib/docker/volumes/acme_blog_html/_data/link: path escapes from parent'])->assertOk();

        $job = $this->api($token)->getJson("/api/v1/jobs/{$jobId}")->assertOk()->json('data');
        $this->assertSame('failed', $job['status']);
        $this->assertSame('That path is outside the volume.', $job['error']);
    }

    public function test_names_are_validated_and_offline_servers_refused(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $volume = $this->volume($org, $server);

        foreach (['..', 'a/b', ''] as $bad) {
            $this->api($token)->postJson("/api/v1/volumes/{$volume->id}/files/rename", ['path' => 'index.php', 'new_name' => $bad])->assertStatus(422);
        }

        $server->forceFill(['last_heartbeat_at' => now()->subHour()])->save();
        $this->api($token)->getJson("/api/v1/volumes/{$volume->id}/files")->assertStatus(409);
    }

    public function test_viewers_can_browse_but_not_change_files(): void
    {
        [, $org, $token] = $this->org();
        [, $viewer] = $this->member($org, 'viewer');
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $volume = $this->volume($org, $server);

        $this->api($viewer)->getJson("/api/v1/volumes/{$volume->id}/files")->assertStatus(202);
        $this->api($viewer)->deleteJson("/api/v1/volumes/{$volume->id}/files", ['path' => 'index.php'])->assertForbidden();
        $this->api($viewer)->getJson("/api/v1/volumes/{$volume->id}/files/content?path=wp-config.php")->assertForbidden();
    }

    public function test_another_organisation_cannot_reach_the_volume(): void
    {
        [, $org, $token] = $this->org();
        [, , $other] = $this->org();
        $volume = $this->volume($org, $this->server($org));

        $this->api($other)->getJson("/api/v1/volumes/{$volume->id}/files")->assertNotFound();
    }

    /** The path and query of a signed agent URL, so the test client can call it. */
    private function local(string $url): string
    {
        return substr($url, strlen(rtrim(config('backups.agent_api_url'), '/')));
    }

    public function test_upload_stores_files_for_the_agent_to_fetch_then_cleans_up(): void
    {
        Storage::fake('local');
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $volume = $this->volume($org, $server);

        $jobId = $this->api($token)->post("/api/v1/volumes/{$volume->id}/files/upload", [
            'path' => 'wp-content',
            'files' => [UploadedFile::fake()->createWithContent('style.css', 'a{}'), UploadedFile::fake()->createWithContent('logo.svg', '<svg/>')],
            'relative_paths' => ['themes/acme/style.css', ''],
        ], ['Accept' => 'application/json'])->assertStatus(202)->json('data.job_id');

        $command = $this->heartbeat($secret)['commands'][0];
        $this->assertSame('fs.upload', $command['type']);
        $this->assertSame(['wp-content/themes/acme/style.css', 'wp-content/logo.svg'], array_column($command['payload']['files'], 'path'));
        $this->assertFalse($command['payload']['overwrite']);

        // The agent fetches with the signed URL alone; a tampered one is refused.
        $url = $this->local($command['payload']['files'][0]['url']);
        $this->withHeaders(['Authorization' => ''])->get($url)->assertOk()->assertStreamedContent('a{}');
        $this->withHeaders(['Authorization' => ''])->get(str_replace('/0?', '/1?', $url))->assertForbidden();

        $this->commandResult($secret, $command['id'], 'succeeded', ['output' => ['files' => 2, 'bytes' => 9]])->assertOk();
        $this->api($token)->getJson("/api/v1/jobs/{$jobId}")->assertJsonPath('data.status', 'succeeded');
        $this->assertSame([], Storage::disk('local')->allFiles('file-transfers'));
        $this->assertSame(['wp-content/themes/acme/style.css', 'wp-content/logo.svg'], AuditLog::where('organization_id', $org->id)->where('action', 'files.upload')->sole()->meta['paths']);
    }

    public function test_download_is_sent_by_the_agent_and_served_from_a_signed_link(): void
    {
        Storage::fake('local');
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $volume = $this->volume($org, $server);

        $jobId = $this->api($token)->postJson("/api/v1/volumes/{$volume->id}/files/download", ['paths' => ['wp-config.php']])->assertStatus(202)->json('data.job_id');
        $command = $this->heartbeat($secret)['commands'][0];
        $this->assertSame('fs.download', $command['type']);

        $this->withHeaders(['Authorization' => ''])->call('PUT', $this->local($command['payload']['upload']['url']), [], [], [], [], "<?php // config")->assertCreated();
        $this->commandResult($secret, $command['id'], 'succeeded', ['output' => ['format' => 'file', 'name' => 'wp-config.php', 'bytes' => 15, 'files' => 1]])->assertOk();

        $job = $this->api($token)->getJson("/api/v1/jobs/{$jobId}")->assertJsonPath('data.status', 'succeeded')->json('data.result');
        $this->assertNotEmpty($job['expires_at']);
        $response = $this->withHeaders(['Authorization' => ''])->get(substr($job['download_url'], strpos($job['download_url'], '/api/')));
        $response->assertOk()->assertDownload('wp-config.php');
        $this->assertSame('<?php // config', $response->streamedContent());

        // A finished job takes no further uploads.
        $this->withHeaders(['Authorization' => ''])->call('PUT', $this->local($command['payload']['upload']['url']), [], [], [], [], 'replaced')->assertStatus(409);
    }

    public function test_viewers_cannot_upload_or_download(): void
    {
        [, $org, $token] = $this->org();
        [, $viewer] = $this->member($org, 'viewer');
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $volume = $this->volume($org, $server);

        $this->api($viewer)->postJson("/api/v1/volumes/{$volume->id}/files/download", ['paths' => ['wp-config.php']])->assertForbidden();
        $this->api($viewer)->post("/api/v1/volumes/{$volume->id}/files/upload", ['files' => [UploadedFile::fake()->create('a.txt', 1)]], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_trash_copy_move_and_archives_dispatch_typed_commands_and_are_audited(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $volume = $this->volume($org, $server);
        $base = "/api/v1/volumes/{$volume->id}/files";

        $this->api($token)->postJson("{$base}/trash", ['paths' => ['wp-content/old-theme']])->assertStatus(202);
        $this->api($token)->getJson("{$base}/trash")->assertStatus(202);
        $this->api($token)->postJson("{$base}/trash/restore", ['ids' => ['20261029T120000-ab12cd34']])->assertStatus(202);
        $this->api($token)->postJson("{$base}/trash/restore", ['ids' => ['../../etc']])->assertStatus(422);
        $this->api($token)->deleteJson("{$base}/trash", ['ids' => ['20261029T120000-ab12cd34']])->assertStatus(202);
        $this->api($token)->postJson("{$base}/copy", ['paths' => ['a.txt'], 'dest' => 'backup'])->assertStatus(202);
        $this->api($token)->postJson("{$base}/move", ['paths' => ['a.txt', 'b.txt'], 'dest' => 'old'])->assertStatus(202);
        $this->api($token)->postJson("{$base}/compress", ['paths' => ['site'], 'dest' => '', 'format' => 'rar'])->assertStatus(422);
        $this->api($token)->postJson("{$base}/compress", ['paths' => ['site'], 'dest' => '', 'format' => 'tar.gz'])->assertStatus(202);
        $this->api($token)->postJson("{$base}/extract", ['path' => 'site.tar.gz', 'dest' => 'restore'])->assertStatus(202);

        $commands = collect($this->heartbeat($secret)['commands']);
        $this->assertSame(['fs.trash', 'fs.trash_list', 'fs.trash_restore', 'fs.trash_purge', 'fs.copy', 'fs.move', 'fs.compress', 'fs.extract'], $commands->pluck('type')->all());
        $this->assertSame(['a.txt', 'b.txt'], $commands->firstWhere('type', 'fs.move')['payload']['paths']);
        $this->assertSame('tar.gz', $commands->firstWhere('type', 'fs.compress')['payload']['format']);

        $actions = AuditLog::where('organization_id', $org->id)->where('action', 'like', 'files.%')->pluck('action')->all();
        $this->assertSame(['files.trash', 'files.trash_restore', 'files.trash_purge', 'files.copy', 'files.move', 'files.compress', 'files.extract'], $actions);
        $this->assertSame(['wp-content/old-theme'], AuditLog::where('organization_id', $org->id)->where('action', 'files.trash')->sole()->meta['paths']);
    }
}
