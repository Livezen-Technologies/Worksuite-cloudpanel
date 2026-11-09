<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Database;
use App\Models\Server;
use App\Support\Tenant;
use Tests\TestCase;

/** Drain moves stateless apps elsewhere; delete is refused while anything lives on the server. */
class ServerDrainTest extends TestCase
{
    public function test_drain_moves_stateless_apps_and_delete_waits_until_the_server_is_empty(): void
    {
        [, $org, $token] = $this->org();
        $old = $this->server($org, ['environment' => 'staging']);
        $new = $this->server($org, ['environment' => 'staging']);
        foreach ([[$old, $token], [$new, $token]] as [$s, $t]) {
            [$secret] = $this->enrollAgent($s, $t);
            $this->heartbeat($secret);
        }
        [$web, $wp, $db] = app(Tenant::class)->as($org->id, fn () => [
            Application::create(['name' => 'web', 'kind' => 'admin', 'source_type' => 'image', 'image' => 'nginx:1.27', 'server_id' => $old->id, 'port' => 80, 'environment' => 'staging']),
            Application::create(['name' => 'blog', 'kind' => 'admin', 'source_type' => 'image', 'image' => 'wordpress:6', 'server_id' => $old->id, 'port' => 80, 'environment' => 'staging', 'volume_path' => '/var/www/html']),
            Database::create(['server_id' => $old->id, 'name' => 'main', 'engine' => 'mariadb', 'version' => '11', 'status' => 'running']),
        ]);

        $this->api($token)->deleteJson("/api/v1/servers/{$old->id}")->assertStatus(409)->assertJsonPath('error.residents.databases.0.name', 'main');
        $this->assertNotNull(Server::find($old->id), 'nothing is deleted');

        $res = $this->api($token)->postJson("/api/v1/servers/{$old->id}/drain")->assertOk()->json('data');
        $this->assertSame(['web'], array_column($res['moving'], 'name'));
        $this->assertEqualsCanonicalizing(['blog', 'main'], array_column($res['kept'], 'name'));
        $this->assertTrue(Server::find($old->id)->maintenance, 'drained servers stay out of placement');
        $this->assertSame($new->id, Application::allTenants()->find($web->id)->server_id, 'placed on the other healthy server');
        $this->assertSame($old->id, Application::allTenants()->find($wp->id)->server_id, 'data stays with its volume');
        $this->assertDatabaseHas('audit_logs', ['action' => 'server.drain', 'resource_id' => $old->id]);

        // Once the rest is moved by hand, the server can go.
        Application::allTenants()->whereKey($wp->id)->update(['server_id' => $new->id]);
        Database::allTenants()->whereKey($db->id)->update(['server_id' => $new->id]);
        $this->assertSame([], $this->api($token)->getJson("/api/v1/servers/{$old->id}/residents")->json('data.apps'));
        $this->api($token)->deleteJson("/api/v1/servers/{$old->id}")->assertNoContent();
    }

    public function test_revoking_the_agent_cuts_it_off_until_it_is_enrolled_again(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['name' => 'web-1']);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $this->assertSame('online', Server::find($server->id)->status);

        $this->api($token)->postJson("/api/v1/servers/{$server->id}/agent/revoke", ['confirm' => 'wrong'])->assertStatus(422);
        $this->api($token)->postJson("/api/v1/servers/{$server->id}/agent/revoke", ['confirm' => 'web-1'])->assertOk()->assertJsonPath('data.status', 'pending');
        $this->withHeaders(['Authorization' => 'Bearer '.$secret])->postJson('/agent/v1/heartbeat', [])->assertStatus(401);
        $this->assertDatabaseHas('audit_logs', ['action' => 'server.agent_revoked', 'resource_id' => $server->id]);

        // A fresh enrolment brings it back.
        [$fresh] = $this->enrollAgent($server, $token);
        $this->heartbeat($fresh);
        $this->assertSame('online', Server::find($server->id)->status);
    }
}
