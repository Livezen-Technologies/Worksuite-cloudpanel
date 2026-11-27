<?php

namespace Tests\Feature;

use App\Models\VpnNetwork;
use Tests\TestCase;

/** VPN phase 2: deploying a VPN network to its gateway through the agent, failure, stop and status. */
class VpnDeployTest extends TestCase
{
    private const GW_KEY = 'HIgo9xNzJMWLKASShiTqIybxZ0U3wGLiUeJ1PKf8ykw=';

    /** @return array<int, array{id:string,type:string,payload:array}> pending commands of a type */
    private function pending(string $secret, string $type): array
    {
        return array_values(array_filter($this->heartbeat($secret)['commands'], fn ($c) => $c['type'] === $type));
    }

    public function test_deploy_sends_the_gateway_config_and_records_the_result(): void
    {
        [, $org, $token] = $this->org();
        $gw = $this->server($org, ['name' => 'frontdoor-01', 'ip' => '203.0.113.30']);
        [$secret] = $this->enrollAgent($gw, $token);
        $this->heartbeat($secret);
        $id = $this->api($token)->postJson('/api/v1/vpn/networks', ['name' => 'Staff', 'protocol' => 'wireguard', 'gateway_server_id' => $gw->id, 'cidr' => '10.90.0.0/24', 'port' => 51821, 'routing' => 'split'])->assertCreated()->json('data.id');

        $this->api($token)->postJson("/api/v1/vpn/networks/$id/deploy")->assertStatus(202)->assertJsonPath('data.network.status', 'deploying');
        $this->api($token)->patchJson("/api/v1/vpn/networks/$id", ['cidr' => '10.90.5.0/24'])->assertStatus(422);
        [$c] = $this->pending($secret, 'vpn.apply');
        $net = VpnNetwork::allTenants()->find($id);
        $this->assertSame([$net->interface, '10.90.0.1/24', 51821, 'split', ['10.88.0.0/16', '10.89.0.0/16'], [], true],
            [$c['payload']['interface'], $c['payload']['address'], $c['payload']['listen_port'], $c['payload']['routing'], $c['payload']['reach'], $c['payload']['peers'], $c['payload']['open_port']]);
        $this->assertMatchesRegularExpression('/^wsv[0-9a-f]{12}$/', $net->interface);

        $this->commandResult($secret, $c['id'], 'succeeded', ['output' => ['up' => true, 'public_key' => self::GW_KEY, 'port_opened' => 'ufw']])->assertOk();
        $net->refresh();
        $this->assertSame(['active', self::GW_KEY], [$net->status, $net->public_key]);
        $this->assertNotNull($net->deployed_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'vpn.network.deploy', 'resource_id' => $id]);

        $this->api($token)->postJson("/api/v1/vpn/networks/$id/status")->assertStatus(202);
        [$s] = $this->pending($secret, 'vpn.status');
        $this->assertSame($net->interface, $s['payload']['interface']);
        $this->commandResult($secret, $s['id'], 'succeeded', ['output' => ['up' => true, 'peers' => [['public_key' => 'x', 'rx_bytes' => 5]]]])->assertOk();
        $this->assertCount(1, $net->fresh()->gateway_status['peers']);

        $this->api($token)->deleteJson("/api/v1/vpn/networks/$id")->assertStatus(422);
        $this->api($token)->postJson("/api/v1/vpn/networks/$id/stop")->assertStatus(202);
        [$d] = $this->pending($secret, 'vpn.apply');
        $this->assertSame(['interface' => $net->interface, 'down' => true], $d['payload']);
        $this->commandResult($secret, $d['id'], 'succeeded', ['output' => ['up' => false]])->assertOk();
        $this->assertSame('stopped', $net->fresh()->status);
        $this->api($token)->deleteJson("/api/v1/vpn/networks/$id")->assertNoContent();
    }

    public function test_a_failed_deploy_is_recorded_with_the_agents_message(): void
    {
        [, $org, $token] = $this->org();
        $gw = $this->server($org);
        [$secret] = $this->enrollAgent($gw, $token);
        $this->heartbeat($secret);
        $id = $this->api($token)->postJson('/api/v1/vpn/networks', ['name' => 'Staff', 'protocol' => 'wireguard', 'gateway_server_id' => $gw->id, 'cidr' => '10.90.0.0/24', 'port' => 51821])->assertCreated()->json('data.id');
        $this->api($token)->postJson("/api/v1/vpn/networks/$id/deploy")->assertStatus(202);
        [$c] = $this->pending($secret, 'vpn.apply');
        $this->commandResult($secret, $c['id'], 'failed', ['message' => 'loading VPN rules: Error (rolled back)'])->assertOk();
        $net = VpnNetwork::allTenants()->find($id);
        $this->assertSame(['failed', 'loading VPN rules: Error (rolled back)'], [$net->status, $net->last_error]);
    }

    public function test_openvpn_and_offline_gateways_are_refused(): void
    {
        [, $org, $token] = $this->org();
        $off = $this->server($org, ['last_heartbeat_at' => now()->subDay()]);
        $id = $this->api($token)->postJson('/api/v1/vpn/networks', ['name' => 'A', 'protocol' => 'wireguard', 'gateway_server_id' => $off->id, 'cidr' => '10.90.0.0/24', 'port' => 51821])->assertCreated()->json('data.id');
        $this->api($token)->postJson("/api/v1/vpn/networks/$id/deploy")->assertStatus(422)->assertJsonPath('error.fields.gateway_server_id.0', fn ($m) => str_contains($m, 'not online'));
        $ovpn = $this->api($token)->postJson('/api/v1/vpn/networks', ['name' => 'B', 'protocol' => 'openvpn', 'gateway_server_id' => $this->server($org)->id, 'cidr' => '10.90.1.0/24', 'port' => 1194])->assertCreated()->json('data.id');
        $this->api($token)->postJson("/api/v1/vpn/networks/$ovpn/deploy")->assertStatus(422)->assertJsonPath('error.fields.protocol.0', fn ($m) => str_contains($m, 'OpenVPN'));
    }
}
