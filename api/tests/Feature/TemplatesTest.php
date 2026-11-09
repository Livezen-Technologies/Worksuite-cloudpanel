<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ComposeProject;
use App\Models\EnvVar;
use App\Models\Template;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** TPL-1..6: manifests, generated variables, Compose templates, sync, updates and install checks. */
class TemplatesTest extends TestCase
{
    public function test_every_bundled_template_renders_and_validates(): void
    {
        $this->artisan('templates:validate')->assertSuccessful();
        $this->assertGreaterThanOrEqual(280, count(app(\App\Services\TemplateService::class)->bundled()), 'SRS 4.36: 280+ templates');
    }

    public function test_imported_coolify_templates_render_with_our_secrets_routing_and_no_host_ports(): void
    {
        $t = app(\App\Services\TemplateService::class);
        $imported = collect($t->bundled())->where('source', 'coolify');
        $this->assertGreaterThanOrEqual(200, $imported->count());
        foreach ($imported as $m) {
            $r = $t->render($m, 'demo', 'demo.example.com', []);
            $doc = \Symfony\Component\Yaml\Yaml::parse($r['compose']);
            $this->assertStringNotContainsString('$SERVICE_', $r['compose'], $m['id'].': a Coolify variable was left unfilled');
            $routed = collect($doc['services'])->filter(fn ($s) => ($s['labels']['traefik.http.routers.demo.rule'] ?? null) === 'Host(`demo.example.com`)');
            $this->assertCount(1, $routed, $m['id'].': exactly one service is routed');
            $this->assertSame((string) $m['port'], $routed->first()['labels']['traefik.http.services.demo.loadbalancer.server.port']);
            foreach ($doc['services'] as $name => $svc) {
                $this->assertArrayNotHasKey('ports', $svc, "{$m['id']}/{$name}: imported templates publish no host ports");
            }
        }
    }

    public function test_image_template_installs_with_generated_secrets_and_required_variables(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        $list = $this->api($token)->getJson('/api/v1/templates?category=Monitoring')->assertOk()->json('data');
        $grafana = collect($list)->firstWhere('id', 'grafana');
        $this->assertSame(['SERVICE_PASSWORD_GRAFANA'], $grafana['generated']);
        $this->assertSame('compose', $this->api($token)->getJson('/api/v1/templates/wordpress-mariadb')->json('data.type'));

        $res = $this->api($token)->postJson('/api/v1/templates/grafana/install', ['name' => 'metrics', 'server_id' => $server->id, 'domain' => 'grafana.acme.test'])->assertCreated()->json('data');
        $app = Application::allTenants()->find($res['resource_id']);
        $this->assertSame(['grafana/grafana:11.3.0', 3000, '/api/health'], [$app->image, $app->port, $app->health_path]);
        $env = EnvVar::allTenants()->where('application_id', $app->id)->get()->keyBy('key');
        $this->assertSame('https://grafana.acme.test', $env['GF_SERVER_ROOT_URL']->value);
        $this->assertSame(32, strlen($env['GF_SECURITY_ADMIN_PASSWORD']->value));
        $this->assertTrue($env['GF_SECURITY_ADMIN_PASSWORD']->secret);
        $this->assertFalse($env['GF_SECURITY_ADMIN_USER']->secret);

        $this->api($token)->postJson('/api/v1/templates/phpmyadmin/install', ['name' => 'pma'])->assertStatus(422)->assertSee('PMA_HOST');
        $this->api($token)->postJson('/api/v1/templates/phpmyadmin/install', ['name' => 'pma', 'variables' => ['PMA_HOST' => 'db:3306']])->assertCreated();
        $this->assertSame('db:3306', EnvVar::allTenants()->where('key', 'PMA_HOST')->value('value'));
        $this->api($token)->postJson('/api/v1/templates/grafana/install', ['name' => 'metrics'])->assertStatus(422);
    }

    public function test_compose_template_renders_one_password_for_both_services_and_deploys(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $this->api($token)->postJson('/api/v1/templates/wordpress-mariadb/install', ['name' => 'blog'])->assertStatus(422);
        $this->api($token)->postJson('/api/v1/templates/wordpress-mariadb/install', ['name' => 'blog', 'server_id' => $server->id, 'domain' => 'blog.acme.test'])->assertCreated();

        $this->assertNotNull(ComposeProject::allTenants()->where('name', 'blog')->first());
        $up = collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'compose.up');
        $yaml = $up['payload']['compose_yaml'];
        $this->assertStringContainsString('Host(`blog.acme.test`)', $yaml);
        $this->assertStringContainsString('traefik.http.routers.blog.rule', $yaml);
        $this->assertStringNotContainsString('${SERVICE_', $yaml);
        preg_match_all('/WORDPRESS_DB_PASSWORD: (\S+)|MARIADB_PASSWORD: (\S+)/', $yaml, $m);
        $this->assertSame(array_filter($m[1])[0] ?? null, array_values(array_filter($m[2]))[0] ?? null, 'the app and its database get the same generated password');
    }

    public function test_updates_keep_generated_values_and_sync_takes_newer_remote_versions(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret);
        $install = $this->api($token)->postJson('/api/v1/templates/grafana/install', ['name' => 'metrics', 'server_id' => $server->id])->assertCreated()->json('data');
        $password = EnvVar::allTenants()->where('key', 'GF_SECURITY_ADMIN_PASSWORD')->value('value');
        $this->assertFalse($this->api($token)->getJson('/api/v1/template-installs')->json('data.0.update_available'));
        $this->api($token)->postJson("/api/v1/template-installs/{$install['id']}/update")->assertStatus(409);

        Http::fake(['templates.example.com/*' => Http::response(['templates' => [
            ['id' => 'grafana', 'name' => 'Grafana', 'category' => 'Monitoring', 'version' => 2, 'image' => 'grafana/grafana:11.4.0', 'port' => 3000, 'health' => '/api/health',
                'env' => ['GF_SECURITY_ADMIN_PASSWORD' => '${SERVICE_PASSWORD_GRAFANA}', 'GF_LOG_LEVEL' => 'warn']],
            ['id' => 'stale-ghost', 'name' => 'Ghost', 'version' => 1, 'image' => 'ghost:4'],
            ['id' => 'BAD id', 'name' => 'x', 'version' => 1, 'image' => 'x:1'],
        ]])]);
        config(['platform.templates.remote_url' => 'https://templates.example.com/index.json']);
        $this->api($token)->postJson('/api/v1/templates-sync')->assertOk()->assertJsonPath('data.remote', 2)->assertJsonCount(1, 'data.invalid');
        $this->assertSame('remote', Template::find('grafana')->source);

        $row = $this->api($token)->getJson('/api/v1/template-installs')->json('data.0');
        $this->assertSame([true, 1, 2], [$row['update_available'], $row['version'], $row['latest_version']]);
        $this->api($token)->postJson("/api/v1/template-installs/{$install['id']}/update")->assertStatus(202)->assertJsonPath('data.version', 2);
        $app = Application::allTenants()->find($install['resource_id']);
        $this->assertSame('grafana/grafana:11.4.0', $app->image);
        $this->assertSame($password, EnvVar::allTenants()->where('application_id', $app->id)->where('key', 'GF_SECURITY_ADMIN_PASSWORD')->value('value'), 'generated secrets survive updates');
        $this->assertSame('warn', EnvVar::allTenants()->where('application_id', $app->id)->where('key', 'GF_LOG_LEVEL')->value('value'));
        $this->assertNotNull(collect($this->heartbeat($secret)['commands'])->firstWhere('type', 'container.create'));
    }
}
