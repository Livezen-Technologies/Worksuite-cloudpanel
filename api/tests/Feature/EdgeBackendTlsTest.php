<?php

namespace Tests\Feature;

use App\Models\AgentCommand;
use App\Models\Application;
use App\Models\Container;
use App\Models\Domain;
use App\Models\EdgeTlsCert;
use App\Models\Server;
use App\Services\CommandBus;
use App\Services\EdgeRoutingService;
use App\Services\EdgeTlsService;
use App\Services\TraefikConfigService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XNODE-3b: verified HTTPS on the edge → app-node hop through one ws-edge-tls terminator per
 * app node, with certificates from the panel's internal CA.
 */
class EdgeBackendTlsTest extends TestCase
{
    /** [token, edge, node] */
    private function fleet(): array
    {
        config(['platform.drivers.certificates' => 'traefik']);
        [, $org, $token] = $this->org();
        $edge = $this->server($org, ['name' => 'frontdoor-01', 'ip' => '51.254.220.203', 'tags' => ['ingress', 'enterprise-edge']]);
        $node = $this->server($org, ['name' => 'node-05', 'ip' => '203.0.113.5']);

        return [$token, $edge, $node];
    }

    private function app(string $token, Server $server, string $name = 'shop'): Application
    {
        $id = $this->api($token)->postJson('/api/v1/applications', ['name' => $name, 'source_type' => 'image', 'image' => 'ghcr.io/acme/shop:1.0', 'server_id' => $server->id, 'port' => 8080])->assertCreated()->json('data.id');
        $this->api($token)->postJson('/api/v1/domains', ['name' => "{$name}.acme.test", 'application_id' => $id, 'target_port' => 8080])->assertCreated();
        Domain::allTenants()->where('name', "{$name}.acme.test")->update(['dns_status' => 'verified']);
        $app = Application::allTenants()->findOrFail($id);
        $app->update(['source' => array_merge((array) $app->source, ['enterprise_deployment_id' => 'dep-1'])]);

        return $app->fresh();
    }

    private function yaml(Server $edge): string
    {
        return app(TraefikConfigService::class)->yaml($edge, true);
    }

    private function http(Server $edge): array
    {
        return json_decode(json_encode(app(TraefikConfigService::class)->render($edge)), true)['http'] ?? [];
    }

    /** Runs every queued edge.tls command as succeeded, as the agent would report it. */
    private function agentSucceeds(Server $node): void
    {
        foreach (AgentCommand::allTenants()->where('server_id', $node->id)->whereNotNull('job_id')->where('status', '!=', 'succeeded')->orderBy('created_at')->orderBy('id')->get() as $c) {
            app(CommandBus::class)->applyResult($c, 'succeeded', 100, null, null);
        }
    }

    private function publishPlain(Application $app): void
    {
        Container::allTenants()->create(['server_id' => $app->server_id, 'application_id' => $app->id, 'name' => $app->containerName(), 'image' => 'x', 'state' => 'running',
            'spec' => ['ports' => [['container_port' => 8080, 'host_port' => app(EdgeRoutingService::class)->port($app)]]]]);
    }

    public function test_flag_off_renders_exactly_as_before(): void
    {
        [$token, $edge, $node] = $this->fleet();
        $app = $this->app($token, $node);
        $port = app(EdgeRoutingService::class)->port($app);
        $before = $this->yaml($edge);

        $this->assertStringContainsString("http://203.0.113.5:{$port}", $before);
        $this->assertStringNotContainsString('serversTransport', $before);
        $this->assertStringNotContainsString('https://203.0.113.5', $before);
        $this->assertFalse(app(EdgeTlsService::class)->syncNode($node));
        $this->assertSame(0, AgentCommand::allTenants()->where('type', 'container.create')->where('server_id', $node->id)->count());
        $this->assertSame([['port' => $port, 'allow' => ['51.254.220.203']]], app(EdgeRoutingService::class)->firewallFor($node)['rules']);
        $this->assertArrayNotHasKey('edge_tls_port', (array) $app->fresh()->source);
        $this->assertSame(0, EdgeTlsCert::count(), 'no CA is even generated');
        $this->assertSame($before, $this->yaml($edge), 'stable');
    }

    public function test_flag_on_keeps_plain_until_the_terminator_is_confirmed_then_switches_to_verified_https(): void
    {
        [$token, $edge, $node] = $this->fleet();
        $app = $this->app($token, $node);
        $this->publishPlain($app);
        $plain = $this->yaml($edge);

        config(['platform.edge.backend_tls' => true]);
        $this->assertSame($plain, $this->yaml($edge), 'migration: nothing changes on the edge before the terminator is live');

        $this->assertTrue(app(EdgeTlsService::class)->syncNode($node));
        $this->agentSucceeds($node);

        $tlsPort = app(EdgeRoutingService::class)->tlsPort($app->fresh());
        $this->assertGreaterThanOrEqual(41000, $tlsPort);
        $this->assertLessThan(51000, $tlsPort);
        $http = $this->http($edge);
        $lb = $http['services']['app-shop']['loadBalancer'];
        $this->assertSame([['url' => "https://203.0.113.5:{$tlsPort}"]], $lb['servers']);
        $this->assertSame('dmp-edge-tls-'.$node->id, $lb['serversTransport']);
        $this->assertSame('/', $lb['healthCheck']['path'], 'health check unchanged');
        $t = $http['serversTransports']['dmp-edge-tls-'.$node->id];
        $this->assertSame($node->id.'.edge.internal', $t['serverName']);
        $this->assertSame([EdgeTlsCert::where('kind', 'ca')->sole()->cert_pem], $t['rootCAs']);
        $this->assertStringContainsString('BEGIN CERTIFICATE', $t['rootCAs'][0]);
        $this->assertArrayNotHasKey('insecureSkipVerify', $t);
        $this->assertStringNotContainsString('insecureSkipVerify', $this->yaml($edge));
    }

    public function test_the_node_certificate_chains_to_the_ca_with_the_right_names(): void
    {
        [$token, , $node] = $this->fleet();
        $this->app($token, $node);
        config(['platform.edge.backend_tls' => true]);
        $tls = app(EdgeTlsService::class);
        $cert = $tls->nodeCert($node);
        $ca = $tls->ca();

        $this->assertSame(1, openssl_x509_verify($cert->cert_pem, openssl_pkey_get_public($ca->cert_pem)));
        $x = openssl_x509_parse($cert->cert_pem);
        $this->assertStringContainsString('DNS:'.$node->id.'.edge.internal', $x['extensions']['subjectAltName']);
        $this->assertStringContainsString('IP Address:203.0.113.5', $x['extensions']['subjectAltName']);
        $this->assertStringContainsString('TLS Web Server Authentication', $x['extensions']['extendedKeyUsage']);
        $this->assertStringContainsString('CA:TRUE', openssl_x509_parse($ca->cert_pem)['extensions']['basicConstraints']);
        $this->assertTrue(openssl_x509_check_private_key($cert->cert_pem, $cert->key_encrypted));
        $this->assertEqualsWithDelta(365, now()->diffInDays($cert->not_after), 1);
        $this->assertSame($cert->id, $tls->nodeCert($node)->id);
        $this->assertSame($cert->serial, $tls->nodeCert($node)->serial, 'not reissued while valid');

        $node->update(['ip' => '203.0.113.6']);
        $this->assertNotSame($cert->serial, $tls->nodeCert($node->fresh())->serial, 'a new IP gets a new certificate');
    }

    public function test_private_keys_are_never_stored_in_plain_text(): void
    {
        [$token, , $node] = $this->fleet();
        $this->app($token, $node);
        $tls = app(EdgeTlsService::class);
        $tls->nodeCert($node);

        foreach (DB::table('edge_tls_certs')->get() as $row) {
            $this->assertStringNotContainsString('PRIVATE KEY', $row->key_encrypted);
            $this->assertStringContainsString('PRIVATE KEY', decrypt($row->key_encrypted, false));
            $this->assertStringContainsString('BEGIN CERTIFICATE', $row->cert_pem);
        }
        $this->assertArrayNotHasKey('key_encrypted', $tls->ca()->toArray(), 'hidden from serialisation');
    }

    public function test_the_terminator_serves_the_app_routes_and_is_filtered_like_the_plain_port(): void
    {
        [$token, , $node] = $this->fleet();
        $app = $this->app($token, $node);
        $two = $this->app($token, $node, 'blog');
        config(['platform.edge.backend_tls' => true]);
        $tls = app(EdgeTlsService::class);
        $routing = app(EdgeRoutingService::class);

        $this->assertTrue($tls->syncNode($node));
        $this->assertFalse($tls->syncNode($node), 'unchanged: nothing resent');
        $cmds = AgentCommand::allTenants()->where('server_id', $node->id)->orderBy('created_at')->orderBy('id')->get();
        $caddy = $cmds->first(fn ($c) => $c->type === 'fs.write' && $c->payload['path'] === 'Caddyfile')->payload['content'];
        foreach ([$app, $two] as $a) {
            $p = $routing->tlsPort($a->fresh());
            $this->assertStringContainsString("https://:{$p} {", $caddy);
            $this->assertStringContainsString('reverse_proxy '.$a->containerName().':8080', $caddy);
        }
        $this->assertStringContainsString('auto_https off', $caddy);
        $this->assertStringContainsString('trusted_proxies static 51.254.220.203/32', $caddy);

        $create = $cmds->firstWhere('type', 'container.create')->payload;
        $this->assertSame(EdgeTlsService::CONTAINER, $create['name']);
        $this->assertContains('--watch', $create['cmd']);
        $this->assertEqualsCanonicalizing([$routing->tlsPort($app->fresh()), $routing->tlsPort($two->fresh())], array_column($create['ports'], 'host_port'));
        $this->assertSame('proxy', $create['networks'][0]['name']);
        $key = $cmds->first(fn ($c) => $c->type === 'fs.write' && str_ends_with($c->payload['path'], '.key'));
        $this->assertTrue($key->payload['create'], 'a new key file, never an overwrite (no file-history snapshot)');
        $this->assertTrue($cmds->contains(fn ($c) => $c->type === 'fs.chmod' && $c->payload['paths'] === [$key->payload['path']] && $c->payload['mode'] === '0600'));
        $this->assertLessThan($cmds->search(fn ($c) => $c->type === 'container.create'), $cmds->search(fn ($c) => ($c->payload['path'] ?? '') === 'Caddyfile'));

        $rules = collect($routing->firewallFor($node)['rules'])->keyBy('port');
        $this->assertCount(4, $rules, 'plain + TLS port per app');
        $this->assertSame(['51.254.220.203'], $rules[$routing->tlsPort($app->fresh())]['allow']);
    }

    public function test_a_route_change_reloads_without_recreating_only_a_new_port_set_recreates(): void
    {
        [$token, , $node] = $this->fleet();
        $app = $this->app($token, $node);
        config(['platform.edge.backend_tls' => true]);
        $tls = app(EdgeTlsService::class);
        $tls->syncNode($node);
        $this->agentSucceeds($node);
        $creates = fn () => AgentCommand::allTenants()->where('server_id', $node->id)->where('type', 'container.create')->count();
        $this->assertSame(1, $creates());

        $app->update(['port' => 9090]);
        $this->assertTrue($tls->syncNode($node));
        $this->assertSame(1, $creates(), 'Caddy --watch reloads the new route in place');

        $this->app($token, $node, 'blog');
        $this->assertTrue($tls->syncNode($node));
        $this->assertSame(2, $creates(), 'a new port needs the container recreated');
    }

    public function test_a_deploy_with_tls_on_publishes_no_plain_port_and_syncs_the_terminator(): void
    {
        [$token, , $node] = $this->fleet();
        $app = $this->app($token, $node);
        config(['platform.edge.backend_tls' => true]);
        $this->api($token)->postJson('/api/v1/deployments', ['application_id' => $app->id])->assertStatus(202);

        $appCreate = AgentCommand::allTenants()->where('server_id', $node->id)->where('type', 'container.create')->get()->first(fn ($c) => $c->payload['name'] === $app->containerName());
        $this->assertEmpty($appCreate->payload['ports'] ?? []);
        $this->assertTrue(AgentCommand::allTenants()->where('server_id', $node->id)->where('type', 'container.create')->get()->contains(fn ($c) => $c->payload['name'] === EdgeTlsService::CONTAINER));
    }

    public function test_renewal_reissues_within_the_window_and_is_scheduled(): void
    {
        [$token, , $node] = $this->fleet();
        $this->app($token, $node);
        config(['platform.edge.backend_tls' => true]);
        $tls = app(EdgeTlsService::class);
        $tls->syncNode($node);
        $cert = EdgeTlsCert::where('kind', 'node')->sole();

        $this->assertSame([], $tls->renewDue(), 'not due');
        $cert->update(['not_after' => now()->addDays(29)]);
        $this->assertSame(['node-05'], $tls->renewDue());
        $fresh = $cert->fresh();
        $this->assertNotSame($cert->serial, $fresh->serial);
        $this->assertTrue($fresh->not_after->gt(now()->addDays(300)));
        $this->assertTrue(AgentCommand::allTenants()->where('type', 'fs.write')->get()->contains(fn ($c) => $c->payload['path'] === "node-{$fresh->serial}.key"), 'pushed to the node');

        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => $e->command)->implode(' ');
        $this->assertStringContainsString('edge:tls-renew', $events);
        $this->assertSame(0, Artisan::call('edge:tls-renew'));
    }

    public function test_a_confirmed_key_is_sent_once_per_serial_so_a_resync_succeeds(): void
    {
        [$token, , $node] = $this->fleet();
        $this->app($token, $node);
        config(['platform.edge.backend_tls' => true]);
        $tls = app(EdgeTlsService::class);
        $keyWrites = fn (string $serial) => AgentCommand::allTenants()->where('type', 'fs.write')->get()
            ->filter(fn ($c) => ($c->payload['path'] ?? null) === "node-{$serial}.key")->count();

        $tls->syncNode($node);
        $cert = EdgeTlsCert::where('kind', 'node')->sole();
        $this->assertSame(1, $keyWrites($cert->serial));
        $this->agentSucceeds($node);
        $this->assertSame($cert->serial, $cert->fresh()->key_delivered_serial, 'recorded once the agent confirmed the job');

        // A forced resync (the live failure: "node-<serial>.key already exists") must skip the key.
        $this->assertTrue($tls->syncNode($node, true));
        $this->assertSame(1, $keyWrites($cert->serial), 'the create-only key is not sent again');
        $this->agentSucceeds($node);
        $job = \App\Models\Job::allTenants()->where('type', 'edge.tls')->latest('created_at')->latest('id')->first();
        $this->assertSame('succeeded', $job->status);

        // A renewed certificate has a new serial, so its key goes out again, once.
        $cert->update(['not_after' => now()->addDays(10)]);
        $tls->renewDue();
        $fresh = $cert->fresh();
        $this->assertNotSame($cert->serial, $fresh->serial);
        $this->assertSame(1, $keyWrites($fresh->serial));
    }
}
