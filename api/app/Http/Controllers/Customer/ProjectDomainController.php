<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\DnsValidationService;
use App\Services\ProjectDomainService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** PDNS: Project → DNS & Domains. Tenant-scoped by the global scope; per-project permissions on top. */
class ProjectDomainController extends Controller
{
    public function __construct(private ProjectDomainService $pdns) {}

    private function project(Request $request, string $id, string $permission): Project
    {
        $project = Project::findOrFail($id);
        $this->pdns->authorize($request->user(), $project, $permission);

        return $project;
    }

    public function index(Request $request, string $id)
    {
        $project = $this->project($request, $id, 'view');

        return $this->data($this->pdns->overview($project) + ['permissions' => $this->pdns->permissionsFor($request->user(), $project),
            'is_owner' => $this->pdns->isOwner($request->user(), $project), 'permission_labels' => ProjectDomainService::PERMISSIONS]);
    }

    public function show(Request $request, string $id, string $domain)
    {
        $project = $this->project($request, $id, 'view');

        return $this->data($this->pdns->present($this->pdns->find($project, $domain)));
    }

    public function store(Request $request, string $id)
    {
        $project = $this->project($request, $id, 'add');
        $data = $request->validate([
            'kind' => ['required', Rule::in(['custom', 'platform'])],
            'name' => ['required', 'string', 'max:253'],
            'app_id' => ['required', 'uuid'],
            'method' => ['sometimes', Rule::in(['manual', 'provider'])],
            'www_redirect' => ['sometimes', 'boolean'],
        ]);
        if ($data['kind'] === 'custom' && ! preg_match('/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i', trim($data['name'], '. '))) {
            throw \App\Exceptions\ApiException::validation(['name' => ['Enter a domain like shop.example.com, without https://']]);
        }
        $domain = $this->pdns->add($project, $data);

        return $this->data($this->pdns->present($domain) + ['published' => $domain->publishedRecords ?? []], 201);
    }

    public function validateDns(Request $request, string $id, string $domain, DnsValidationService $validation)
    {
        $project = $this->project($request, $id, 'view');

        return $this->data($validation->validate($this->pdns->find($project, $domain)));
    }

    public function update(Request $request, string $id, string $domain)
    {
        $data = $request->validate(['app_id' => 'sometimes|uuid', 'is_primary' => 'sometimes|accepted']);
        $project = Project::findOrFail($id);
        $row = $this->pdns->find($project, $domain);
        if (isset($data['app_id'])) {
            $this->pdns->authorize($request->user(), $project, 'records');
            $this->pdns->changeApp($project, $row, $data['app_id']);
        }
        if (! empty($data['is_primary'])) {
            $this->pdns->authorize($request->user(), $project, 'primary');
            $this->pdns->makePrimary($project, $row);
        }

        return $this->data($this->pdns->present($row->fresh()));
    }

    public function publish(Request $request, string $id, string $domain)
    {
        $project = $this->project($request, $id, 'records');

        return $this->data(['records' => $this->pdns->publish($project, $this->pdns->find($project, $domain))]);
    }

    public function certificate(Request $request, string $id, string $domain)
    {
        $project = $this->project($request, $id, 'ssl');

        return $this->data($this->pdns->renewCertificate($project, $this->pdns->find($project, $domain)));
    }

    public function destroy(Request $request, string $id, string $domain)
    {
        $project = $this->project($request, $id, 'remove');
        $this->pdns->remove($project, $this->pdns->find($project, $domain), $request->input('confirm'));

        return response()->noContent();
    }

    public function history(Request $request, string $id)
    {
        return $this->data($this->pdns->history($this->project($request, $id, 'view')));
    }

    public function grants(Request $request, string $id)
    {
        return $this->data($this->pdns->grants($this->project($request, $id, 'view')));
    }

    public function grant(Request $request, string $id, string $userId)
    {
        $project = Project::findOrFail($id);
        abort_unless($this->pdns->isOwner($request->user(), $project), 403, 'Only the project owner can change DNS permissions.');
        $data = $request->validate(['permissions' => 'present|array', 'permissions.*' => ['string', Rule::in(array_keys(ProjectDomainService::PERMISSIONS))]]);
        $this->pdns->grant($project, $userId, $data['permissions']);

        return $this->data($this->pdns->grants($project));
    }
}
