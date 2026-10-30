<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\DomainService;
use App\Support\Lists;
use Illuminate\Http\Request;

class DomainController extends Controller
{
    public function __construct(private DomainService $domains) {}

    /** Staff administer every tenant, so a lookup by id must not be organisation-scoped. */
    private function findDomain(Request $request, string $id): Domain
    {
        return $this->seesAllTenants($request)
            ? Domain::allTenants()->findOrFail($id)
            : Domain::findOrFail($id);
    }

    public function index(Request $request)
    {
        $query = $this->seesAllTenants($request) ? Domain::allTenants()->with('certificate') : Domain::with('certificate');

        return response()->json(Lists::paginate($query, $request, ['name'], ['name', 'created_at'], [
            'application_id', 'dns_status', 'https_status',
            'filter' => fn ($q, $v) => match ($v) {
                'expiring' => $q->whereHas('certificate', fn ($c) => $c->where('status', 'active')->where('expires_at', '<', now()->addDays(14))),
                'pending_dns' => $q->where('dns_status', '!=', 'verified'),
                'wildcard' => $q->where('wildcard', true),
                default => $q,
            },
        ], fn ($d) => $this->domains->toApi($d)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:253', 'regex:/^(\*\.)?([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i'],
            'application_id' => 'nullable|uuid|exists:applications,id', 'target_port' => 'nullable|integer|between:1,65535',
            'www_redirect' => 'sometimes|boolean', 'challenge' => 'sometimes|in:http-01,dns-01', 'options' => 'nullable|array',
            'dns_provider' => 'nullable|in:'.implode(',', array_keys(\App\Services\TraefikConfigService::DNS_PROVIDERS)), 'dns_token' => 'nullable|string|max:4096',
        ]);

        return $this->data($this->domains->toApi($this->domains->create($data)), 201);
    }

    public function show(Request $request, string $id)
    {
        return $this->data($this->domains->toApi($this->findDomain($request, $id)));
    }

    public function update(Request $request, string $id)
    {
        $domain = $this->findDomain($request, $id);
        $data = $request->validate([
            'application_id' => 'nullable|uuid|exists:applications,id', 'target_port' => 'nullable|integer|between:1,65535',
            'www_redirect' => 'sometimes|boolean', 'challenge' => 'sometimes|in:http-01,dns-01', 'options' => 'nullable|array',
            'dns_provider' => 'nullable|in:'.implode(',', array_keys(\App\Services\TraefikConfigService::DNS_PROVIDERS)), 'dns_token' => 'nullable|string|max:4096',
        ]);
        // The WAF setting changes only through PUT /domains/{id}/waf, which validates and audits it.
        if (array_key_exists('options', $data)) {
            $data['options'] = array_diff_key($data['options'] ?? [], ['waf' => 1]) + array_intersect_key($domain->options ?? [], ['waf' => 1]);
        }
        $domain->update(collect($data)->except('dns_token')->all());
        if (! empty($data['dns_token'])) {
            $this->domains->setDnsToken($domain, $data['dns_token']);
        }
        app(\App\Services\TraefikConfigService::class)->push();

        return $this->data($this->domains->toApi($domain->fresh()));
    }

    public function destroy(Request $request, string $id)
    {
        $this->findDomain($request, $id)->delete();

        return response()->noContent();
    }

    /** AV-9: WAF mode (off, detect, block) and CRS rules switched off for this domain. */
    public function waf(Request $request, string $id)
    {
        $data = $request->validate([
            'mode' => 'required|in:'.implode(',', \App\Services\TraefikConfigService::WAF_MODES),
            'exclusions' => 'sometimes|array|max:100', 'exclusions.*' => 'integer|min:1|max:9999999',
        ]);
        $domain = $this->findDomain($request, $id);
        $before = $domain->options['waf'] ?? ['mode' => 'off'];
        $waf = ['mode' => $data['mode'], 'exclusions' => array_values(array_unique(array_map('intval', $data['exclusions'] ?? $before['exclusions'] ?? [])))];
        $domain->update(['options' => array_merge($domain->options ?? [], ['waf' => $waf])]);
        app(\App\Services\AuditLogger::class)->log('domains.waf_updated', 'domains', $domain->id, ['name' => $domain->name, 'from' => $before, 'to' => $waf]);
        $request->attributes->set('audit_skip', true);
        if (\App\Services\TraefikConfigService::enabled()) {
            app(\App\Services\TraefikConfigService::class)->push();
        }

        return $this->data(['mode' => $waf['mode'], 'exclusions' => $waf['exclusions'], 'installed' => (bool) config('platform.waf.enabled')]);
    }

    public function verify(Request $request, string $id)
    {
        return $this->data($this->domains->verify($this->findDomain($request, $id)));
    }

    public function certificate(Request $request, string $id)
    {
        $domain = $this->findDomain($request, $id);
        $this->domains->issueCertificate($domain);

        return $this->data($this->domains->toApi($domain->fresh()));
    }
}
