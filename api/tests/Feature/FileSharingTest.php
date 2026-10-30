<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FileShare;
use App\Models\Volume;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** FM File Sharing (expiring public links) and the Transfer Manager (list, retry, cancel). */
class FileSharingTest extends TestCase
{
    private function setUpVolume(): array
    {
        Storage::fake('local');
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $volume = Volume::allTenants()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'name' => 'acme_blog_html', 'type' => 'volume', 'status' => 'active']);

        return [$org, $token, $secret, $volume];
    }

    private function local(string $url): string
    {
        return substr($url, strlen(rtrim(config('backups.agent_api_url'), '/')));
    }

    /** Plays the agent's side of a download: send the bytes, report success. */
    private function agentSends(string $secret, string $content, string $name = 'report.pdf'): void
    {
        $command = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'fs.download');
        $this->withHeaders(['Authorization' => ''])->call('PUT', $this->local($command['payload']['upload']['url']), [], [], [], [], $content)->assertCreated();
        $this->commandResult($secret, $command['id'], 'succeeded', ['output' => ['format' => 'file', 'name' => $name, 'bytes' => strlen($content), 'files' => 1]])->assertOk();
    }

    private function publicPath(string $url): string
    {
        return substr($url, strpos($url, '/api/'));
    }

    public function test_a_share_serves_a_snapshot_until_its_download_limit(): void
    {
        [$org, $token, $secret, $volume] = $this->setUpVolume();
        $share = $this->api($token)->postJson("/api/v1/volumes/{$volume->id}/files/shares", ['paths' => ['docs/report.pdf'], 'expires_in_hours' => 24, 'max_downloads' => 2])
            ->assertCreated()->assertJsonPath('data.status', 'preparing')->json('data');
        $url = $this->publicPath($share['url']);

        // Not ready until the agent has sent the file.
        $this->withHeaders(['Authorization' => ''])->getJson($url)->assertStatus(409);
        $this->agentSends($secret, '%PDF-1.7 report');

        $this->assertSame('active', $this->api($token)->getJson("/api/v1/volumes/{$volume->id}/files/shares")->json('data.0.status'));
        $first = $this->withHeaders(['Authorization' => ''])->get($url)->assertOk()->assertDownload('report.pdf');
        $this->assertSame('%PDF-1.7 report', $first->streamedContent());
        // The copy lives outside the transfer folder, so the hourly prune leaves it alone.
        $this->assertSame([], Storage::disk('local')->allFiles('file-transfers'));
        $this->withHeaders(['Authorization' => ''])->get($url)->assertOk();
        $this->withHeaders(['Authorization' => ''])->getJson($url)->assertStatus(410)->assertJsonPath('error.message', 'This link has used up.');

        $this->assertSame(1, app(\App\Services\FileShareService::class)->prune());
        $this->assertSame([], Storage::disk('local')->allFiles('file-shares'));
        $log = AuditLog::where('organization_id', $org->id)->where('action', 'files.share_created')->sole();
        $this->assertSame(['docs/report.pdf'], $log->meta['paths']);
        $this->assertStringNotContainsString(basename($url), json_encode($log->meta));
    }

    public function test_password_expiry_and_revocation(): void
    {
        [, $token, $secret, $volume] = $this->setUpVolume();
        $share = $this->api($token)->postJson("/api/v1/volumes/{$volume->id}/files/shares", ['paths' => ['a.txt'], 'expires_in_hours' => 1, 'password' => 'open-sesame'])->assertCreated()->json('data');
        $this->agentSends($secret, 'hello', 'a.txt');
        $url = $this->publicPath($share['url']);
        $this->assertSame(64, strlen(FileShare::allTenants()->find($share['id'])->token_hash));

        $this->withHeaders(['Authorization' => ''])->getJson($url)->assertStatus(401)->assertJsonPath('error.message', 'This link needs a password.');
        $this->withHeaders(['Authorization' => ''])->postJson($url, ['password' => 'wrong'])->assertStatus(401)->assertJsonPath('error.message', 'Wrong password.');
        $this->withHeaders(['Authorization' => ''])->post($url, ['password' => 'open-sesame'])->assertOk()->assertDownload('a.txt');

        $this->travel(2)->hours();
        $this->withHeaders(['Authorization' => ''])->postJson($url, ['password' => 'open-sesame'])->assertStatus(410)->assertJsonPath('error.message', 'This link has expired.');
        $this->travelBack();

        $this->api($token)->deleteJson("/api/v1/volumes/{$volume->id}/files/shares/{$share['id']}")->assertNoContent();
        $this->withHeaders(['Authorization' => ''])->postJson($url, ['password' => 'open-sesame'])->assertStatus(410)->assertJsonPath('error.message', 'This link has revoked.');
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/shares/'.str_repeat('x', 40))->assertNotFound();

        [, , $other] = $this->org();
        $this->api($other)->getJson("/api/v1/volumes/{$volume->id}/files/shares")->assertNotFound();
        $this->api($token)->postJson("/api/v1/volumes/{$volume->id}/files/shares", ['paths' => ['a.txt'], 'expires_in_hours' => 9999])->assertStatus(422);
    }

    public function test_transfers_are_listed_and_a_failed_upload_is_retried_with_its_files(): void
    {
        [, $token, $secret, $volume] = $this->setUpVolume();
        $this->api($token)->post("/api/v1/volumes/{$volume->id}/files/upload", [
            'path' => 'uploads', 'files' => [UploadedFile::fake()->createWithContent('a.css', 'a{}')],
        ], ['Accept' => 'application/json'])->assertStatus(202);
        $command = $this->heartbeat($secret)['commands'][0];
        $this->commandResult($secret, $command['id'], 'failed', ['message' => 'open /var/lib/docker/volumes/x/uploads/a.css: permission denied'])->assertOk();

        $list = $this->api($token)->getJson("/api/v1/volumes/{$volume->id}/files/transfers")->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame(['upload', 'failed', true, false], [$list[0]['operation'], $list[0]['status'], $list[0]['can_retry'], $list[0]['can_cancel']]);
        $this->assertSame('Permission denied.', $list[0]['error']);
        $this->assertNotEmpty(Storage::disk('local')->allFiles('file-transfers'), 'kept for the retry');

        $retry = $this->api($token)->postJson("/api/v1/volumes/{$volume->id}/files/transfers/{$list[0]['id']}/retry")->assertStatus(202)->json('data.job_id');
        $again = $this->heartbeat($secret)['commands'][0];
        $this->assertSame('fs.upload', $again['type']);
        $this->withHeaders(['Authorization' => ''])->get($this->local($again['payload']['files'][0]['url']))->assertOk()->assertStreamedContent('a{}');
        $this->commandResult($secret, $again['id'], 'succeeded', ['output' => ['files' => 1, 'bytes' => 3]])->assertOk();
        $this->assertSame([], Storage::disk('local')->allFiles('file-transfers'));

        $list = $this->api($token)->getJson("/api/v1/volumes/{$volume->id}/files/transfers")->json('data');
        $this->assertSame([$retry, 'succeeded', $list[1]['id']], [$list[0]['id'], $list[0]['status'], $list[0]['retry_of']]);
        $this->api($token)->postJson("/api/v1/volumes/{$volume->id}/files/transfers/{$retry}/retry")->assertStatus(409);
        $this->assertTrue(AuditLog::where('action', 'files.transfer_retried')->exists());
    }

    public function test_a_queued_transfer_can_be_cancelled_but_not_once_running(): void
    {
        [, $token, $secret, $volume] = $this->setUpVolume();
        $first = $this->api($token)->postJson("/api/v1/volumes/{$volume->id}/files/copy", ['paths' => ['a'], 'dest' => 'b'])->assertStatus(202)->json('data.job_id');
        $this->api($token)->postJson("/api/v1/volumes/{$volume->id}/files/transfers/{$first}/cancel")->assertNoContent();
        $this->api($token)->getJson("/api/v1/jobs/{$first}")->assertJsonPath('data.status', 'failed');
        $this->assertSame([], $this->heartbeat($secret)['commands'], 'a cancelled command is never sent');

        $second = $this->api($token)->postJson("/api/v1/volumes/{$volume->id}/files/move", ['paths' => ['a'], 'dest' => 'b'])->assertStatus(202)->json('data.job_id');
        $this->heartbeat($secret);
        $this->api($token)->postJson("/api/v1/volumes/{$volume->id}/files/transfers/{$second}/cancel")->assertStatus(409);

        // Listing is not a transfer.
        $listing = $this->api($token)->getJson("/api/v1/volumes/{$volume->id}/files")->json('data.job_id');
        $this->api($token)->postJson("/api/v1/volumes/{$volume->id}/files/transfers/{$listing}/cancel")->assertNotFound();
    }
}
