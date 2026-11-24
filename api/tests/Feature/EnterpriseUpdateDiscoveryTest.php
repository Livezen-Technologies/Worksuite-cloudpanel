<?php

namespace Tests\Feature;

use App\Models\AgentCommand;
use App\Models\EnterpriseDeployment;
use App\Models\Image;
use App\Models\Organization;
use App\Models\Server;
use App\Services\CommandBus;
use App\Services\EnterpriseProvisioningService;
use App\Services\EnterpriseUpdateService;
use App\Support\Tenant;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Updates tab finds a newly loaded worksuite-server tag: on the node the deployment runs on (not only the
 * `enterprise`-tagged nodes), without a second page load (Refresh waits for the scan), and for every deployment.
 */
class EnterpriseUpdateDiscoveryTest extends TestCase
{
    /** panel-01 (tagged enterprise) and node-05 (untagged, where the deployments were placed by node_id). */
    private function nodes(): array
    {
        [, $org, $ownerToken] = $this->org();
        $panel = $this->server($org, ['name' => 'livezen-panel-01', 'ram_mb' => 65536, 'tags' => ['enterprise']]);
        $node5 = $this->server($org, ['name' => 'livezen-node-05', 'ram_mb' => 65536]);

        return [$org, $ownerToken, $panel, $node5];
    }

    private function load(Server $server, string $tag): void
    {
        Image::allTenants()->create(['organization_id' => $server->organization_id, 'server_id' => $server->id, 'docker_id' => 'sha256:'.Str::random(12),
            'repository' => 'worksuite-server', 'tags' => ['worksuite-server:'.$tag], 'size_mb' => 900]);
    }

    private function deployment(Organization $org, Server $server, string $slug, string $version): EnterpriseDeployment
    {
        return EnterpriseDeployment::create(['organization_id' => $org->id, 'server_id' => $server->id, 'external_id' => 'ent_'.$slug,
            'customer_name' => ucfirst($slug), 'slug' => $slug, 'version' => $version, 'size' => 'small', 'status' => 'active',
            'callback_secret' => 'x', 'hostname' => $slug.'.example.test']);
    }

    private function updates(): EnterpriseUpdateService
    {
        app(Tenant::class)->set(null);

        return app(EnterpriseUpdateService::class);
    }

    public function test_a_version_only_on_node_05_is_offered_to_the_deployment_on_node_05(): void
    {
        [$org, , $panel, $node5] = $this->nodes();
        $this->load($panel, '3.2.0-3011');
        $this->load($node5, '3.2.0-3011');
        $this->load($node5, '3.3.0-3200'); // docker load-ed on node-05 only
        $pmm = $this->deployment($org, $node5, 'pmm', '3.2.0-3011');
        $other = $this->deployment($org, $panel, 'other', '3.2.0-3011');

        $state = $this->updates()->state($pmm);
        $this->assertSame(['3.3.0-3200'], array_column($state['available'], 'version'));
        $this->assertTrue($state['available'][0]['on_node']);
        $this->assertSame(['livezen-node-05'], $state['available'][0]['nodes']);
        $this->assertSame('3.3.0-3200', $state['latest']);
        $this->assertSame('livezen-node-05', $state['node']['name']);

        // The same tag is listed for a deployment on panel-01, but marked as not on its node and never "latest".
        $state = $this->updates()->state($other);
        $this->assertSame(['3.3.0-3200'], array_column($state['available'], 'version'));
        $this->assertFalse($state['available'][0]['on_node']);
        $this->assertStringContainsString('Not on livezen-panel-01 yet', $state['available'][0]['not_on_node']);
        $this->assertNull($state['latest']);

        // The org-wide list (create form) includes node-05 too.
        $versions = app(EnterpriseProvisioningService::class)->versions($org->id);
        $this->assertContains('3.3.0-3200', array_column($versions['versions'], 'version'));
        $this->assertEqualsCanonicalizing(['livezen-panel-01', 'livezen-node-05'], array_column($versions['nodes'], 'name'));
    }

    public function test_available_returns_the_newer_tags_for_every_deployment_in_an_org(): void
    {
        [$org, , $panel, $node5] = $this->nodes();
        foreach ([$panel, $node5] as $s) {
            $this->load($s, '3.1.0-4515');
            $this->load($s, '3.1.0-4558');
        }
        $deps = [
            $this->deployment($org, $node5, 'pmm', '3.1.0-4515'),
            $this->deployment($org, $node5, 'computerage', '3.1.0-4515'),
            $this->deployment($org, $panel, 'third', '3.1.0-4515'),
        ];
        foreach ($deps as $dep) {
            $available = $this->updates()->available($dep);
            $this->assertSame(['3.1.0-4558'], array_column($available, 'version'), $dep->slug);
            $this->assertTrue($available[0]['on_node'], $dep->slug);
        }
        // A deployment already on the newest tag has nothing newer: [] is then the right answer, not a lost list.
        $this->assertSame([], $this->updates()->available($this->deployment($org, $node5, 'newest', '3.1.0-4558')));
    }

    public function test_refresh_scans_the_node_and_returns_the_new_tag_in_the_same_call(): void
    {
        [$org, , $panel, $node5] = $this->nodes();
        $this->load($node5, '3.2.0-3011');
        $dep = $this->deployment($org, $node5, 'pmm', '3.2.0-3011');
        [$svc] = $this->member($org, 'enterprise_service');
        $token = $this->tokenFor($svc, ['enterprise.deploy']);

        // A first load starts a scan of both nodes; the new tag is not known yet.
        $this->api($token)->getJson('/api/v1/enterprise/deployments/ent_pmm/updates')->assertOk()->assertJsonPath('data.available', []);

        // The agents answer while Refresh waits (the panel's agent endpoint runs in another request in production).
        Sleep::fake();
        Sleep::whenFakingSleep(function () {
            AgentCommand::allTenants()->where('type', 'cron.exec')->whereIn('status', ['queued', 'sent'])->get()->each(function (AgentCommand $c) {
                $node5 = Server::allTenants()->where('name', 'livezen-node-05')->value('id');
                $out = $c->server_id === $node5 ? "worksuite-server:3.2.0-3011\nworksuite-server:3.4.0-4700\n" : "worksuite-server:3.2.0-3011\n";
                app(CommandBus::class)->applyResult($c, 'succeeded', 100, null, ['exit_code' => 0, 'output' => $out]);
            });
        });
        $res = $this->api($token)->withHeader('Idempotency-Key', 'r1')->postJson('/api/v1/enterprise/deployments/ent_pmm/updates/refresh')->assertOk();
        $this->assertSame(['3.4.0-4700'], array_column($res->json('data.available'), 'version'));
        $res->assertJsonPath('data.available.0.on_node', true)->assertJsonPath('data.latest', '3.4.0-4700');
        $this->assertEqualsCanonicalizing(['scanned', 'scanned'], array_column($res->json('data.refresh.nodes'), 'state'));
        Sleep::assertSleptTimes(1);

        // The fresh scan is what the next ordinary load shows too.
        $this->api($token)->getJson('/api/v1/enterprise/deployments/ent_pmm/updates')->assertOk()->assertJsonPath('data.available.0.version', '3.4.0-4700');
    }

    public function test_refresh_is_bounded_when_the_agent_does_not_answer(): void
    {
        [$org, , , $node5] = $this->nodes();
        $dep = $this->deployment($org, $node5, 'pmm', '3.2.0-3011');
        config(['enterprise.images_refresh_timeout' => 3, 'enterprise.images_refresh_poll_ms' => 500]);
        Sleep::fake();

        $out = $this->updates()->refresh($dep);
        Sleep::assertSleptTimes(6);
        $row = collect($out['refresh']['nodes'])->firstWhere('name', 'livezen-node-05');
        $this->assertSame('timeout', $row['state']);
        $this->assertStringContainsString('did not answer', $row['error']);
        $this->assertSame([], $out['available']);
    }

    public function test_the_scheduled_scan_asks_each_deployment_node_once_and_skips_offline_ones(): void
    {
        [$org, , $panel, $node5] = $this->nodes();
        $gone = $this->server($org, ['name' => 'old-node', 'last_heartbeat_at' => now()->subHour()]);
        $this->deployment($org, $node5, 'pmm', '3.2.0-3011');
        $this->deployment($org, $node5, 'computerage', '3.2.0-3011');
        $this->deployment($org, $gone, 'stale', '3.2.0-3011');

        $this->artisan('enterprise:images')->assertSuccessful();
        $scans = AgentCommand::allTenants()->where('type', 'cron.exec')->get();
        $this->assertEqualsCanonicalizing([$panel->id, $node5->id], $scans->pluck('server_id')->all());
        $this->assertStringContainsString('docker images', $scans->first()->payload['command']);

        // Still waiting for the agents: the next run queues nothing more.
        $this->artisan('enterprise:images')->assertSuccessful();
        $this->assertSame(2, AgentCommand::allTenants()->where('type', 'cron.exec')->count());

        // Once answered, the cache holds the node's tags.
        foreach ($scans as $c) {
            app(CommandBus::class)->applyResult($c, 'succeeded', 100, null, ['exit_code' => 0, 'output' => "worksuite-server:3.3.0-3200\n"]);
        }
        $this->assertSame(['worksuite-server:3.3.0-3200'], app(EnterpriseProvisioningService::class)->scannedTags($node5));
        $this->assertContains('enterprise:images', collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => (string) $e->command)
            ->map(fn ($c) => trim(Str::after($c, 'artisan'), " '\""))->all());
    }

    public function test_an_update_needs_the_tag_on_the_deployments_own_node(): void
    {
        [$org, $ownerToken, $panel, $node5] = $this->nodes();
        [$agent] = $this->enrollAgent($node5, $ownerToken);
        [$svc] = $this->member($org, 'enterprise_service');
        $token = $this->tokenFor($svc, ['enterprise.deploy']);
        config(['enterprise.require_loaded_image' => false]);
        $id = $this->api($token)->withHeader('Idempotency-Key', 'c1')->postJson('/api/v1/enterprise/deployments', [
            'external_id' => 'ent_pmm', 'customer_name' => 'PMM', 'slug' => 'pmm', 'version' => '3.2.0-3011', 'size' => 'small',
            'node_id' => $node5->id, 'callback_url' => 'https://cloud.test/hooks/enterprise',
        ])->assertStatus(202)->json('data.deployment_id');
        $this->assertSame($node5->id, EnterpriseDeployment::find($id)->server_id);
        $ack = function (array $types, array $output = []) use ($agent) {
            foreach (array_filter($this->heartbeat($agent)['commands'], fn ($c) => in_array($c['type'], $types, true)) as $c) {
                $this->commandResult($agent, $c['id'], 'succeeded', ['output' => $output ?: ['container_id' => 'c-'.$c['id']]])->assertOk();
            }
        };
        $ack(['container.create']);
        app(Tenant::class)->set(null);
        app(EnterpriseProvisioningService::class)->sync();
        $ack(['container.create']);
        $this->passHealthChecks($agent);
        app(Tenant::class)->set(null);
        app(EnterpriseProvisioningService::class)->sync();
        $this->load($panel, '3.3.0-3200'); // only on the tagged node
        $this->load($node5, '3.2.1-3100');
        $ack(['cron.exec'], ['exit_code' => 0, 'output' => "worksuite-server:3.2.1-3100\n"]); // node-05's scan

        $base = "/api/v1/enterprise/deployments/{$id}/updates";
        $state = $this->api($token)->getJson($base)->assertOk()->assertJsonPath('data.latest', '3.2.1-3100');
        $this->assertSame(['3.3.0-3200', '3.2.1-3100'], array_column($state->json('data.available'), 'version'));
        $this->assertSame([false, true], array_column($state->json('data.available'), 'on_node'));

        $err = $this->api($token)->withHeader('Idempotency-Key', 'u1')->postJson($base, ['version' => '3.3.0-3200'])->assertStatus(422)->json('error.fields.version.0');
        $this->assertStringContainsString('not on livezen-node-05', $err);
        $this->api($token)->withHeader('Idempotency-Key', 'u2')->postJson($base, ['version' => '3.2.1-3100'])->assertStatus(202)->assertJsonPath('data.status', 'backing_up');
    }
}
