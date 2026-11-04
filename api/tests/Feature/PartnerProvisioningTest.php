<?php

namespace Tests\Feature;

use App\Models\AgentCommand;
use App\Models\Application;
use App\Models\Membership;
use App\Models\PartnerClient;
use App\Models\PartnerDeployment;
use App\Models\PartnerWebhook;
use App\Services\PartnerProvisioningService;
use App\Services\PartnerWebhookService;
use App\Support\PartnerSignature;
use App\Support\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerProvisioningTest extends TestCase
{
    private PartnerClient $client;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = Str::random(64);
        $this->client = PartnerClient::create([
            'name' => 'WorkSuite Cloud', 'key_id' => 'lzp_test', 'secret' => $this->secret, 'webhook_secret' => 'whsec-test',
            'webhook_url' => 'https://worksuitecloud.test/livezen/v1/webhook', 'scopes' => config('partner.scopes'),
        ]);
    }

    /** A signed partner request, exactly as WorkSuite Cloud's client builds it. */
    private function partner(string $method, string $uri, array $body = [], array $headers = [], ?string $secret = null, ?int $ts = null)
    {
        app(Tenant::class)->set(null);
        $this->app['auth']->forgetGuards();
        $json = $method === 'GET' ? '' : json_encode($body);
        $ts = (string) ($ts ?? time());
        $nonce = Str::random(24);
        $sig = PartnerSignature::sign($secret ?? $this->secret, PartnerSignature::canonical($ts, $method, $uri, $json, $nonce));
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_LIVEZEN_KEY' => 'lzp_test', 'HTTP_X_LIVEZEN_TIMESTAMP' => $ts,
            'HTTP_X_LIVEZEN_NONCE' => $nonce, 'HTTP_X_LIVEZEN_SIGNATURE' => $sig];
        foreach ($headers as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return $this->call($method, $uri, [], [], [], $server, $json);
    }

    private function deployBody(array $extra = []): array
    {
        return array_merge([
            'external_id' => 'dep_1', 'product' => 'business', 'account' => ['id' => 'acct_42', 'name' => 'Acme Ltd'],
            'workspace_name' => 'Acme', 'subdomain' => 'acme', 'region' => 'eu-central', 'storage_gb' => 8,
            'admin' => ['email' => 'owner@acme.test', 'name' => 'Ada Owner'],
            'sso' => ['issuer' => 'https://worksuitecloud.com/oauth', 'client_id' => 'ws-inst-1', 'client_secret' => 's3cret'],
        ], $extra);
    }

    public function test_rejects_unsigned_bad_signature_stale_and_replayed_requests(): void
    {
        $this->getJson('/partner/v1/deployments')->assertStatus(401);
        $this->partner('GET', '/partner/v1/deployments', [], [], 'wrong-secret')->assertStatus(401);
        $this->partner('GET', '/partner/v1/deployments', [], [], null, time() - 3600)->assertStatus(401);
        $this->partner('GET', '/partner/v1/deployments')->assertOk();

        $ts = (string) time();
        $nonce = Str::random(24);
        $sig = PartnerSignature::sign($this->secret, PartnerSignature::canonical($ts, 'GET', '/partner/v1/summary', '', $nonce));
        $h = ['X-Livezen-Key' => 'lzp_test', 'X-Livezen-Timestamp' => $ts, 'X-Livezen-Nonce' => $nonce, 'X-Livezen-Signature' => $sig, 'Accept' => 'application/json'];
        $this->withHeaders($h)->get('/partner/v1/summary')->assertOk();
        $this->withHeaders($h)->get('/partner/v1/summary')->assertStatus(401);
    }

    public function test_scope_is_enforced(): void
    {
        $this->client->update(['scopes' => ['deployments:read']]);
        $this->partner('POST', '/partner/v1/deployments', $this->deployBody(), ['Idempotency-Key' => 'k1'])->assertStatus(403);
    }

    public function test_post_requires_idempotency_key(): void
    {
        $this->partner('POST', '/partner/v1/deployments', $this->deployBody())->assertStatus(422);
    }

    public function test_deploys_worksuite_into_an_isolated_partner_org_and_reports_status(): void
    {
        [, $platform, $token] = $this->org();
        $server = $this->server($platform, ['shared_pool' => true, 'ram_mb' => 65536]);
        [$agentSecret] = $this->enrollAgent($server, $token);

        $res = $this->partner('POST', '/partner/v1/deployments', $this->deployBody(), ['Idempotency-Key' => 'deploy-dep_1'])->assertStatus(202);
        $res->assertJsonPath('data.status', 'provisioning')->assertJsonPath('data.product', 'business')->assertJsonPath('data.platform_url', 'https://acme.livezen.app');

        // Same key → replay; same external id with a new key → the existing deployment, not a second one.
        $this->partner('POST', '/partner/v1/deployments', $this->deployBody(), ['Idempotency-Key' => 'deploy-dep_1'])->assertHeader('Idempotent-Replayed', 'true');
        $this->partner('POST', '/partner/v1/deployments', $this->deployBody(), ['Idempotency-Key' => 'other'])->assertOk();
        $this->assertSame(1, PartnerDeployment::count());

        $dep = PartnerDeployment::first();
        $this->assertNotSame($platform->id, $dep->organization_id);
        $app = Application::allTenants()->find($dep->application_id);
        $this->assertSame('standard', $app->size); // 8 GB → standard
        $this->assertStringContainsString('worksuite/business', $app->image);

        // The partner org's service owner is a plain customer owner, never platform staff.
        $role = Membership::allTenants()->where('organization_id', $dep->organization_id)->value('role');
        $this->assertSame('owner', $role);

        $cmd = collect($this->heartbeat($agentSecret)['commands'])->firstWhere('type', 'container.create');
        $this->assertNotNull($cmd);
        $env = (array) ($cmd['payload']['env'] ?? []);
        $this->assertSame('ws-inst-1', $env['OIDC_CLIENT_ID'] ?? null, 'SSO settings reach the instance');
        $this->assertSame('owner@acme.test', $env['WORKSUITE_ADMIN_EMAIL'] ?? null);
        $this->commandResult($agentSecret, $cmd['id'], 'succeeded', ['output' => ['container_id' => 'c1']])->assertOk();
        $this->assertCount(1, $this->passHealthChecks($agentSecret), 'the health check reaches a shared-pool server in another organisation');

        $this->partner('GET', '/partner/v1/deployments/dep_1')->assertOk()->assertJsonPath('data.status', 'running');

        // A status webhook was queued for Cloud.
        $this->assertTrue(PartnerWebhook::where('partner_deployment_id', $dep->id)->exists());
    }

    public function test_actions_lifecycle_and_webhook_signature(): void
    {
        [, $platform, $token] = $this->org();
        $server = $this->server($platform, ['shared_pool' => true, 'ram_mb' => 65536]);
        [$agentSecret] = $this->enrollAgent($server, $token);
        $this->partner('POST', '/partner/v1/deployments', $this->deployBody(['product' => 'cms', 'subdomain' => 'acme-site', 'external_id' => 'dep_cms']), ['Idempotency-Key' => 'a'])->assertStatus(202);
        $cmd = collect($this->heartbeat($agentSecret)['commands'])->firstWhere('type', 'container.create');
        $this->commandResult($agentSecret, $cmd['id'], 'succeeded', ['output' => ['container_id' => 'c2']]);
        $this->passHealthChecks($agentSecret);

        $this->partner('POST', '/partner/v1/deployments/dep_cms/actions', ['action' => 'restart'], ['Idempotency-Key' => 'r1'])->assertStatus(202)->assertJsonPath('data.action', 'restart');
        $this->partner('POST', '/partner/v1/deployments/dep_cms/actions', ['action' => 'upgrade'], ['Idempotency-Key' => 'u0'])->assertStatus(422);
        $this->partner('POST', '/partner/v1/deployments/dep_cms/actions', ['action' => 'upgrade', 'version' => '2.4.0'], ['Idempotency-Key' => 'u1'])->assertStatus(202);
        $this->assertStringEndsWith(':2.4.0', Application::allTenants()->find(PartnerDeployment::first()->application_id)->image);

        $this->partner('POST', '/partner/v1/deployments/dep_cms/lifecycle', ['state' => 'suspend', 'reason' => 'subscription expired'], ['Idempotency-Key' => 's1'])
            ->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->partner('POST', '/partner/v1/deployments/dep_cms/actions', ['action' => 'restart'], ['Idempotency-Key' => 'r2'])->assertStatus(409);
        $this->assertNotNull(Application::allTenants()->find(PartnerDeployment::first()->application_id), 'suspension keeps the application and its data');
        $this->partner('POST', '/partner/v1/deployments/dep_cms/lifecycle', ['state' => 'resume'], ['Idempotency-Key' => 's2'])->assertOk();

        Http::fake(['*' => Http::response('', 204)]);
        $stats = app(PartnerWebhookService::class)->deliverDue();
        $this->assertGreaterThan(0, $stats['delivered']);
        Http::assertSent(function ($req) {
            $header = $req->header('X-Livezen-Signature')[0];
            preg_match('/t=(\d+),v1=([0-9a-f]+)/', $header, $m);

            return hash_equals(hash_hmac('sha256', $m[1].'.'.$req->body(), 'whsec-test'), $m[2]);
        });

        $this->partner('POST', '/partner/v1/deployments/dep_cms/lifecycle', ['state' => 'terminate'], ['Idempotency-Key' => 't1'])->assertOk()->assertJsonPath('data.status', 'terminated');
    }

    public function test_partner_cannot_see_another_partners_deployment(): void
    {
        $other = PartnerClient::create(['name' => 'Other', 'key_id' => 'lzp_other', 'secret' => 'x', 'webhook_secret' => 'y', 'scopes' => ['*']]);
        $acct = app(PartnerProvisioningService::class)->account($other, 'acct_x', 'X');
        PartnerDeployment::create(['partner_client_id' => $other->id, 'partner_account_id' => $acct->id, 'organization_id' => $acct->organization_id, 'external_id' => 'theirs',
            'product' => 'business', 'workspace_name' => 'X', 'subdomain' => 'x', 'size' => 'small', 'storage_gb' => 5, 'admin_email' => 'x@x.test']);
        $this->partner('GET', '/partner/v1/deployments/theirs')->assertStatus(404);
    }

    public function test_size_follows_storage_and_partner_templates_are_hidden_from_customers(): void
    {
        $this->assertSame('small', PartnerProvisioningService::sizeFor(5));
        $this->assertSame('standard', PartnerProvisioningService::sizeFor(6));
        $this->assertSame('pro', PartnerProvisioningService::sizeFor(400));
        [, , $token] = $this->org();
        $ids = collect($this->api($token)->getJson('/api/v1/app/templates')->assertOk()->json('data'))->pluck('id');
        $this->assertNotContains('worksuite-business', $ids);
    }

    public function test_staff_overview_is_for_platform_staff_only(): void
    {
        $acct = app(PartnerProvisioningService::class)->account($this->client, 'acct_o', 'Overview Co');
        PartnerDeployment::create(['partner_client_id' => $this->client->id, 'partner_account_id' => $acct->id, 'organization_id' => $acct->organization_id, 'external_id' => 'ov1',
            'product' => 'cms', 'workspace_name' => 'Site', 'subdomain' => 'site', 'size' => 'small', 'storage_gb' => 5, 'admin_email' => 'a@x.test', 'status' => 'failed', 'status_reason' => 'boom']);

        [, , $owner] = $this->org();
        $this->api($owner)->getJson('/api/v1/partner-overview')->assertForbidden();

        [, , $admin] = $this->org('super_admin');
        $this->api($admin)->getJson('/api/v1/partner-overview')->assertOk()
            ->assertJsonPath('data.clients.0.key_id', 'lzp_test')
            ->assertJsonMissingPath('data.clients.0.secret')
            ->assertJsonPath('data.deployments.failed', 1)
            ->assertJsonPath('data.recent.0.workspace_name', 'Site')
            ->assertJsonPath('data.recent.0.account', 'Overview Co')
            ->assertJsonPath('data.failures.0.reason', 'boom');

        $this->api($admin)->patchJson("/api/v1/partner-clients/{$this->client->id}", ['active' => false])->assertOk()->assertJsonPath('data.active', false);
        $this->partner('GET', '/partner/v1/deployments')->assertStatus(401);
    }
}
