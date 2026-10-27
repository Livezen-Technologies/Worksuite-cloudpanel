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

    public function index(Request $request)
    {
        return response()->json(Lists::paginate(Domain::with('certificate'), $request, ['name'], ['name', 'created_at'], [
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

    public function show(string $id)
    {
        return $this->data($this->domains->toApi(Domain::findOrFail($id)));
    }

    public function update(Request $request, string $id)
    {
        $domain = Domain::findOrFail($id);
        $data = $request->validate([
            'application_id' => 'nullable|uuid|exists:applications,id', 'target_port' => 'nullable|integer|between:1,65535',
            'www_redirect' => 'sometimes|boolean', 'challenge' => 'sometimes|in:http-01,dns-01', 'options' => 'nullable|array',
            'dns_provider' => 'nullable|in:'.implode(',', array_keys(\App\Services\TraefikConfigService::DNS_PROVIDERS)), 'dns_token' => 'nullable|string|max:4096',
        ]);
        $domain->update(collect($data)->except('dns_token')->all());
        if (! empty($data['dns_token'])) {
            $this->domains->setDnsToken($domain, $data['dns_token']);
        }
        app(\App\Services\TraefikConfigService::class)->push();

        return $this->data($this->domains->toApi($domain->fresh()));
    }

    public function destroy(string $id)
    {
        Domain::findOrFail($id)->delete();

        return response()->noContent();
    }

    public function verify(string $id)
    {
        return $this->data($this->domains->verify(Domain::findOrFail($id)));
    }

    public function certificate(string $id)
    {
        $domain = Domain::findOrFail($id);
        $this->domains->issueCertificate($domain);

        return $this->data($this->domains->toApi($domain->fresh()));
    }
}
