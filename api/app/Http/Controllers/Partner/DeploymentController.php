<?php

namespace App\Http\Controllers\Partner;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\PartnerClient;
use App\Models\PartnerDeployment;
use App\Models\PartnerWebhook;
use App\Services\PartnerProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Partner API, base /partner/v1 (docs/PARTNER_API.md). A partner sees and
 * changes only the deployments it created, addressed by its own external id.
 */
class DeploymentController extends Controller
{
    public function __construct(private PartnerProvisioningService $svc) {}

    private function client(Request $request): PartnerClient
    {
        return $request->attributes->get('partner_client');
    }

    private function find(Request $request, string $externalId): PartnerDeployment
    {
        return PartnerDeployment::where('partner_client_id', $this->client($request)->id)->where('external_id', $externalId)->firstOrFail();
    }

    public function index(Request $request)
    {
        $q = PartnerDeployment::where('partner_client_id', $this->client($request)->id)->with(['account', 'client']);
        if ($acct = $request->query('account_id')) {
            $q->whereHas('account', fn ($a) => $a->where('external_account_id', $acct));
        }
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        $items = $q->orderByDesc('created_at')->limit(min(500, (int) $request->query('limit', 200)))->get();

        return $this->data($items->map(fn ($d) => $this->svc->present($d))->values());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'external_id' => 'required|string|max:64|regex:/^[A-Za-z0-9_-]+$/',
            'product' => ['required', Rule::in(array_keys(config('partner.products')))],
            'account' => 'required|array',
            'account.id' => 'required|string|max:64',
            'account.name' => 'required|string|max:160',
            'workspace_name' => 'required|string|min:2|max:160',
            'subdomain' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/'],
            'custom_domain' => ['nullable', 'string', 'max:253', 'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
            'region' => 'nullable|string|max:60',
            'storage_gb' => 'required|integer|min:1|max:10000',
            'version' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/'],
            'admin' => 'required|array',
            'admin.email' => 'required|email|max:255',
            'admin.name' => 'nullable|string|max:160',
            'sso' => 'nullable|array',
            'sso.issuer' => 'required_with:sso|url|max:255',
            'sso.client_id' => 'required_with:sso|string|max:120',
            'sso.client_secret' => 'required_with:sso|string|max:255',
            'env' => 'nullable|array',
            'entitlement' => 'nullable|array',
        ]);
        foreach (array_keys($data['env'] ?? []) as $k) {
            if (! preg_match('/^WORKSUITE_[A-Z0-9_]{1,80}$/', (string) $k)) {
                throw ApiException::validation(['env' => ["{$k}: only WORKSUITE_* variables may be set by a partner."]]);
            }
        }
        $existed = PartnerDeployment::where('partner_client_id', $this->client($request)->id)->where('external_id', $data['external_id'])->exists();
        $dep = $this->svc->deploy($this->client($request), $data);

        return $this->data($this->svc->present($dep), $existed ? 200 : 202);
    }

    public function show(Request $request, string $externalId)
    {
        $dep = $this->find($request, $externalId);
        $this->svc->refresh($dep);

        return $this->data($this->svc->present($dep->fresh()) + ['backups' => $this->svc->backupsOf($dep)]);
    }

    public function action(Request $request, string $externalId)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(PartnerProvisioningService::ACTIONS)],
            'version' => ['required_if:action,upgrade', 'nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/'],
            'storage_gb' => 'required_if:action,scale|nullable|integer|min:1|max:10000',
            'backup_id' => 'required_if:action,restore|nullable|uuid',
            'backup_first' => 'nullable|boolean',
        ]);
        $out = $this->svc->act($this->find($request, $externalId), $data['action'], array_filter($data, fn ($v, $k) => $k !== 'action' && $v !== null, ARRAY_FILTER_USE_BOTH));

        return response()->json(['data' => $out], 202);
    }

    public function lifecycle(Request $request, string $externalId)
    {
        $data = $request->validate(['state' => 'required|in:suspend,resume,terminate', 'reason' => 'nullable|string|max:200']);

        return $this->data($this->svc->present($this->svc->lifecycle($this->find($request, $externalId), $data['state'], (string) ($data['reason'] ?? ''))));
    }

    public function domain(Request $request, string $externalId)
    {
        $data = $request->validate(['domain' => ['required', 'string', 'max:253', 'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i']]);
        $dep = $this->find($request, $externalId);
        if ($dep->custom_domain) {
            $this->svc->removeDomain($dep);
        }

        return $this->data($this->svc->addDomain($dep->fresh(), $data['domain']), 201);
    }

    public function verifyDomain(Request $request, string $externalId)
    {
        return $this->data($this->svc->verifyDomain($this->find($request, $externalId)));
    }

    public function removeDomain(Request $request, string $externalId)
    {
        $this->svc->removeDomain($this->find($request, $externalId));

        return response()->noContent();
    }

    public function logs(Request $request, string $externalId)
    {
        return $this->data($this->svc->logs($this->find($request, $externalId), (int) $request->query('tail', 200)));
    }

    /** Infrastructure summary for the partner's own monitoring dashboard. */
    public function summary(Request $request)
    {
        $client = $this->client($request);
        $by = PartnerDeployment::where('partner_client_id', $client->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $webhooks = PartnerWebhook::where('partner_client_id', $client->id);

        return $this->data([
            'deployments' => collect(PartnerDeployment::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($by[$s] ?? 0)]),
            'webhooks' => [
                'pending' => (clone $webhooks)->whereNull('delivered_at')->whereNull('failed_at')->count(),
                'failed' => (clone $webhooks)->whereNotNull('failed_at')->count(),
            ],
            'generated_at' => now()->toIso8601ZuluString(),
        ]);
    }
}
