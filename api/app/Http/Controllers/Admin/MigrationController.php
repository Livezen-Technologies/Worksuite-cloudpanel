<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\SiteMigration;
use App\Services\MigrationService;
use Illuminate\Http\Request;

/** Migration wizard (SRS MIG-001..011, UMIG-002): cPanel accounts into apps. */
class MigrationController extends Controller
{
    public function __construct(private MigrationService $migrations) {}

    public function index(Request $request)
    {
        $servers = Server::allTenants()->pluck('name', 'id');
        // Platform administrators also see customers' requests (UMIG-002).
        $query = $this->seesAllTenants($request) ? SiteMigration::allTenants() : SiteMigration::query();

        return $this->data($query->latest()->get()->map(fn (SiteMigration $m) => [
            'id' => $m->id, 'source' => $m->source, 'status' => $m->status, 'requested' => (bool) $m->requested_by, 'server' => $servers[$m->server_id] ?? null,
            'account' => $m->inventory['user'] ?? null, 'sites' => collect($m->sites ?? [])->where('enabled', true)->count(),
            'migrated' => collect($m->sites ?? [])->where('status', 'migrated')->count(), 'created_at' => $m->created_at?->toIso8601ZuluString(),
        ]));
    }

    public function show(Request $request, string $id)
    {
        $m = $this->find($request, $id);

        return $this->data($this->present($m));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'server_id' => 'required|string', 'archive_url' => 'required|url:https,http|max:2000',
            'sha256' => 'nullable|string|regex:/^[a-fA-F0-9]{64}$/',
        ]);
        $request->attributes->set('audit_skip', true);
        $m = $this->migrations->create($data, $request->user()->id);

        return $this->data($this->present($m), 202);
    }

    public function map(Request $request, string $id)
    {
        $data = $request->validate([
            'sites' => 'required|array', 'sites.*.domain' => 'required|string', 'sites.*.enabled' => 'sometimes|boolean',
            'sites.*.app_name' => 'sometimes|string|max:41', 'sites.*.docroot' => 'sometimes|nullable|string|max:255',
            'sites.*.database' => 'sometimes|nullable|string|max:64', 'sites.*.target_database_id' => 'sometimes|nullable|string',
        ]);

        return $this->data($this->present($this->migrations->map($this->find($request, $id), $data['sites'])));
    }

    public function start(Request $request, string $id)
    {
        $request->attributes->set('audit_skip', true);

        return $this->data($this->present($this->migrations->start($this->find($request, $id), $request->user()->id)), 202);
    }

    /** MIG-011 */
    public function bulk(Request $request)
    {
        $data = $request->validate([
            'server_id' => 'required|string', 'archives' => 'required|array|min:1|max:50',
            'archives.*.url' => 'required|url:https,http|max:2000', 'archives.*.sha256' => 'nullable|string|regex:/^[a-fA-F0-9]{64}$/',
        ]);
        $request->attributes->set('audit_skip', true);
        $created = $this->migrations->bulk($data['server_id'], $data['archives'], $request->user()->id);

        return $this->data(['queued' => count($created), 'ids' => array_map(fn ($m) => $m->id, $created)], 202);
    }

    /** UMIG-002 */
    public function decide(Request $request, string $id, string $decision)
    {
        $data = $request->validate(['server_id' => 'nullable|string', 'reason' => 'nullable|string|max:1000']);
        $request->attributes->set('audit_skip', true);
        $m = $this->migrations->decide($this->find($request, $id), $decision === 'approve', $data['server_id'] ?? null, $data['reason'] ?? null, $request->user()->id);

        return $this->data($this->present($m), $decision === 'approve' ? 202 : 200);
    }

    /** MIG-009 */
    public function resync(Request $request, string $id)
    {
        $data = $request->validate(['archive_url' => 'required|url:https,http|max:2000', 'sha256' => 'nullable|string|regex:/^[a-fA-F0-9]{64}$/']);
        $request->attributes->set('audit_skip', true);

        return $this->accepted($this->migrations->resync($this->find($request, $id), $data['archive_url'], $data['sha256'] ?? null, $request->user()->id));
    }

    /** MIG-007 / MIG-008 */
    public function dns(Request $request, string $id, int $site, string $action)
    {
        $m = $this->find($request, $id);
        $request->attributes->set('audit_skip', true);
        $m = $action === 'cutover' ? $this->migrations->cutover($m, $site, $request->user()->id) : $this->migrations->rollback($m, $site, $request->user()->id);

        return $this->data($this->present($m));
    }

    /** Re-run the copy of one site (MIG-004: it resumes where it stopped). */
    public function retry(Request $request, string $id, int $site)
    {
        $m = $this->find($request, $id);
        abort_unless(isset($m->sites[$site]['app_id']) && in_array($m->sites[$site]['status'], ['failed', 'migrated'], true), 409, 'Only a deployed site that failed or finished can be copied again.');

        return $this->accepted($this->migrations->copySite($m, $site, $request->user()->id));
    }

    private function find(Request $request, string $id): SiteMigration
    {
        return $this->seesAllTenants($request) ? SiteMigration::allTenants()->findOrFail($id) : SiteMigration::findOrFail($id);
    }

    private function present(SiteMigration $m): array
    {
        return [
            'id' => $m->id, 'source' => $m->source, 'status' => $m->status, 'server_id' => $m->server_id, 'error' => $m->error,
            'inventory' => $m->inventory, 'sites' => $m->sites ?? [], 'preflight' => $m->status === 'ready' ? $this->migrations->preflight($m) : [],
            'notes' => $m->notes, 'decision' => $m->decision, 'requested' => (bool) $m->requested_by,
            'timeline' => $m->timeline ?? [], 'created_at' => $m->created_at?->toIso8601ZuluString(),
        ];
    }
}
