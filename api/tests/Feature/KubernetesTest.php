<?php

namespace Tests\Feature;

use App\Models\KubeCluster;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** K8S: connect an existing cluster with a token, read it, and run deployments there. */
class KubernetesTest extends TestCase
{
    private const API = 'https://10.0.0.5:6443';

    private function fakeCluster(): void
    {
        Http::fake([
            self::API.'/version' => Http::response(['gitVersion' => 'v1.31.2']),
            self::API.'/api/v1/namespaces*' => Http::response(['items' => [['metadata' => ['name' => 'default'], 'status' => ['phase' => 'Active']], ['metadata' => ['name' => 'shop'], 'status' => ['phase' => 'Active']]]]),
            self::API.'/api/v1/nodes' => Http::response(['items' => [[
                'metadata' => ['name' => 'k1', 'labels' => ['node-role.kubernetes.io/control-plane' => '']],
                'status' => ['conditions' => [['type' => 'Ready', 'status' => 'True']], 'nodeInfo' => ['kubeletVersion' => 'v1.31.2'], 'capacity' => ['cpu' => '4', 'memory' => '8Gi']],
            ]]]),
            self::API.'/apis/apps/v1/deployments' => Http::response(['items' => [['metadata' => ['name' => 'web', 'namespace' => 'shop'],
                'spec' => ['replicas' => 3, 'template' => ['spec' => ['containers' => [['image' => 'nginx:1.27']]]]], 'status' => ['readyReplicas' => 2, 'availableReplicas' => 2]]]]),
            self::API.'/apis/apps/v1/namespaces/shop/deployments/web/scale' => Http::response(['spec' => ['replicas' => 5]]),
            self::API.'/apis/apps/v1/namespaces/shop/deployments/web' => Http::response(['metadata' => ['name' => 'web']]),
            self::API.'/apis/apps/v1/namespaces/shop/deployments' => Http::response(['metadata' => ['name' => 'api']], 201),
            self::API.'/api/v1/namespaces/shop/services' => Http::response(['metadata' => ['name' => 'api']], 201),
            self::API.'/apis/networking.k8s.io/*' => Http::response(['items' => []]),
            self::API.'/apis/cert-manager.io/*' => Http::response([], 404),
        ]);
    }

    public function test_a_cluster_connects_with_a_kubeconfig_and_is_operated(): void
    {
        [, , $token] = $this->org();
        $this->fakeCluster();
        $sa = 'fake-'.str_repeat('k', 16);
        $kubeconfig = "apiVersion: v1\nkind: Config\ncurrent-context: prod\nclusters:\n- name: prod\n  cluster:\n    server: ".self::API."\n"
            ."contexts:\n- name: prod\n  context:\n    cluster: prod\n    user: deployer\n    namespace: shop\nusers:\n- name: deployer\n  user:\n    token: {$sa}\n";
        $c = $this->api($token)->postJson('/api/v1/kubernetes', ['name' => 'prod', 'kubeconfig' => $kubeconfig])->assertCreated()
            ->assertJsonPath('data.status', 'connected')->assertJsonPath('data.version', 'v1.31.2')->assertJsonPath('data.default_namespace', 'shop')->json('data');
        $this->assertSame($sa, KubeCluster::allTenants()->sole()->token);
        Http::assertSent(fn (Request $r) => $r->url() === self::API.'/version' && $r->hasHeader('Authorization', 'Bearer '.$sa));
        $this->assertStringNotContainsString($sa, json_encode($this->api($token)->getJson('/api/v1/kubernetes')->json()));

        $o = $this->api($token)->getJson("/api/v1/kubernetes/{$c['id']}")->assertOk()->json('data');
        $this->assertSame(['control-plane'], $o['nodes'][0]['roles']);
        $this->assertSame('True', $o['nodes'][0]['ready']);
        $this->assertSame(['name' => 'web', 'replicas' => 3, 'ready' => 2], array_intersect_key($o['deployments'][0], ['name' => 1, 'replicas' => 1, 'ready' => 1]));

        $this->api($token)->postJson("/api/v1/kubernetes/{$c['id']}/namespaces/shop/deployments/web/scale", ['replicas' => 5])->assertOk();
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/deployments/web/scale')
            && $r->hasHeader('Content-Type', 'application/merge-patch+json') && $r['spec']['replicas'] === 5);
        $this->api($token)->postJson("/api/v1/kubernetes/{$c['id']}/namespaces/shop/deployments/web/restart")->assertOk();
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/deployments/web') && isset($r['spec']['template']['metadata']['annotations']['kubectl.kubernetes.io/restartedAt']));

        $this->api($token)->postJson("/api/v1/kubernetes/{$c['id']}/deployments", ['namespace' => 'shop', 'name' => 'api', 'image' => 'ghcr.io/acme/api:2', 'replicas' => 2, 'port' => 8080, 'env' => ['MODE' => 'prod']])->assertCreated();
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/namespaces/shop/deployments') && $r['spec']['replicas'] === 2
            && $r['spec']['template']['spec']['containers'][0]['env'] === [['name' => 'MODE', 'value' => 'prod']]);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/namespaces/shop/services'));

        $this->api($token)->deleteJson("/api/v1/kubernetes/{$c['id']}/namespaces/shop/deployments/web?confirm=nope")->assertStatus(422);
        $this->api($token)->deleteJson("/api/v1/kubernetes/{$c['id']}/namespaces/shop/deployments/web?confirm=web")->assertNoContent();
        $this->api($token)->deleteJson("/api/v1/kubernetes/{$c['id']}")->assertNoContent();
    }

    public function test_bad_input_failures_and_isolation(): void
    {
        [, , $token] = $this->org();
        $sa = 'fake-'.str_repeat('t', 16);
        foreach (['http://10.0.0.5:6443', 'https://127.0.0.1:6443', 'https://169.254.169.254', 'https://[::ffff:127.0.0.1]:6443'] as $url) {
            $this->api($token)->postJson('/api/v1/kubernetes', ['name' => 'x1', 'api_url' => $url, 'token' => $sa])->assertStatus(422);
        }
        $this->api($token)->postJson('/api/v1/kubernetes', ['name' => 'x1', 'kubeconfig' => "users:\n- name: u\n  user:\n    client-certificate-data: abc\n"])->assertStatus(422);
        $this->api($token)->postJson('/api/v1/kubernetes', ['name' => 'x1', 'api_url' => self::API, 'token' => $sa, 'ca_cert' => 'not a cert'])->assertStatus(422);

        Http::fake([self::API.'/*' => Http::response(['message' => 'Unauthorized'], 401)]);
        $c = $this->api($token)->postJson('/api/v1/kubernetes', ['name' => 'bad', 'api_url' => self::API, 'token' => $sa])->assertCreated()
            ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error', 'The cluster rejected the token.')->json('data');
        $this->api($token)->getJson("/api/v1/kubernetes/{$c['id']}")->assertStatus(409);
        $this->api($token)->postJson("/api/v1/kubernetes/{$c['id']}/deployments", ['namespace' => 'Bad_NS', 'name' => 'api', 'image' => 'nginx'])->assertStatus(422);

        [, , $other] = $this->org();
        $this->api($other)->getJson("/api/v1/kubernetes/{$c['id']}")->assertNotFound();
        $this->api($other)->deleteJson("/api/v1/kubernetes/{$c['id']}")->assertNotFound();
        $this->assertSame([], $this->api($other)->getJson('/api/v1/kubernetes')->json('data'));
    }

    private function connect(string $token): string
    {
        Http::fake([self::API.'/version' => Http::response(['gitVersion' => 'v1.31.2']), self::API.'/api/v1/namespaces*' => Http::response(['items' => []])]);

        $id = $this->api($token)->postJson('/api/v1/kubernetes', ['name' => 'prod', 'api_url' => self::API, 'token' => 'fake-'.str_repeat('p', 16)])->assertCreated()->json('data.id');
        Http::swap(new \Illuminate\Http\Client\Factory); // each test's own fakes, not the connect ones

        return $id;
    }

    public function test_pods_and_their_logs(): void
    {
        [, , $token] = $this->org();
        $id = $this->connect($token);
        Http::fake([
            self::API.'/apis/apps/v1/namespaces/shop/deployments/web' => Http::response(['spec' => ['selector' => ['matchLabels' => ['app.kubernetes.io/name' => 'web']]]]),
            self::API.'/api/v1/namespaces/shop/pods?*' => Http::response(['items' => [[
                'metadata' => ['name' => 'web-7d9f-abc12'], 'spec' => ['nodeName' => 'k1', 'containers' => [['name' => 'web']]],
                'status' => ['phase' => 'Running', 'startTime' => '2026-11-01T00:00:00Z', 'containerStatuses' => [['ready' => false, 'restartCount' => 4, 'state' => ['waiting' => ['reason' => 'CrashLoopBackOff']]]]],
            ]]]),
            self::API.'/api/v1/namespaces/shop/pods/web-7d9f-abc12/log*' => Http::response("2026-11-04T00:00:00Z boot\n2026-11-04T00:00:01Z crash\n", 200, ['Content-Type' => 'text/plain']),
        ]);
        $pods = $this->api($token)->getJson("/api/v1/kubernetes/{$id}/namespaces/shop/deployments/web/pods")->assertOk()->json('data');
        $this->assertSame(['web-7d9f-abc12', false, 4, 'CrashLoopBackOff', ['web']], [$pods[0]['name'], $pods[0]['ready'], $pods[0]['restarts'], $pods[0]['reason'], $pods[0]['containers']]);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/pods?') && str_contains(urldecode($r->url()), 'labelSelector=app.kubernetes.io/name=web'));

        $lines = $this->api($token)->getJson("/api/v1/kubernetes/{$id}/namespaces/shop/pods/web-7d9f-abc12/logs?container=web&tail=50&previous=1")->assertOk()->json('data.lines');
        $this->assertSame(['2026-11-04T00:00:00Z boot', '2026-11-04T00:00:01Z crash'], $lines);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/log?') && $r['tailLines'] == 50 && $r['container'] === 'web' && $r['previous'] === 'true' && $r['limitBytes'] == 524288);
        $this->api($token)->getJson("/api/v1/kubernetes/{$id}/namespaces/shop/pods/web-7d9f-abc12/logs?tail=999999")->assertStatus(422);
        $this->api($token)->getJson("/api/v1/kubernetes/{$id}/namespaces/shop/pods/web-7d9f-abc12/logs?container=Bad_Name")->assertStatus(422);
        $this->api($token)->getJson("/api/v1/kubernetes/{$id}/namespaces/shop/pods/BAD/logs")->assertNotFound();
    }

    public function test_ingresses_with_cert_manager_https(): void
    {
        [, , $token] = $this->org();
        $id = $this->connect($token);
        Http::fake([
            self::API.'/api/v1/nodes' => Http::response(['items' => []]),
            self::API.'/api/v1/namespaces' => Http::response(['items' => []]),
            self::API.'/apis/apps/v1/deployments' => Http::response(['items' => []]),
            self::API.'/apis/networking.k8s.io/v1/ingresses' => Http::response(['items' => [['metadata' => ['name' => 'web', 'namespace' => 'shop', 'annotations' => ['cert-manager.io/cluster-issuer' => 'letsencrypt']],
                'spec' => ['ingressClassName' => 'nginx', 'tls' => [['hosts' => ['shop.example.com']]], 'rules' => [['host' => 'shop.example.com', 'http' => ['paths' => [['backend' => ['service' => ['name' => 'web']]]]]]]],
                'status' => ['loadBalancer' => ['ingress' => [['ip' => '203.0.113.9']]]]]]]),
            self::API.'/apis/cert-manager.io/v1/clusterissuers' => Http::response(['items' => [['metadata' => ['name' => 'letsencrypt'], 'status' => ['conditions' => [['type' => 'Ready', 'status' => 'True']]]]]]),
            self::API.'/apis/networking.k8s.io/v1/namespaces/shop/ingresses' => Http::response(['metadata' => ['name' => 'api']], 201),
            self::API.'/apis/networking.k8s.io/v1/namespaces/shop/ingresses/api' => Http::response(['status' => 'Success']),
        ]);
        $o = $this->api($token)->getJson("/api/v1/kubernetes/{$id}")->assertOk()->json('data');
        $this->assertSame(['shop.example.com'], $o['ingresses'][0]['hosts']);
        $this->assertSame([true, 'letsencrypt', ['203.0.113.9'], 'web'], [$o['ingresses'][0]['tls'], $o['ingresses'][0]['issuer'], $o['ingresses'][0]['address'], $o['ingresses'][0]['service']]);
        $this->assertSame([['name' => 'letsencrypt', 'ready' => true]], $o['cluster_issuers']);

        $this->api($token)->postJson("/api/v1/kubernetes/{$id}/ingresses", ['namespace' => 'shop', 'service' => 'api', 'port' => 8080, 'host' => 'not a host'])->assertStatus(422);
        $this->api($token)->postJson("/api/v1/kubernetes/{$id}/ingresses", ['namespace' => 'shop', 'service' => 'api', 'port' => 8080, 'host' => 'API.example.com', 'class' => 'nginx', 'issuer' => 'letsencrypt'])
            ->assertCreated()->assertJsonPath('data.tls', true);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/namespaces/shop/ingresses')
            && $r['spec']['rules'][0]['host'] === 'api.example.com' && $r['spec']['rules'][0]['http']['paths'][0]['backend']['service'] === ['name' => 'api', 'port' => ['number' => 8080]]
            && $r['spec']['tls'][0]['secretName'] === 'api-tls' && $r['metadata']['annotations']['cert-manager.io/cluster-issuer'] === 'letsencrypt' && $r['spec']['ingressClassName'] === 'nginx');
        $this->api($token)->deleteJson("/api/v1/kubernetes/{$id}/namespaces/shop/ingresses/api")->assertNoContent();

        [, , $viewer] = $this->org('viewer');
        $this->api($viewer)->postJson("/api/v1/kubernetes/{$id}/ingresses", ['namespace' => 'shop', 'service' => 'api', 'port' => 80, 'host' => 'a.example.com'])->assertStatus(403);
    }

    public function test_the_page_still_loads_when_the_token_cannot_read_ingresses(): void
    {
        [, , $token] = $this->org();
        $id = $this->connect($token);
        Http::fake([
            self::API.'/apis/networking.k8s.io/*' => Http::response(['message' => 'forbidden'], 403),
            self::API.'/apis/cert-manager.io/*' => Http::response([], 404),
            self::API.'/*' => Http::response(['items' => []]),
        ]);
        $o = $this->api($token)->getJson("/api/v1/kubernetes/{$id}")->assertOk()->json('data');
        $this->assertSame([null, null], [$o['ingresses'], $o['cluster_issuers']]);
    }
}
