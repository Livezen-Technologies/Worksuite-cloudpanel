<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\FirewallIpList;
use App\Models\Project;
use App\Support\Tenant;
use Tests\TestCase;

/** Project → Firewall: project-scoped view, IP entries limited to the project, app lock state. */
class ProjectFirewallTest extends TestCase
{
    public function test_view_add_and_remove_within_the_project_only(): void
    {
        [, $org, $token] = $this->org();
        [$p, $other, $web, $stranger] = app(Tenant::class)->as($org->id, function () {
            $p = Project::create(['name' => 'Shop']);
            $other = Project::create(['name' => 'Blog']);
            $web = Application::create(['name' => 'web', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'port' => 80, 'project_id' => $p->id, 'public_access' => false, 'lock_reason' => 'maintenance']);
            $stranger = Application::create(['name' => 'blog', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'port' => 80, 'project_id' => $other->id]);

            return [$p, $other, $web, $stranger];
        });

        $this->api($token)->postJson("/api/v1/projects/{$p->id}/firewall/ips", ['list' => 'block', 'cidr' => '203.0.113.0/24'])->assertCreated();
        $this->api($token)->postJson("/api/v1/projects/{$p->id}/firewall/ips", ['list' => 'allow', 'cidr' => '198.51.100.7', 'application_id' => $web->id, 'expires_hours' => 24])->assertCreated();
        $this->api($token)->postJson("/api/v1/projects/{$p->id}/firewall/ips", ['list' => 'block', 'cidr' => '192.0.2.1', 'application_id' => $stranger->id])->assertStatus(422);
        $this->api($token)->postJson("/api/v1/projects/{$p->id}/firewall/ips", ['list' => 'block', 'cidr' => '127.0.0.1'])->assertStatus(409);
        $otherEntry = app(Tenant::class)->as($org->id, fn () => FirewallIpList::create(['list' => 'block', 'cidr' => '192.0.2.0/24', 'project_id' => $other->id]));

        $d = $this->api($token)->getJson("/api/v1/projects/{$p->id}/firewall")->assertOk()->json('data');
        $this->assertCount(2, $d['ips']);
        $this->assertSame('whole project', collect($d['ips'])->firstWhere('cidr', '203.0.113.0/24')['scope']);
        $this->assertSame('app web', collect($d['ips'])->firstWhere('cidr', '198.51.100.7/32')['scope']);
        $this->assertFalse($d['apps'][0]['public_access']);
        $this->assertSame('maintenance', $d['apps'][0]['lock_reason']);

        // Another project's entry cannot be removed through this one.
        $this->api($token)->deleteJson("/api/v1/projects/{$p->id}/firewall/ips/{$otherEntry->id}")->assertNotFound();
        $mine = collect($d['ips'])->firstWhere('cidr', '203.0.113.0/24')['id'];
        $this->api($token)->deleteJson("/api/v1/projects/{$p->id}/firewall/ips/{$mine}")->assertNoContent();
        $this->assertSame(2, FirewallIpList::allTenants()->count());
    }
}
