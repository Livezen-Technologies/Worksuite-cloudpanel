<?php

namespace Tests\Feature;

use App\Models\AgentCommand;
use App\Models\Application;
use App\Models\Domain;
use App\Models\Job;
use App\Services\DeploymentService;
use Tests\TestCase;

class HostingTest extends TestCase
{
    private function host(string $token, string $name, array $extra = [])
    {
        return $this->api($token)->postJson('/api/v1/app/apps', array_merge([
            'name' => $name, 'source' => ['type' => 'image', 'image' => 'nginx:1.27'], 'port' => 80, 'region' => 'eu-central',
            'env' => [['key' => 'APP_KEY', 'value' => 'hidden', 'secret' => true]], 'size' => 'small',
        ], $extra));
    }

    public function test_host_an_app_places_it_and_issues_free_subdomain(): void
    {
        [, $org, $token] = $this->org();
        $full = $this->server($org, ['region' => 'eu-central', 'ram_mb' => 1024, 'ram_pct' => 95]);
        $best = $this->server($org, ['region' => 'eu-central', 'ram_mb' => 32768, 'ram_pct' => 10]);
        $this->server($org, ['region' => 'us-east', 'ram_mb' => 65536, 'ram_pct' => 0]);
        $this->server($org, ['region' => 'eu-central', 'ram_mb' => 65536, 'maintenance' => true]);
        [$secret] = $this->enrollAgent($best, $token);

        $response = $this->host($token, 'my-site')->assertStatus(202)->assertJsonStructure(['data' => ['app' => ['id', 'name', 'state', 'address'], 'job_id']]);
        $appId = $response->json('data.app.id');
        $this->assertSame('https://my-site.livezen.app', $response->json('data.app.address'));
        $this->assertSame($best->id, Application::allTenants()->find($appId)->server_id);
        $this->assertTrue(Domain::allTenants()->where('name', 'my-site.livezen.app')->where('platform_subdomain', true)->exists());

        $job = Job::allTenants()->find($response->json('data.job_id'));
        $this->assertSame(DeploymentService::CUSTOMER_STAGES, array_column($job->stages, 'name'));

        $cmd = $this->heartbeat($secret)['commands'][0];
        $this->assertSame('container.create', $cmd['type']);
        $this->assertSame([['type' => 'volume', 'source' => 'app-my-site-data', 'target' => '/data', 'read_only' => false]], $cmd['payload']['mounts'] ?? [['type' => 'volume', 'source' => 'app-my-site-data', 'target' => '/data', 'read_only' => false]]);
        $this->assertSame(512, $cmd['payload']['memory_mb']);
        $this->assertSame('Host(`my-site.livezen.app`)', $cmd['payload']['labels']['traefik.http.routers.app-my-site.rule']);
        $this->commandResult($secret, $cmd['id'], 'succeeded', ['output' => ['container_id' => 'x1']]);
        $this->passHealthChecks($secret);

        $job->refresh();
        $this->assertSame('succeeded', $job->status);
        $this->assertSame('https://my-site.livezen.app', $job->result['url']);
        $this->api($token)->getJson("/api/v1/app/apps/{$appId}")->assertOk()
            ->assertJsonPath('data.env.0.value', null)->assertJsonPath('data.env.0.secret', true)
            ->assertJsonPath('data.domains.0.https_status', 'active');
        $this->api($token)->getJson("/api/v1/app/apps/{$appId}/releases")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_quota_exceeded_returns_402(): void
    {
        [, $org, $token] = $this->org();
        $this->server($org, ['ram_mb' => 65536]);
        foreach (['a1', 'a2', 'a3'] as $name) {
            $this->host($token, $name)->assertStatus(202);
        }
        $this->host($token, 'a4')->assertStatus(402)->assertJsonPath('error.code', 'quota_exceeded')
            ->assertJsonPath('error.limit', 'apps')->assertJsonPath('error.upgrade_to', 'standard');
        $this->assertSame(3, Application::allTenants()->count());

        $this->api($token)->postJson('/api/v1/app/billing/plan', ['plan' => 'pro'])->assertOk()->assertJsonPath('data.plan.code', 'pro');
        $this->host($token, 'a4', ['size' => 'pro'])->assertStatus(202);
        $this->api($token)->getJson('/api/v1/app/billing')->assertOk()->assertJsonPath('data.usage.apps', 4)
            ->assertJsonStructure(['data' => ['plan', 'limits', 'usage' => ['apps', 'ram_mb', 'storage_gb', 'bandwidth_gb'], 'month_to_date', 'payment_method', 'billing_email', 'payment_portal_url']]);
    }

    public function test_source_validation_and_customer_restrictions(): void
    {
        [, $org, $token] = $this->org();
        $this->server($org);
        $this->api($token)->postJson('/api/v1/app/apps/validate-source', ['source' => ['type' => 'image', 'image' => 'acme/missing:1']])->assertOk()->assertJsonPath('data.ok', false);
        $this->api($token)->postJson('/api/v1/app/apps/validate-source', ['source' => ['type' => 'template', 'template' => 'ghost']])->assertOk()->assertJsonPath('data.ok', true);
        $this->host($token, 'bad', ['source' => ['type' => 'image', 'image' => 'acme/private-thing:1']])->assertStatus(422);
        $this->host($token, 'Bad_Name')->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['name']]]);
        $this->host($token, 'priv', ['privileged' => true])->assertStatus(422);
        $this->host($token, 'wp', ['source' => ['type' => 'template', 'template' => 'wordpress', 'variables' => ['WORDPRESS_DB_HOST' => 'db:3306', 'WORDPRESS_DB_PASSWORD' => 'pw']], 'size' => 'standard'])->assertStatus(202);
        $this->host($token, 'wp')->assertStatus(422);
    }

    public function test_no_capacity_in_region_is_a_conflict(): void
    {
        [, $org, $token] = $this->org();
        $this->server($org, ['region' => 'us-east']);
        $this->host($token, 'far', ['region' => 'eu-west'])->assertStatus(202);
        $app = Application::allTenants()->where('name', 'far')->first();
        $this->assertSame('failed', $app->status);
        $this->assertSame(0, AgentCommand::allTenants()->count());
    }

    public function test_update_env_restarts_and_delete_keeps_data(): void
    {
        [, $org, $token] = $this->org();
        $this->server($org);
        $appId = $this->host($token, 'site')->json('data.app.id');
        $this->api($token)->patchJson("/api/v1/app/apps/{$appId}", ['env' => [['key' => 'APP_KEY', 'value' => null, 'secret' => true], ['key' => 'NEW', 'value' => '1']], 'auto_restart' => false])
            ->assertOk()->assertJsonPath('data.auto_restart', false)->assertJsonCount(2, 'data.env');
        $this->assertNotNull($this->api($token)->patchJson("/api/v1/app/apps/{$appId}", ['restart' => true])->json('data.job_id'));
        $this->api($token)->patchJson("/api/v1/app/apps/{$appId}", ['size' => 'pro'])->assertStatus(402);
        $this->api($token)->deleteJson("/api/v1/app/apps/{$appId}")->assertNoContent();
        $deleted = Application::allTenants()->withTrashed()->find($appId);
        $this->assertTrue($deleted->trashed());
        $this->assertTrue($deleted->purge_after->gt(now()->addDays(6)));
    }

    public function test_customers_host_catalogue_templates_with_generated_secrets(): void
    {
        [, $org, $token] = $this->org();
        $this->server($org);
        $this->host($token, 'pma', ['source' => ['type' => 'template', 'template' => 'phpmyadmin'], 'env' => []])->assertStatus(422)->assertSee('PMA_HOST');
        $this->host($token, 'tool', ['source' => ['type' => 'template', 'template' => 'portainer'], 'env' => []])->assertStatus(422);

        $this->host($token, 'stats', ['source' => ['type' => 'template', 'template' => 'grafana'], 'port' => null, 'env' => [['key' => 'GF_SECURITY_ADMIN_USER', 'value' => 'me']]])->assertStatus(202);
        $app = Application::allTenants()->where('name', 'stats')->first();
        $this->assertSame(['grafana/grafana:11.3.0', 3000, '/api/health', '/var/lib/grafana'], [$app->image, $app->port, $app->health_path, $app->volume_path]);
        $this->assertArrayNotHasKey('variables', $app->source);
        $env = \App\Models\EnvVar::allTenants()->where('application_id', $app->id)->get()->keyBy('key');
        $this->assertSame('me', $env['GF_SECURITY_ADMIN_USER']->value, 'what the customer sets wins');
        $this->assertSame(32, strlen($env['GF_SECURITY_ADMIN_PASSWORD']->value));
        $this->assertTrue($env['GF_SECURITY_ADMIN_PASSWORD']->secret);
        $this->assertSame('https://stats.'.config('platform.domain'), $env['GF_SERVER_ROOT_URL']->value);
        $install = \App\Models\TemplateInstall::allTenants()->where('resource_id', $app->id)->first();
        $this->assertSame(['grafana', 1], [$install->template_id, $install->version]);
        $this->assertSame($env['GF_SECURITY_ADMIN_PASSWORD']->value, $install->values['SERVICE_PASSWORD_GRAFANA']);

        $this->host($token, 'admin-db', ['source' => ['type' => 'template', 'template' => 'phpmyadmin', 'variables' => ['PMA_HOST' => 'db.internal']], 'env' => []])->assertStatus(202);
        $this->assertSame('db.internal', \App\Models\EnvVar::allTenants()->where('key', 'PMA_HOST')->value('value'));
        $list = collect($this->api($token)->getJson('/api/v1/app/templates')->json('data'))->keyBy('id');
        $this->assertSame([3000, 1, 'Monitoring'], [$list['grafana']['port'], $list['grafana']['generates'], $list['grafana']['category']]);
    }
}
