<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\VpnPeer;
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
        $this->assertSame([['public_key' => self::KEY_A, 'endpoint' => '203.0.113.10:51820', 'allowed_ips' => ['10.88.0.1/32'], 'keepalive' => 25]], $toB['peers']);
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
        $this->assertStringContainsString("PublicKey = ".self::KEY_A."\nEndpoint = 203.0.113.10:51820\nAllowedIPs = 10.88.0.1/32", $vpn['config']);
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
}
