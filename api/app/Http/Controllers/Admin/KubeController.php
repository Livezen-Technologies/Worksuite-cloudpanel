<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\KubeCluster;
use App\Services\AuditLogger;
use App\Services\KubeService;
use Illuminate\Http\Request;

/** K8S: connected Kubernetes clusters. */
class KubeController extends Controller
{
    public function __construct(private KubeService $kube, private AuditLogger $audit) {}

    private function present(KubeCluster $c): array
    {
        return ['id' => $c->id, 'name' => $c->name, 'api_url' => $c->api_url, 'has_ca' => (bool) $c->ca_cert, 'default_namespace' => $c->default_namespace,
            'status' => $c->status, 'version' => $c->version, 'error' => $c->error, 'checked_at' => $c->checked_at?->toIso8601ZuluString()];
    }

    public function index()
    {
        return $this->data(KubeCluster::orderBy('name')->get()->map(fn ($c) => $this->present($c))->values());
    }

    public function store(Request $request)
    {
        $v = $request->validate([
            'name' => 'required|string|min:2|max:60',
            'kubeconfig' => 'nullable|string|max:65536',
            'api_url' => 'required_without:kubeconfig|nullable|string|max:255',
            'token' => 'required_without:kubeconfig|nullable|string|max:8192',
            'ca_cert' => 'nullable|string|max:16384',
            'default_namespace' => ['nullable', 'string', 'regex:'.KubeService::NAME],
        ]);
        $conn = ! empty($v['kubeconfig']) ? KubeService::parseKubeconfig($v['kubeconfig'])
            : ['api_url' => rtrim((string) $v['api_url'], '/'), 'token' => (string) $v['token'], 'ca_cert' => $v['ca_cert'] ?? null, 'default_namespace' => null];
        if ($why = KubeService::checkUrl($conn['api_url'])) {
            throw ApiException::validation(['api_url' => [$why]]);
        }
        if ($conn['ca_cert'] && ! str_contains($conn['ca_cert'], '-----BEGIN CERTIFICATE-----')) {
            throw ApiException::validation(['ca_cert' => ['Paste the cluster CA certificate in PEM form.']]);
        }
        $ns = $v['default_namespace'] ?? $conn['default_namespace'] ?? 'default';
        $c = $this->kube->check(KubeCluster::create(['name' => $v['name'], 'api_url' => $conn['api_url'], 'token' => $conn['token'], 'ca_cert' => $conn['ca_cert'],
            'default_namespace' => preg_match(KubeService::NAME, (string) $ns) ? $ns : 'default']));
        $this->audit->log('kube.connect', 'kube_cluster', $c->id, ['name' => $c->name, 'api_url' => $c->api_url, 'status' => $c->status]);

        return $this->data($this->present($c), 201);
    }

    public function check(string $id)
    {
        return $this->data($this->present($this->kube->check(KubeCluster::findOrFail($id))));
    }

    public function show(Request $request, string $id)
    {
        $c = KubeCluster::findOrFail($id);
        $ns = $request->query('namespace');
        if ($ns !== null && ! preg_match(KubeService::NAME, (string) $ns)) {
            throw ApiException::validation(['namespace' => ['Invalid namespace.']]);
        }

        return $this->data($this->present($c) + $this->kube->overview($c, $ns ?: null) + $this->kube->routing($c, $ns ?: null));
    }

    public function deploy(Request $request, string $id)
    {
        $c = KubeCluster::findOrFail($id);
        $v = $request->validate([
            'namespace' => ['required', 'string', 'regex:'.KubeService::NAME], 'name' => ['required', 'string', 'regex:'.KubeService::NAME],
            'image' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9._\/:@-]*$/i'],
            'replicas' => 'nullable|integer|between:0,50', 'port' => 'nullable|integer|between:1,65535',
            'env' => 'nullable|array', 'env.*' => 'nullable|string|max:4096',
        ]);
        foreach (array_keys($v['env'] ?? []) as $k) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $k)) {
                throw ApiException::validation(['env' => ["Invalid variable name {$k}."]]);
            }
        }
        $r = $this->kube->deploy($c, $v);
        $this->audit->log('kube.deploy', 'kube_cluster', $c->id, ['namespace' => $v['namespace'], 'name' => $v['name'], 'image' => $v['image']]);

        return $this->data($r, 201);
    }

    public function action(Request $request, string $id, string $ns, string $name, string $action)
    {
        $c = KubeCluster::findOrFail($id);
        match ($action) {
            'scale' => $this->kube->scale($c, $ns, $name, (int) $request->validate(['replicas' => 'required|integer|between:0,50'])['replicas']),
            'restart' => $this->kube->restart($c, $ns, $name),
        };
        $this->audit->log("kube.{$action}", 'kube_cluster', $c->id, ['namespace' => $ns, 'name' => $name] + ($action === 'scale' ? ['replicas' => (int) $request->input('replicas')] : []));

        return $this->data(['ok' => true]);
    }

    public function destroyDeployment(Request $request, string $id, string $ns, string $name)
    {
        $c = KubeCluster::findOrFail($id);
        if (trim((string) $request->input('confirm')) !== $name) {
            throw ApiException::validation(['confirm' => ['Type the deployment name to delete it.']]);
        }
        $this->kube->delete($c, $ns, $name);
        $this->audit->log('kube.delete', 'kube_cluster', $c->id, ['namespace' => $ns, 'name' => $name]);

        return response()->noContent();
    }

    public function destroy(string $id)
    {
        $c = KubeCluster::findOrFail($id);
        @unlink(storage_path('app/kube-ca-'.$c->id.'.pem'));
        $c->delete();
        $this->audit->log('kube.disconnect', 'kube_cluster', $id);

        return response()->noContent();
    }

    public function pods(string $id, string $ns, string $name)
    {
        return $this->data($this->kube->pods(KubeCluster::findOrFail($id), $ns, $name));
    }

    public function logs(Request $request, string $id, string $ns, string $pod)
    {
        $c = KubeCluster::findOrFail($id);
        $v = $request->validate(['container' => 'nullable|string|max:63', 'tail' => 'nullable|integer|between:10,5000', 'previous' => 'nullable|boolean']);
        $text = $this->kube->logs($c, $ns, $pod, $v['container'] ?? null, (int) ($v['tail'] ?? 300), (bool) ($v['previous'] ?? false));
        $this->audit->log('kube.logs', 'kube_cluster', $c->id, ['namespace' => $ns, 'pod' => $pod]);

        return $this->data(['lines' => $text === '' ? [] : explode("\n", rtrim($text, "\n"))]);
    }

    public function storeIngress(Request $request, string $id)
    {
        $c = KubeCluster::findOrFail($id);
        $v = $request->validate([
            'namespace' => ['required', 'string', 'regex:'.KubeService::NAME], 'service' => ['required', 'string', 'regex:'.KubeService::NAME],
            'name' => ['nullable', 'string', 'regex:'.KubeService::NAME], 'port' => 'required|integer|between:1,65535',
            'host' => ['required', 'string', 'max:253', 'regex:'.KubeService::HOST],
            'class' => ['nullable', 'string', 'regex:'.KubeService::NAME], 'issuer' => ['nullable', 'string', 'regex:'.KubeService::NAME],
        ]);
        $v['host'] = strtolower($v['host']);
        $r = $this->kube->createIngress($c, $v);
        $this->audit->log('kube.ingress.create', 'kube_cluster', $c->id, ['namespace' => $v['namespace'], 'host' => $v['host'], 'service' => $v['service'], 'tls' => $r['tls']]);

        return $this->data($r, 201);
    }

    public function destroyIngress(string $id, string $ns, string $name)
    {
        $c = KubeCluster::findOrFail($id);
        $this->kube->deleteIngress($c, $ns, $name);
        $this->audit->log('kube.ingress.delete', 'kube_cluster', $c->id, ['namespace' => $ns, 'name' => $name]);

        return response()->noContent();
    }
}
