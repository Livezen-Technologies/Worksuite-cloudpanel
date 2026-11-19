<?php

namespace Tests\Feature;

use App\Models\ServerFirewall;
use Tests\TestCase;

/** Server → Firewall: settings, safety checks, apply with auto-rollback confirmed on success, turn off. */
class ServerFirewallTest extends TestCase
{
    private function online(): array
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        config(['platform.firewall.always_allow' => ['192.0.2.10/32']]);

        return [$org, $token, $secret, $server];
    }

    public function test_settings_are_validated_against_lockouts(): void
    {
        [, $token, , $server] = $this->online();
        $this->api($token)->putJson("/api/v1/servers/{$server->id}/firewall", ['block' => ['203.0.113.0/24', '198.51.100.7']])->assertOk()
            ->assertJsonPath('data.block', ['203.0.113.0/24', '198.51.100.7/32']);
        $this->api($token)->putJson("/api/v1/servers/{$server->id}/firewall", ['block' => ['10.1.0.0/16']])->assertStatus(422);    // private network
        $this->api($token)->putJson("/api/v1/servers/{$server->id}/firewall", ['block' => ['192.0.2.0/24']])->assertStatus(422);  // admin range
        $this->api($token)->putJson("/api/v1/servers/{$server->id}/firewall", ['block' => ['nonsense']])->assertStatus(422);
        $this->api($token)->putJson("/api/v1/servers/{$server->id}/firewall", ['mode' => 'allow_only', 'allow' => ['198.51.100.0/24']])->assertStatus(422); // no ports
        $this->api($token)->putJson("/api/v1/servers/{$server->id}/firewall", ['mode' => 'allow_only', 'allow' => ['198.51.100.0/24'], 'ports' => [3306]])->assertOk();
        $d = $this->api($token)->getJson("/api/v1/servers/{$server->id}/firewall")->assertOk()->json('data');
        $this->assertContains('192.0.2.10/32', $d['never_block']);
        $this->assertContains('172.16.0.0/12', $d['never_block']);
    }

    public function test_apply_sends_rules_and_confirms_after_success_then_turns_off(): void
    {
        [, $token, $secret, $server] = $this->online();
        $this->api($token)->putJson("/api/v1/servers/{$server->id}/firewall", ['block' => ['203.0.113.0/24']])->assertOk();

        $this->api($token)->postJson("/api/v1/servers/{$server->id}/firewall/preview")->assertStatus(202);
        $cmd = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'firewall.apply');
        $this->assertTrue($cmd['payload']['dry_run']);
        $this->commandResult($secret, $cmd['id'], 'succeeded', ['output' => ['ruleset' => 'table inet dockermgr {}', 'applied' => false]])->assertOk();
        $this->assertSame('not_applied', ServerFirewall::allTenants()->sole()->status);

        $this->api($token)->postJson("/api/v1/servers/{$server->id}/firewall/apply")->assertStatus(202);
        $cmd = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'firewall.apply');
        $this->assertFalse($cmd['payload']['dry_run']);
        $this->assertSame(120, $cmd['payload']['confirm_within_seconds']);
        $this->assertSame(['203.0.113.0/24'], $cmd['payload']['block']);
        $this->assertContains('192.0.2.10/32', $cmd['payload']['never_block']);
        $this->commandResult($secret, $cmd['id'], 'succeeded', ['output' => ['ruleset' => 'table inet dockermgr { … }', 'applied' => true]])->assertOk();
        $fw = ServerFirewall::allTenants()->sole();
        $this->assertSame('applied', $fw->status);
        $this->assertTrue($fw->enabled);
        $this->assertNotNull(collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'firewall.confirm'), 'success cancels the rollback');

        $this->api($token)->postJson("/api/v1/servers/{$server->id}/firewall/disable")->assertStatus(202);
        $cmd = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'firewall.apply');
        $this->assertSame([], $cmd['payload']['block']);
        $this->commandResult($secret, $cmd['id'], 'succeeded', ['output' => ['ruleset' => '', 'applied' => true]])->assertOk();
        $this->assertSame('disabled', ServerFirewall::allTenants()->sole()->status);
    }

    public function test_developers_can_view_but_not_change(): void
    {
        [$org, , , $server] = $this->online();
        [, $dev] = $this->member($org, 'developer');
        $this->api($dev)->getJson("/api/v1/servers/{$server->id}/firewall")->assertOk();
        $this->api($dev)->putJson("/api/v1/servers/{$server->id}/firewall", ['block' => ['203.0.113.9']])->assertForbidden();
        $this->api($dev)->postJson("/api/v1/servers/{$server->id}/firewall/apply")->assertForbidden();
    }
}
