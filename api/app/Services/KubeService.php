<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\KubeCluster;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;

/**
 * K8S: talks to a cluster's API server with a service-account bearer token. Read: nodes,
 * namespaces, deployments. Write: deploy an image, scale, restart, delete a deployment.
 */
class KubeService
{
    public const NAME = '/^[a-z0-9]([-a-z0-9]{0,61}[a-z0-9])?$/';

    /**
     * https only. Private networks are allowed (that is where clusters live); loopback,
     * link-local and cloud metadata addresses are not, so the panel can't be pointed at itself.
     */
    public static function checkUrl(string $url): ?string
    {
        $p = parse_url($url);
        if (($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['query']) || isset($p['fragment'])) {
            return 'Use the API server\'s https:// address, e.g. https://10.0.0.5:6443.';
        }
        $host = trim($p['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            return 'That host name does not resolve.';
        }
        foreach ($ips as $ip) {
            // An IPv4 address written as IPv6 (::ffff:127.0.0.1) is judged as the IPv4 it is.
            if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
                $ip = $m[1];
            }
            $bin = inet_pton($ip);
            $v4 = strlen($bin) === 4;
            $bad = $v4
                ? (str_starts_with($ip, '127.') || str_starts_with($ip, '169.254.') || str_starts_with($ip, '0.') || $ip === '255.255.255.255')
                : ($ip === '::1' || str_starts_with(strtolower($ip), 'fe80:') || $ip === '::');
            if ($bad) {
                return 'That address is loopback or link-local, which is not allowed.';
            }
        }

        return null;
    }

    /** @return array{api_url:string, ca_cert:?string, token:string, default_namespace:?string} */
    public static function parseKubeconfig(string $yaml): array
    {
        try {
            $k = Yaml::parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (\Throwable) {
            throw ApiException::validation(['kubeconfig' => ['That is not valid YAML.']]);
        }
        if (! is_array($k)) {
            throw ApiException::validation(['kubeconfig' => ['That is not a kubeconfig.']]);
        }
        $ctxName = $k['current-context'] ?? ($k['contexts'][0]['name'] ?? null);
        $ctx = collect($k['contexts'] ?? [])->firstWhere('name', $ctxName)['context'] ?? [];
        $cluster = collect($k['clusters'] ?? [])->firstWhere('name', $ctx['cluster'] ?? null)['cluster'] ?? ($k['clusters'][0]['cluster'] ?? []);
        $user = collect($k['users'] ?? [])->firstWhere('name', $ctx['user'] ?? null)['user'] ?? ($k['users'][0]['user'] ?? []);
        $token = (string) ($user['token'] ?? '');
        if ($token === '') {
            throw ApiException::validation(['kubeconfig' => ['This kubeconfig has no token. Use a service-account token (client certificates and exec plugins are not supported).']]);
        }
        $ca = isset($cluster['certificate-authority-data']) ? base64_decode((string) $cluster['certificate-authority-data'], true) : null;

        return ['api_url' => rtrim((string) ($cluster['server'] ?? ''), '/'), 'ca_cert' => $ca ?: null, 'default_namespace' => $ctx['namespace'] ?? null, 'token' => $token];
    }

    private function http(KubeCluster $c): PendingRequest
    {
        $h = Http::baseUrl(rtrim($c->api_url, '/'))->withToken((string) $c->token)->acceptJson()->timeout(15);
        if ($c->ca_cert) {
            // The cluster's own CA, written to a private temp file for the TLS check.
            $file = storage_path('app/kube-ca-'.$c->id.'.pem');
            if (! is_file($file) || file_get_contents($file) !== $c->ca_cert) {
                file_put_contents($file, $c->ca_cert);
                chmod($file, 0600);
            }
            $h = $h->withOptions(['verify' => $file]);
        }

        return $h;
    }

    private function json(Response $r, string $what): array
    {
        if (! $r->successful()) {
            $msg = (string) ($r->json('message') ?? '');
            throw ApiException::conflict(match ($r->status()) {
                401 => 'The cluster rejected the token.',
                403 => "The token is not allowed to {$what}.".($msg ? " ({$msg})" : ''),
                404 => 'Not found in the cluster.',
                default => "The cluster answered HTTP {$r->status()} when trying to {$what}.".($msg ? " {$msg}" : ''),
            });
        }

        return (array) $r->json();
    }

    private function call(callable $fn)
    {
        try {
            return $fn();
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw ApiException::conflict('The cluster could not be reached: '.mb_substr($e->getMessage(), 0, 200));
        }
    }

    public function check(KubeCluster $c): KubeCluster
    {
        try {
            $v = $this->call(fn () => $this->json($this->http($c)->get('/version'), 'read its version'));
            $this->call(fn () => $this->json($this->http($c)->get('/api/v1/namespaces', ['limit' => 1]), 'list namespaces'));
            $c->forceFill(['status' => 'connected', 'version' => (string) ($v['gitVersion'] ?? ''), 'error' => null, 'checked_at' => now()])->save();
        } catch (ApiException $e) {
            $c->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'checked_at' => now()])->save();
        }

        return $c;
    }

    public function overview(KubeCluster $c, ?string $namespace = null): array
    {
        $h = $this->http($c);
        $nodes = $this->call(fn () => $this->json($h->get('/api/v1/nodes'), 'list nodes'));
        $nss = $this->call(fn () => $this->json($h->get('/api/v1/namespaces'), 'list namespaces'));
        $depPath = $namespace ? '/apis/apps/v1/namespaces/'.rawurlencode($namespace).'/deployments' : '/apis/apps/v1/deployments';
        $deps = $this->call(fn () => $this->json($h->get($depPath), 'list deployments'));

        return [
            'nodes' => collect($nodes['items'] ?? [])->map(fn ($n) => [
                'name' => $n['metadata']['name'] ?? '',
                'ready' => collect($n['status']['conditions'] ?? [])->firstWhere('type', 'Ready')['status'] ?? 'Unknown',
                'roles' => collect(array_keys($n['metadata']['labels'] ?? []))->filter(fn ($l) => str_starts_with($l, 'node-role.kubernetes.io/'))->map(fn ($l) => substr($l, 24))->values()->all(),
                'version' => $n['status']['nodeInfo']['kubeletVersion'] ?? null,
                'cpu' => $n['status']['capacity']['cpu'] ?? null, 'memory' => $n['status']['capacity']['memory'] ?? null,
                'unschedulable' => (bool) ($n['spec']['unschedulable'] ?? false),
            ])->values()->all(),
            'namespaces' => collect($nss['items'] ?? [])->map(fn ($n) => ['name' => $n['metadata']['name'] ?? '', 'phase' => $n['status']['phase'] ?? null])->values()->all(),
            'deployments' => collect($deps['items'] ?? [])->map(fn ($d) => [
                'namespace' => $d['metadata']['namespace'] ?? '', 'name' => $d['metadata']['name'] ?? '',
                'images' => collect($d['spec']['template']['spec']['containers'] ?? [])->pluck('image')->all(),
                'replicas' => (int) ($d['spec']['replicas'] ?? 0), 'ready' => (int) ($d['status']['readyReplicas'] ?? 0),
                'available' => (int) ($d['status']['availableReplicas'] ?? 0),
            ])->values()->all(),
        ];
    }

    private function names(string $ns, string $name): void
    {
        if (! preg_match(self::NAME, $ns) || ! preg_match(self::NAME, $name)) {
            throw ApiException::validation(['name' => ['Names are lowercase letters, numbers and hyphens (max 63).']]);
        }
    }

    public function scale(KubeCluster $c, string $ns, string $name, int $replicas): void
    {
        $this->names($ns, $name);
        $this->call(fn () => $this->json($this->http($c)->withBody(json_encode(['spec' => ['replicas' => $replicas]]), 'application/merge-patch+json')
            ->patch('/apis/apps/v1/namespaces/'.$ns.'/deployments/'.$name.'/scale'), 'scale deployments'));
    }

    /** The same restart kubectl does: change a pod-template annotation, which rolls the pods. */
    public function restart(KubeCluster $c, string $ns, string $name): void
    {
        $this->names($ns, $name);
        $patch = ['spec' => ['template' => ['metadata' => ['annotations' => ['kubectl.kubernetes.io/restartedAt' => now()->toIso8601ZuluString()]]]]];
        $this->call(fn () => $this->json($this->http($c)->withBody(json_encode($patch), 'application/strategic-merge-patch+json')
            ->patch('/apis/apps/v1/namespaces/'.$ns.'/deployments/'.$name), 'restart deployments'));
    }

    public function delete(KubeCluster $c, string $ns, string $name): void
    {
        $this->names($ns, $name);
        $this->call(fn () => $this->json($this->http($c)->delete('/apis/apps/v1/namespaces/'.$ns.'/deployments/'.$name), 'delete deployments'));
    }

    /** Deploys an image as a Deployment (and a ClusterIP Service when a port is given). */
    public function deploy(KubeCluster $c, array $v): array
    {
        $ns = $v['namespace'];
        $this->names($ns, $v['name']);
        $labels = ['app.kubernetes.io/name' => $v['name'], 'app.kubernetes.io/managed-by' => 'worksuite-cloudpanel'];
        $container = ['name' => $v['name'], 'image' => $v['image']]
            + (! empty($v['port']) ? ['ports' => [['containerPort' => (int) $v['port']]]] : [])
            + (! empty($v['env']) ? ['env' => collect($v['env'])->map(fn ($val, $k) => ['name' => (string) $k, 'value' => (string) $val])->values()->all()] : []);
        $dep = ['apiVersion' => 'apps/v1', 'kind' => 'Deployment', 'metadata' => ['name' => $v['name'], 'namespace' => $ns, 'labels' => $labels],
            'spec' => ['replicas' => (int) ($v['replicas'] ?? 1), 'selector' => ['matchLabels' => ['app.kubernetes.io/name' => $v['name']]],
                'template' => ['metadata' => ['labels' => $labels], 'spec' => ['containers' => [$container]]]]];
        $h = $this->http($c);
        $this->call(fn () => $this->json($h->post("/apis/apps/v1/namespaces/{$ns}/deployments", $dep), 'create deployments'));
        if (! empty($v['port'])) {
            $svc = ['apiVersion' => 'v1', 'kind' => 'Service', 'metadata' => ['name' => $v['name'], 'namespace' => $ns, 'labels' => $labels],
                'spec' => ['selector' => ['app.kubernetes.io/name' => $v['name']], 'ports' => [['port' => (int) $v['port'], 'targetPort' => (int) $v['port']]]]];
            $this->call(fn () => $this->json($h->post("/api/v1/namespaces/{$ns}/services", $svc), 'create services'));
        }

        return ['namespace' => $ns, 'name' => $v['name']];
    }
}
