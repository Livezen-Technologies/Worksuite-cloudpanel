<?php

namespace App\Http\Controllers\Enterprise;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseDeployment;
use App\Services\EnterpriseProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cloud Admin service API, base /api/v1/enterprise (docs/ENTERPRISE_API.md).
 * Needs a panel API token with the `enterprise.deploy` ability. Deployments are
 * addressed by the panel id or by Cloud Admin's own external_id.
 */
class DeploymentController extends Controller
{
    private const SLUG = 'regex:/^[a-z0-9]([a-z0-9-]{1,38}[a-z0-9])$/';

    private const DOMAIN = 'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    private const VERSION = 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{0,59}$/';

    public function __construct(private EnterpriseProvisioningService $svc) {}

    private function find(string $id): EnterpriseDeployment
    {
        $q = EnterpriseDeployment::where('organization_id', app(\App\Support\Tenant::class)->id());

        return (clone $q)->whereKey(\Illuminate\Support\Str::isUuid($id) ? $id : null)->first()
            ?? $q->where('external_id', $id)->first()
            ?? throw new \App\Exceptions\ApiException('deployment_not_found', 'No such enterprise deployment.', 404);
    }

    public function index(Request $request)
    {
        $q = EnterpriseDeployment::where('organization_id', app(\App\Support\Tenant::class)->id());
        if ($s = $request->query('status')) {
            $q->where('status', $s);
        }

        return $this->data($q->orderByDesc('created_at')->limit(min(500, (int) $request->query('limit', 200)))->get()->map(fn ($d) => $this->svc->present($d))->values());
    }

    /** Sizes and settings the Cloud Admin wizard shows. */
    public function options()
    {
        return $this->data([
            'image' => config('enterprise.image'),
            'domain' => config('enterprise.domain'),
            'sizes' => collect(config('enterprise.sizes'))->map(fn ($s, $k) => ['code' => $k, 'label' => $s['label'],
                'cpus' => $s['app_cpus'] + $s['worker_cpus'], 'ram_mb' => $s['app_ram_mb'] + $s['worker_ram_mb'] + $s['db_ram_mb'] + $s['redis_ram_mb'], 'disk_gb' => $s['disk_gb']])->values(),
        ]);
    }

    public function slug(Request $request)
    {
        $slug = strtolower((string) $request->query('slug', ''));
        $valid = (bool) preg_match('/^[a-z0-9]([a-z0-9-]{1,38}[a-z0-9])$/', $slug);

        return $this->data(['slug' => $slug, 'valid' => $valid, 'available' => $valid && $this->svc->slugAvailable($slug), 'hostname' => $valid ? EnterpriseProvisioningService::hostFor($slug) : null]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'external_id' => 'required|string|max:64|regex:/^[A-Za-z0-9_-]+$/',
            'customer_name' => 'required|string|min:2|max:160',
            'slug' => ['required', 'string', self::SLUG],
            'version' => ['required', 'string', self::VERSION],
            'size' => ['required', Rule::in(array_keys(config('enterprise.sizes')))],
            'callback_url' => 'nullable|url:https,http|max:500',
            'callback_secret' => 'nullable|string|min:32|max:200',
            'node_id' => 'nullable|uuid',
            'region' => 'nullable|string|max:60',
        ]);
        [$dep, $secret, $created] = $this->svc->create($request->user(), $data);

        return $this->data($this->svc->present($dep) + ['deployment_id' => $dep->id] + ($created && empty($data['callback_secret']) ? ['callback_secret' => $secret] : []), $created ? 202 : 200);
    }

    public function show(string $id)
    {
        $dep = $this->find($id);
        rescue(fn () => $this->svc->advance($dep), report: true);

        return $this->data($this->svc->present($dep->fresh()));
    }

    public function logs(Request $request, string $id)
    {
        $data = $request->validate(['container' => 'nullable|in:app,worker,database,redis', 'tail' => 'nullable|integer|min:1|max:2000']);

        return $this->data($this->svc->logs($this->find($id), $data['container'] ?? 'app', (int) ($data['tail'] ?? 200)));
    }

    public function configure(Request $request, string $id)
    {
        $data = $request->validate([
            'bootstrap' => 'required|array',
            'custom_domain' => ['nullable', 'string', 'max:253', self::DOMAIN],
        ]);

        return response()->json(['data' => $this->svc->present($this->svc->configure($this->find($id), $data['bootstrap'], isset($data['custom_domain']) ? strtolower($data['custom_domain']) : null))], 202);
    }

    public function action(Request $request, string $id, string $action)
    {
        abort_unless(in_array($action, EnterpriseProvisioningService::ACTIONS, true), 404);
        $data = $request->validate(['version' => ['nullable', 'string', self::VERSION], 'container' => 'nullable|in:app,worker,database,redis']);
        if (! empty($data['container']) && $action !== 'restart') {
            throw \App\Exceptions\ApiException::validation(['container' => ['Only restart takes a single container.']]);
        }

        return response()->json(['data' => $this->svc->act($this->find($id), $action, array_filter($data))], 202);
    }

    /** worksuite-server tags on the enterprise nodes (inventory + a direct scan when it is stale). */
    public function versions()
    {
        return $this->data($this->svc->versions(app(\App\Support\Tenant::class)->id()));
    }

    /** Eligible enterprise nodes with free CPU / RAM / disk. */
    public function nodes()
    {
        return $this->data(['nodes' => $this->svc->nodes(app(\App\Support\Tenant::class)->id())]);
    }

    public function resize(Request $request, string $id)
    {
        $b = (array) config('enterprise.custom_limits');
        $data = $request->validate([
            'size' => ['nullable', Rule::in(array_keys(config('enterprise.sizes')))],
            'cpus' => ['nullable', 'numeric', 'min:'.$b['cpus'][0], 'max:'.$b['cpus'][1]],
            'ram_mb' => ['nullable', 'integer', 'min:'.$b['ram_mb'][0], 'max:'.$b['ram_mb'][1]],
            'disk_gb' => ['nullable', 'integer', 'min:'.$b['disk_gb'][0], 'max:'.$b['disk_gb'][1]],
        ]);
        if (empty($data['size']) && ! isset($data['cpus']) && ! isset($data['ram_mb']) && ! isset($data['disk_gb'])) {
            throw \App\Exceptions\ApiException::validation(['size' => ['Give a size, or cpus / ram_mb / disk_gb.']]);
        }

        return response()->json(['data' => $this->svc->present($this->svc->resize($this->find($id), array_filter($data, fn ($v) => $v !== null)))], 202);
    }

    public function metrics(Request $request, string $id)
    {
        $data = $request->validate(['range' => 'nullable|in:1h,6h,24h,7d']);

        return $this->data($this->svc->metrics($this->find($id), $data['range'] ?? '1h'));
    }

    public function addDomain(Request $request, string $id)
    {
        $request->merge(['domain' => strtolower(trim((string) $request->input('domain'), '. '))]);
        $data = $request->validate(['domain' => ['required', 'string', 'max:253', self::DOMAIN]]);

        return response()->json(['data' => $this->svc->addDomain($this->find($id), strtolower($data['domain']))], 201);
    }

    public function verifyDomain(string $id, string $domain)
    {
        return $this->data($this->svc->verifyDomain($this->find($id), strtolower($domain)));
    }

    public function removeDomain(string $id, string $domain)
    {
        return $this->data($this->svc->present($this->svc->removeDomain($this->find($id), strtolower($domain))));
    }

    public function primaryDomain(string $id, string $domain)
    {
        return response()->json(['data' => $this->svc->present($this->svc->primaryDomain($this->find($id), strtolower($domain)))], 202);
    }

    public function licenceRefresh(string $id)
    {
        return response()->json(['data' => $this->svc->refreshLicence($this->find($id))], 202);
    }

    public function retry(string $id, string $step)
    {
        return response()->json(['data' => $this->svc->present($this->svc->retryStep($this->find($id), $step))], 202);
    }

    public function destroy(string $id)
    {
        return $this->data($this->svc->present($this->svc->delete($this->find($id))));
    }

    public function purge(Request $request, string $id)
    {
        $dep = $this->find($id);
        $request->validate(['confirm' => ['required', 'string', Rule::in([$dep->slug])]], ['confirm.in' => 'Type the deployment slug to confirm the purge.']);

        return $this->data($this->svc->present($this->svc->purge($dep)));
    }
}
