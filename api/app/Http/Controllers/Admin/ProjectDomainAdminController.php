<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Organization;
use App\Models\Project;
use App\Services\DnsValidationService;
use App\Services\ProjectDomainService;
use App\Support\Lists;
use App\Support\Tenant;
use Illuminate\Http\Request;

/**
 * PDNS-2: staff view of every customer's project domains. Each row is presented inside its own
 * organisation's tenant scope, so its app, certificate and zone resolve (a cross-tenant list
 * read under the reader's scope would show them blank).
 */
class ProjectDomainAdminController extends Controller
{
    public function __construct(private ProjectDomainService $pdns, private Tenant $tenant) {}

    private function staffOnly(Request $request): void
    {
        if (! $this->seesAllTenants($request)) {
            throw ApiException::forbidden('Only platform staff can see every customer\'s project domains.');
        }
    }

    public function index(Request $request)
    {
        $this->staffOnly($request);
        $query = Domain::allTenants()->whereNotNull('project_id');
        $projects = [];
        $orgs = [];

        return response()->json(Lists::paginate($query, $request, ['name'], ['name', 'created_at', 'dns_checked_at'], [
            'organization_id', 'project_id',
            'status' => fn ($q, $v) => match ($v) {
                'ownership' => $q->whereNotNull('ownership_token')->whereNull('ownership_verified_at'),
                'dns' => $q->where('dns_status', '!=', 'verified')->where(fn ($q) => $q->whereNull('ownership_token')->orWhereNotNull('ownership_verified_at')),
                'ssl_failed' => $q->where('dns_status', 'verified')->where('https_status', 'failed'),
                'issuing' => $q->where('dns_status', 'verified')->whereNotIn('https_status', ['active', 'failed']),
                'active' => $q->where('dns_status', 'verified')->where('https_status', 'active'),
                default => $q,
            },
        ], function (Domain $d) use (&$projects, &$orgs) {
            $project = $projects[$d->project_id] ??= Project::allTenants()->find($d->project_id);
            $org = $orgs[$d->organization_id] ??= Organization::find($d->organization_id);

            return $this->tenant->as($d->organization_id, fn () => $this->pdns->present(Domain::with('certificate')->find($d->id) ?? $d)) + [
                'organization_id' => $d->organization_id, 'organization_name' => $org?->name,
                'project_name' => $project?->name,
            ];
        }));
    }

    public function validateDns(Request $request, string $id, DnsValidationService $validation)
    {
        $this->staffOnly($request);
        $domain = Domain::allTenants()->whereNotNull('project_id')->findOrFail($id);

        return $this->data($this->tenant->as($domain->organization_id, function () use ($domain, $validation, $request) {
            $result = $validation->validate(Domain::find($domain->id));
            app(\App\Services\AuditLogger::class)->log('domain.validated_by_staff', 'domains', $domain->id, ['domain' => $domain->name, 'project_id' => $domain->project_id, 'ok' => $result['ok'] ?? null, 'by' => $request->user()?->email], $domain->organization_id);

            return $result;
        }));
    }
}
