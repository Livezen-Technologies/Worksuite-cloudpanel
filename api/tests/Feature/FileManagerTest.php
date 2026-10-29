<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Volume;
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
}
