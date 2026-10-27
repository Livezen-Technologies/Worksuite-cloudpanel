<?php

namespace Tests\Feature;

use App\Contracts\DnsResolver;
use App\Models\Application;
use App\Support\Tenant;
use Tests\TestCase;

class DomainTest extends TestCase
{
    public function test_customer_domain_verification_and_certificate(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org, ['ip' => '198.51.100.7']);
        $app = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'site', 'kind' => 'customer', 'source_type' => 'image', 'image' => 'nginx', 'size' => 'small', 'server_id' => $server->id, 'port' => 80]));

        $domain = $this->api($token)->postJson('/api/v1/app/domains', ['domain' => 'Example.com', 'app_id' => $app->id, 'redirect_www' => true])->assertCreated()
            ->assertJsonPath('data.records.0', ['type' => 'A', 'name' => 'example.com', 'value' => '198.51.100.7'])
            ->assertJsonPath('data.records.1', ['type' => 'CNAME', 'name' => 'www.example.com', 'value' => 'example.com'])
            ->json('data');

        $verify = $this->api($token)->postJson("/api/v1/app/domains/{$domain['id']}/verify")->assertOk();
        $verify->assertJsonPath('data.records.0.status', 'waiting')->assertJsonPath('data.https_status', 'pending');

        $dns = app(DnsResolver::class);
        $dns->set('example.com', 'A', ['198.51.100.7']);
        $this->api($token)->postJson("/api/v1/app/domains/{$domain['id']}/verify")->assertOk()
            ->assertJsonPath('data.records.0.status', 'verified')->assertJsonPath('data.records.1.status', 'waiting')->assertJsonPath('data.https_status', 'pending');

        $dns->set('www.example.com', 'CNAME', ['example.com.']);
        $this->api($token)->postJson("/api/v1/app/domains/{$domain['id']}/verify")->assertOk()
            ->assertJsonPath('data.records.1.status', 'verified')->assertJsonPath('data.https_status', 'active');

        $this->api($token)->getJson('/api/v1/app/domains')->assertOk()->assertJsonPath('data.0.https_status', 'active')->assertJsonPath('data.0.dns_status', 'verified')
            ->assertJsonStructure(['data' => [['id', 'domain', 'app_id', 'dns_status', 'https_status', 'renewal_at', 'certificate' => ['expires_at']]]]);
    }

    public function test_domain_used_by_another_org_is_refused(): void
    {
        [, $orgA, $tokenA] = $this->org();
        [, , $tokenB] = $this->org();
        $this->api($tokenA)->postJson('/api/v1/domains', ['name' => 'taken.example.com'])->assertCreated();
        $this->api($tokenB)->postJson('/api/v1/domains', ['name' => 'taken.example.com'])->assertStatus(409)->assertJsonPath('error.code', 'conflict');
        $this->api($tokenA)->postJson('/api/v1/domains', ['name' => 'taken.example.com'])->assertStatus(409);
        $this->api($tokenA)->postJson('/api/v1/domains', ['name' => 'evil.livezen.app'])->assertStatus(422);
    }

    public function test_certificate_requires_verified_dns(): void
    {
        [, , $token] = $this->org();
        $id = $this->api($token)->postJson('/api/v1/domains', ['name' => 'pending.example.com'])->json('data.id');
        $this->api($token)->postJson("/api/v1/domains/{$id}/certificate")->assertStatus(409);
        $this->api($token)->getJson('/api/v1/domains?filter=pending_dns')->assertOk()->assertJsonPath('meta.total', 1);
    }
}
