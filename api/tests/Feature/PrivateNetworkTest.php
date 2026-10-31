<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\VpnPeer;
use App\Support\Tenant;
use Tests\TestCase;

/** PNET-1..5: WireGuard mesh, *.internal names, admin VPN configs and status. */
class PrivateNetworkTest extends TestCase
{
    private const KEY_A = 'xTIBA5rboUvnH4htodjb6e697QjLERt1NAB4mZqp8Dg=';

    private const KEY_B = 'HIgo9xNzJMWLKASShiTqIybxZ0U3wGLiUeJ1PKf8ykw=';

    /** Answers every pending mesh.apply on a server with its public key; returns the payloads. */
    private function applyAs(string $secret, string $key): array
    {
        $payloads = [];
        foreach ($this->heartbeat($secret)['commands'] as $c) {
            if ($c['type'] === 'mesh.apply') {
                $payloads[] = $c['payload'];
                $this->commandResult($secret, $c['id'], 'succeeded', ['output' => ['public_key' => $key, 'up' => true]])->assertOk();
            }
        }

        return $payloads;
    }

    public function test_servers_form_a_mesh_with_internal_names_and_admins_get_vpn_configs(): void
    {
        [, $org, $token] = $this->org();
        $a = $this->server($org, ['name' => 'web-1', 'ip' => '203.0.113.10']);
        $b = $this->server($org, ['name' => 'db-1', 'ip' => '203.0.113.20']);
        [$secretA] = $this->enrollAgent($a, $token);
        [$secretB] = $this->enrollAgent($b, $token);
        $this->heartbeat($secretA);
        $this->heartbeat($secretB);
        $this->api($token)->postJson('/api/v1/applications', ['name' => 'shop', 'source_type' => 'image', 'image' => 'acme/shop:1', 'server_id' => $b->id, 'port' => 8080])->assertCreated();
        $this->api($token)->postJson('/api/v1/mesh/vpn-peers', ['name' => 'laptop'])->assertStatus(409);

        $this->api($token)->postJson("/api/v1/mesh/servers/{$a->id}")->assertStatus(202);
        [$first] = $this->applyAs($secretA, self::KEY_A);
        $this->assertSame(['10.88.0.1/16', 51820, []], [$first['address'], $first['listen_port'], $first['peers']]);
        $this->api($token)->postJson("/api/v1/mesh/servers/{$a->id}")->assertStatus(409);

        $this->api($token)->postJson("/api/v1/mesh/servers/{$b->id}")->assertStatus(202);
        $toB = $this->applyAs($secretB, self::KEY_B)[0];
        $this->assertSame([['public_key' => self::KEY_A, 'endpoint' => '203.0.113.10:51820', 'allowed_ips' => ['10.88.0.1/32', '10.89.1.0/24'], 'keepalive' => 25]], $toB['peers']);
        $this->assertSame(['10.89.2.0/24', '10.89.0.0/16'], [$toB['container_subnet'], $toB['container_supernet']]);
        $this->assertSame('10.88.0.2/16', $toB['address']);
        // B's new key is pushed to A.
        $toA = collect($this->applyAs($secretA, self::KEY_A))->last();
        $this->assertSame(self::KEY_B, $toA['peers'][0]['public_key']);
        $this->assertSame('203.0.113.20:51820', $toA['peers'][0]['endpoint']);
        $names = collect($toA['dns']['records'])->pluck('ip', 'name')->all();
        $this->assertSame(['web-1.internal' => '10.88.0.1', 'db-1.internal' => '10.88.0.2', 'shop.internal' => '10.88.0.2'], $names);
        $this->applyAs($secretB, self::KEY_B);

        // Admin VPN: config shown once, private key not stored; servers learn the peer.
        $vpn = $this->api($token)->postJson('/api/v1/mesh/vpn-peers', ['name' => 'laptop'])->assertCreated()->json('data');
        $this->assertSame('10.88.255.254', $vpn['address']);
        $this->assertStringContainsString("Address = 10.88.255.254/32\nDNS = 10.88.0.1, internal", $vpn['config']);
        $this->assertStringContainsString("PublicKey = ".self::KEY_A."\nEndpoint = 203.0.113.10:51820\nAllowedIPs = 10.88.0.1/32, 10.89.1.0/24", $vpn['config']);
        preg_match('/PrivateKey = (\S+)/', $vpn['config'], $m);
        $peer = VpnPeer::allTenants()->find($vpn['id']);
        $this->assertSame(base64_encode(sodium_crypto_box_publickey_from_secretkey(base64_decode($m[1]))), $peer->public_key);
        $this->assertStringNotContainsString($m[1], json_encode($peer->getAttributes()));
        $withVpn = $this->applyAs($secretA, self::KEY_A)[0];
        $this->assertContains(['public_key' => $peer->public_key, 'allowed_ips' => ['10.88.255.254/32']], $withVpn['peers']);
        $this->applyAs($secretB, self::KEY_B);

        // Status: handshakes reach the server row and the VPN peer.
        $this->artisan('mesh:status')->assertSuccessful();
        $status = collect($this->heartbeat($secretA)['commands'])->firstWhere('type', 'mesh.status');
        $this->commandResult($secretA, $status['id'], 'succeeded', ['output' => ['up' => true, 'peers' => [['public_key' => $peer->public_key, 'last_handshake' => '2026-10-31T09:00:00Z', 'rx_bytes' => 10, 'tx_bytes' => 20]]]])->assertOk();
        $this->assertSame('2026-10-31 09:00:00', $peer->fresh()->last_handshake_at->format('Y-m-d H:i:s'));
        $this->assertTrue(Server::allTenants()->find($a->id)->mesh_status['up']);

        $page = $this->api($token)->getJson('/api/v1/mesh')->assertOk()->json('data');
        $this->assertSame(['web-1', 'db-1'], array_column($page['members'], 'name'));
        $this->assertCount(1, $page['vpn_peers']);

        // Revoke and leave.
        $this->api($token)->deleteJson("/api/v1/mesh/vpn-peers/{$vpn['id']}")->assertNoContent();
        $this->assertSame(1, count($this->applyAs($secretA, self::KEY_A)[0]['peers']));
        $this->applyAs($secretB, self::KEY_B);
        $this->api($token)->deleteJson("/api/v1/mesh/servers/{$b->id}")->assertStatus(202);
        $this->assertTrue(collect($this->heartbeat($secretB)['commands'])->contains(fn ($c) => $c['type'] === 'mesh.apply' && ($c['payload']['down'] ?? false)));
        $this->assertSame([], $this->applyAs($secretA, self::KEY_A)[0]['peers']);
        [, , $other] = $this->org();
        $this->api($other)->postJson("/api/v1/mesh/servers/{$a->id}")->assertNotFound();
    }

    public function test_ssh_can_be_limited_to_the_private_network_with_a_confirmation_window(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-11-02 10:00:00');
        [, $org, $token] = $this->org();
        $a = $this->server($org, ['name' => 'web-1', 'ip' => '203.0.113.10']);
        [$secret] = $this->enrollAgent($a, $token);
        $this->heartbeat($secret);
        $this->api($token)->postJson("/api/v1/mesh/servers/{$a->id}/ssh-lock")->assertNotFound();
        $this->api($token)->postJson("/api/v1/mesh/servers/{$a->id}")->assertStatus(202);
        $first = $this->applyAs($secret, self::KEY_A)[0];
        $this->applyAs($secret, self::KEY_A);
        $this->assertTrue($first['open_port'], 'the WireGuard port is opened in the host firewall');
        $this->assertArrayNotHasKey('ssh_lock', $first);

        $this->api($token)->postJson("/api/v1/mesh/servers/{$a->id}/ssh-lock")->assertStatus(409)->assertSee('lock yourself out');
        $this->api($token)->postJson("/api/v1/mesh/servers/{$a->id}/ssh-lock", ['allow' => ['nope']])->assertStatus(422);
        $this->api($token)->postJson("/api/v1/mesh/servers/{$a->id}/ssh-lock", ['allow' => ['198.51.100.7']])->assertStatus(202);
        $lock = collect($this->applyAs($secret, self::KEY_A))->last()['ssh_lock'];
        $this->assertSame(['port' => 22, 'allow' => ['198.51.100.7'], 'confirm_within_seconds' => 300], $lock);

        $this->api($token)->postJson("/api/v1/mesh/servers/{$a->id}/ssh-lock/confirm")->assertOk()->assertJsonPath('data.members.0.ssh_lock.allow.0', '198.51.100.7');
        $this->assertSame('mesh.confirm', $this->heartbeat($secret)['commands'][0]['type']);
        $this->api($token)->postJson('/api/v1/mesh/apply')->assertStatus(202);
        $this->assertSame(0, collect($this->applyAs($secret, self::KEY_A))->last()['ssh_lock']['confirm_within_seconds'], 'a confirmed lock is applied without a rollback');

        // An unconfirmed lock expires on the panel as it does on the server.
        $this->api($token)->deleteJson("/api/v1/mesh/servers/{$a->id}/ssh-lock")->assertStatus(202);
        $this->assertArrayNotHasKey('ssh_lock', collect($this->applyAs($secret, self::KEY_A))->last());
        $this->api($token)->postJson("/api/v1/mesh/servers/{$a->id}/ssh-lock", ['allow' => ['198.51.100.0/24']])->assertStatus(202);
        $this->applyAs($secret, self::KEY_A);
        \Illuminate\Support\Carbon::setTestNow('2026-11-02 10:06:00');
        $this->api($token)->postJson("/api/v1/mesh/servers/{$a->id}/ssh-lock/confirm")->assertStatus(409)->assertSee('window passed');
        $this->assertNull(\App\Models\Server::allTenants()->find($a->id)->mesh_ssh_lock);
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_apps_on_the_mesh_network_get_a_fixed_routable_address(): void
    {
        [, $org, $token] = $this->org();
        $a = $this->server($org, ['name' => 'web-1', 'ip' => '203.0.113.10']);
        [$secret] = $this->enrollAgent($a, $token);
        $this->heartbeat($secret);
        $this->api($token)->postJson("/api/v1/mesh/servers/{$a->id}")->assertStatus(202);
        $this->applyAs($secret, self::KEY_A);
        $this->applyAs($secret, self::KEY_A);
        $appId = $this->api($token)->postJson('/api/v1/applications', ['name' => 'api', 'source_type' => 'image', 'image' => 'acme/api:1', 'server_id' => $a->id, 'port' => 9000, 'mesh_network' => true])->assertCreated()->json('data.id');
        $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $appId])->assertStatus(202);
        $create = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'container.create');
        $this->assertSame([['name' => 'proxy'], ['name' => 'dmp-mesh', 'ipv4_address' => '10.89.1.10']], $create['payload']['networks']);
        $records = collect(app(Tenant::class)->as($org->id, fn () => app(\App\Services\MeshService::class)->records()))->pluck('ip', 'name');
        $this->assertSame('10.89.1.10', $records['api.internal']);
        $this->assertSame('10.88.0.1', $records['web-1.internal']);
    }

    public function test_the_admin_api_can_be_limited_to_the_private_network(): void
    {
        [, , $token] = $this->org();
        config(['security.admin_allowed_cidrs' => ['10.88.0.0/16']]);
        $this->api($token)->getJson('/api/v1/servers')->assertStatus(403)->assertJsonPath('error.code', 'network_restricted');
        $this->api($token)->getJson('/api/v1/me')->assertOk();
        $this->api($token)->withServerVariables(['REMOTE_ADDR' => '10.88.255.254'])->getJson('/api/v1/servers')->assertOk();
        config(['security.admin_allowed_cidrs' => []]);
        $this->api($token)->getJson('/api/v1/servers')->assertOk();
    }
}
