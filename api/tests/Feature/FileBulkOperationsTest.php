<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Volume;
use Tests\TestCase;

/** FM Bulk Operations: extracting several archives and copying or moving into another volume. */
class FileBulkOperationsTest extends TestCase
{
    private function vol($org, $server, string $name, string $type = 'volume'): Volume
    {
        return Volume::allTenants()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'name' => $name, 'type' => $type, 'status' => 'active']);
    }

    public function test_bulk_extract_sends_every_archive_in_one_command(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $volume = $this->vol($org, $server, 'site');

        $this->api($token)->postJson("/api/v1/volumes/{$volume->id}/files/extract", ['paths' => ['a.zip', 'b.tar.gz'], 'dest' => 'imports', 'separate' => true])->assertStatus(202);
        $command = $this->heartbeat($secret)['commands'][0];
        $this->assertSame(['fs.extract', ['a.zip', 'b.tar.gz'], 'imports', true], [$command['type'], $command['payload']['paths'], $command['payload']['dest'], $command['payload']['separate']]);
        $this->assertTrue(AuditLog::where('action', 'files.extract')->sole()->meta['separate']);

        // A single archive still works as before.
        $this->api($token)->postJson("/api/v1/volumes/{$volume->id}/files/extract", ['path' => 'a.zip', 'dest' => ''])->assertStatus(202);
        $this->api($token)->postJson("/api/v1/volumes/{$volume->id}/files/extract", ['dest' => ''])->assertStatus(422);
    }

    public function test_copy_and_move_into_another_volume_on_the_same_server(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $site = $this->vol($org, $server, 'site');
        $staging = $this->vol($org, $server, 'staging');

        $this->api($token)->postJson("/api/v1/volumes/{$site->id}/files/copy", ['paths' => ['wp-content/uploads'], 'dest' => 'wp-content', 'dest_id' => $staging->id])->assertStatus(202);
        $this->api($token)->postJson("/api/v1/volumes/{$site->id}/files/move", ['paths' => ['old'], 'dest' => '', 'dest_id' => $staging->id])->assertStatus(202);
        $commands = $this->heartbeat($secret)['commands'];
        $this->assertSame([['fs.copy', 'staging'], ['fs.move', 'staging']], array_map(fn ($c) => [$c['type'], $c['payload']['dest_volume']], $commands));
        $this->assertSame('site', $commands[0]['payload']['volume']);
        $this->assertSame('staging', AuditLog::where('action', 'files.copy')->sole()->meta['dest_volume']);

        // Same volume: no dest_volume; another server or NFS: refused; someone else's volume: not found.
        $this->api($token)->postJson("/api/v1/volumes/{$site->id}/files/copy", ['paths' => ['a'], 'dest' => '', 'dest_id' => $site->id])->assertStatus(202);
        $this->assertArrayNotHasKey('dest_volume', $this->heartbeat($secret)['commands'][0]['payload']);
        $elsewhere = $this->vol($org, $this->server($org), 'elsewhere');
        $this->api($token)->postJson("/api/v1/volumes/{$site->id}/files/copy", ['paths' => ['a'], 'dest' => '', 'dest_id' => $elsewhere->id])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['dest_id']]]);
        $nfs = $this->vol($org, $server, 'shared', 'nfs');
        $this->api($token)->postJson("/api/v1/volumes/{$site->id}/files/copy", ['paths' => ['a'], 'dest' => '', 'dest_id' => $nfs->id])->assertStatus(422);
        [, $other] = $this->org();
        $foreign = $this->vol($other, $server, 'foreign');
        $this->api($token)->postJson("/api/v1/volumes/{$site->id}/files/copy", ['paths' => ['a'], 'dest' => '', 'dest_id' => $foreign->id])->assertNotFound();
    }
}
