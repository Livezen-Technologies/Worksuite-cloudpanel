<?php

namespace Tests\Feature;

use App\Models\VpnNetwork;
use Tests\TestCase;

/** VPN phase 1: VPN network records, their address and port rules, and organisation isolation. */
class VpnNetworkTest extends TestCase
{
    private function body(string $gw, array $over = []): array
    {
        return $over + ['name' => 'Livezen Private', 'protocol' => 'wireguard', 'gateway_server_id' => $gw, 'cidr' => '10.90.0.0/24', 'port' => 51821, 'routing' => 'split', 'private_dns' => true];
    }

    public function test_create_list_update_and_delete_a_draft_network(): void
    {
        [, $org, $token] = $this->org();
        $gw = $this->server($org)->id;

        $this->assertSame('10.90.0.0/24', $this->api($token)->getJson('/api/v1/vpn/networks/suggest')->assertOk()->json('data.cidr'));
        $id = $this->api($token)->postJson('/api/v1/vpn/networks', $this->body($gw, ['transport' => 'tcp']))->assertCreated()->json('data.id');
        $net = VpnNetwork::allTenants()->find($id);
        $this->assertSame(['draft', 'udp'], [$net->status, $net->transport]);
        $this->assertSame('10.90.1.0/24', $this->api($token)->getJson('/api/v1/vpn/networks/suggest')->json('data.cidr'));

        $row = $this->api($token)->getJson('/api/v1/vpn/networks')->assertOk()->json('data.0');
        $this->assertSame('Livezen Private', $row['name']);
        $this->assertNotNull($row['gateway_name']);

        $this->api($token)->patchJson("/api/v1/vpn/networks/$id", ['routing' => 'full'])->assertOk()->assertJsonPath('data.routing', 'full');
        $this->assertDatabaseHas('audit_logs', ['action' => 'vpn.network.create', 'resource_id' => $id]);

        $net->update(['status' => 'active']);
        $this->api($token)->deleteJson("/api/v1/vpn/networks/$id")->assertStatus(422);
        $net->update(['status' => 'draft']);
        $this->api($token)->deleteJson("/api/v1/vpn/networks/$id")->assertNoContent();
    }

    public function test_address_ranges_and_ports_are_checked(): void
    {
        [, $org, $token] = $this->org();
        $gw = $this->server($org)->id;
        $post = fn (array $o) => $this->api($token)->postJson('/api/v1/vpn/networks', $this->body($gw, $o));

        $post(['cidr' => '10.90.0.5/24'])->assertStatus(422)->assertJsonPath('error.fields.cidr.0', fn ($m) => is_string($m));
        $post(['cidr' => '8.8.8.0/24'])->assertStatus(422)->assertJsonPath('error.fields.cidr.0', fn ($m) => is_string($m));
        $post(['cidr' => '10.88.4.0/24'])->assertStatus(422)->assertJsonPath('error.fields.cidr.0', fn ($m) => is_string($m));
        $post(['cidr' => 'nonsense'])->assertStatus(422)->assertJsonPath('error.fields.cidr.0', fn ($m) => is_string($m));
        $post(['port' => 51820])->assertStatus(422)->assertJsonPath('error.fields.port.0', fn ($m) => is_string($m));

        $post([])->assertCreated();
        $post(['name' => 'Second', 'cidr' => '10.90.0.128/25', 'port' => 51822])->assertStatus(422)->assertJsonPath('error.fields.cidr.0', fn ($m) => is_string($m));
        $post(['name' => 'Second', 'cidr' => '10.90.1.0/24'])->assertStatus(422)->assertJsonPath('error.fields.port.0', fn ($m) => is_string($m));
        $post(['name' => 'Second', 'cidr' => '10.90.1.0/24', 'protocol' => 'openvpn', 'port' => 1194, 'transport' => 'tcp'])->assertCreated();
    }

    public function test_another_organisation_cannot_see_or_change_a_network(): void
    {
        [, $org, $token] = $this->org();
        $id = $this->api($token)->postJson('/api/v1/vpn/networks', $this->body($this->server($org)->id))->assertCreated()->json('data.id');

        [, , $other] = $this->org();
        $this->assertSame([], $this->api($other)->getJson('/api/v1/vpn/networks')->assertOk()->json('data'));
        $this->api($other)->patchJson("/api/v1/vpn/networks/$id", ['name' => 'x'])->assertNotFound();
        $this->api($other)->deleteJson("/api/v1/vpn/networks/$id")->assertNotFound();
    }
}
