<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FirewallBlockedIp;
use App\Models\FirewallIpList;
use App\Support\Tenant;
use Tests\TestCase;

/** Firewall → Blocked IPs: manual blocks and CrowdSec bans; unblock / extend / permanent / allow, audited. */
class BlockedIpsTest extends TestCase
{
    private function ingress(): array
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['tags' => ['ingress']]);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        config(['platform.traefik.ingress_server_ids' => [$server->id]]);

        return [$org, $token, $secret, $server];
    }

    public function test_refresh_reads_bans_and_lists_them_with_manual_blocks(): void
    {
        [$org, $token, $secret] = $this->ingress();
        $this->api($token)->postJson('/api/v1/firewall/ips', ['list' => 'block', 'cidr' => '198.51.100.7', 'note' => 'abuse'])->assertCreated();
        $this->api($token)->postJson('/api/v1/firewall/blocked/refresh')->assertStatus(202);
        $cmd = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'crowdsec.decisions');
        $this->assertSame('list', $cmd['payload']['op']);
        $this->commandResult($secret, $cmd['id'], 'succeeded', ['output' => ['decisions' => [
            ['id' => 9, 'ip' => '203.0.113.5', 'type' => 'ban', 'origin' => 'crowdsec', 'scenario' => 'crowdsecurity/http-probing', 'country' => 'LK', 'events' => 11, 'at' => now()->subHour()->toIso8601String(), 'until' => now()->addHours(3)->toIso8601String()],
        ]]])->assertOk();

        $items = collect($this->api($token)->getJson('/api/v1/firewall/blocked')->assertOk()->json('data.items'));
        $this->assertCount(2, $items);
        $auto = $items->firstWhere('ip', '203.0.113.5');
        $this->assertSame('Suspicious activity', $auto['reason']);
        $this->assertSame('LK', $auto['country']);
        $this->assertSame('abuse', $items->firstWhere('kind', 'manual')['reason']);
        unset($org);
    }

    public function test_actions_reach_crowdsec_and_are_audited(): void
    {
        [$org, $token, $secret, $server] = $this->ingress();
        FirewallBlockedIp::create(['server_id' => $server->id, 'ip' => '203.0.113.5', 'origin' => 'crowdsec', 'scenario' => 'x', 'started_at' => now(), 'expires_at' => now()->addHour()]);

        $this->api($token)->postJson('/api/v1/firewall/blocked/unblock', ['key' => 'auto:203.0.113.5', 'reason' => 'customer office'])->assertOk();
        $cmd = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'crowdsec.decisions');
        $this->assertSame(['op' => 'delete', 'ip' => '203.0.113.5'], $cmd['payload']);
        $this->assertSame(0, FirewallBlockedIp::count());
        $log = app(Tenant::class)->as($org->id, fn () => AuditLog::where('action', 'firewall.unblock')->latest('id')->first());
        $this->assertSame('customer office', $log->meta['reason']);
        $this->assertSame('x', $log->meta['previous']['decisions'][0]['scenario']);

        $this->api($token)->postJson('/api/v1/firewall/blocked/extend', ['key' => 'auto:203.0.113.6', 'duration' => '7d'])->assertOk();
        $this->api($token)->postJson('/api/v1/firewall/blocked/extend', ['key' => 'auto:203.0.113.6', 'duration' => 'forever'])->assertStatus(422);
        $this->api($token)->postJson('/api/v1/firewall/blocked/permanent', ['key' => 'auto:203.0.113.6'])->assertOk();
        $this->assertTrue(FirewallIpList::allTenants()->where('list', 'block')->where('cidr', '203.0.113.6/32')->whereNull('expires_at')->exists());
        $this->api($token)->postJson('/api/v1/firewall/blocked/allow', ['key' => 'auto:198.51.100.9', 'reason' => 'partner'])->assertOk();
        $this->assertTrue(FirewallIpList::allTenants()->where('list', 'allow')->where('cidr', '198.51.100.9/32')->exists());
    }

    public function test_support_can_unblock_but_not_extend(): void
    {
        [, $org, $owner] = $this->org();
        [, $support] = $this->member($org, 'support');
        $this->api($owner)->postJson('/api/v1/firewall/ips', ['list' => 'block', 'cidr' => '198.51.100.7'])->assertCreated();
        $id = FirewallIpList::allTenants()->value('id');
        $this->api($support)->postJson('/api/v1/firewall/blocked/extend', ['key' => "manual:{$id}", 'duration' => '4h'])->assertForbidden();
        $this->api($support)->postJson('/api/v1/firewall/blocked/unblock', ['key' => "manual:{$id}"])->assertOk();
        $this->assertSame(0, FirewallIpList::allTenants()->count());
    }
}
