<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Contracts\DnsResolver;
use App\Support\Tenant;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/** PWS-E: the project firewall renders only onto the project's own routers. */
class ProjectFirewallTest extends TestCase
{
    public function test_project_ip_blocks_rate_limits_and_lock_reach_only_the_projects_routers(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['ip' => '198.51.100.9']);
        [$secret] = $this->enrollAgent($server, $token);
        [$project, $app, $other] = app(Tenant::class)->as($org->id, function () use ($server) {
            $p = Project::create(['name' => 'Shop']);
            $a = Application::create(['name' => 'shop', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'size' => 'small', 'server_id' => $server->id, 'port' => 80, 'project_id' => $p->id]);
            $o = Application::create(['name' => 'blog', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'size' => 'small', 'server_id' => $server->id, 'port' => 80]);

            return [$p, $a, $o];
        });
        foreach (['shop.example.com' => $app, 'blog.example.com' => $other] as $host => $a) {
            $d = $this->api($token)->postJson('/api/v1/app/domains', ['domain' => $host, 'app_id' => $a->id])->assertCreated()->json('data');
            app(DnsResolver::class)->set($host, 'A', ['198.51.100.9']);
            $this->api($token)->postJson("/api/v1/app/domains/{$d['id']}/verify")->assertOk();
        }

        [$viewer, $viewerToken] = $this->member($org, 'developer');
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $viewer->id, 'role' => 'viewer']);
        $this->api($viewerToken)->getJson("/api/v1/app/projects/{$project->id}/firewall")->assertOk()->assertJsonPath('data.can_manage', false);
        $this->api($viewerToken)->postJson("/api/v1/app/projects/{$project->id}/firewall/ips", ['list' => 'block', 'cidr' => '203.0.113.7'])->assertForbidden();

        $this->api($token)->postJson("/api/v1/app/projects/{$project->id}/firewall/ips", ['list' => 'block', 'cidr' => '203.0.113.7'])->assertCreated();
        $this->api($token)->postJson("/api/v1/app/projects/{$project->id}/firewall/rate-limits", ['name' => 'Login', 'paths' => ['/login'], 'limit' => 10])->assertCreated();
        $this->api($token)->postJson("/api/v1/app/projects/{$project->id}/firewall/apps/{$app->id}/lock", ['reason' => 'audit'])->assertOk()->assertJsonPath('data.apps.0.locked', true);

        $y = app(Tenant::class)->as($org->id, fn () => app(\App\Services\TraefikConfigService::class)->render(\App\Models\Server::allTenants()->find($server->id), false));
        $routers = json_decode(json_encode($y), true)['http']['routers'];
        $this->assertArrayHasKey('dmp-shop-example-com-blocked', $routers);
        $this->assertContains('dmp-shop-example-com-locked', $routers['dmp-shop-example-com']['middlewares']);
        $this->assertArrayNotHasKey('dmp-blog-example-com-blocked', $routers, 'another app outside the project is untouched');
        $this->assertNotContains('dmp-blog-example-com-locked', $routers['dmp-blog-example-com']['middlewares'] ?? []);
        $blogPathRouters = array_filter(array_keys($routers), fn ($k) => str_starts_with($k, 'dmp-blog-example-com') && $k !== 'dmp-blog-example-com');
        $this->assertSame([], array_values($blogPathRouters));
    }
}
