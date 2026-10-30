<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\SiteMigration;
use App\Services\MigrationService;
use Illuminate\Http\Request;

/** Migration wizard (SRS MIG-001..006): cPanel accounts into apps. */
class MigrationController extends Controller
{
    public function __construct(private MigrationService $migrations) {}

    public function index()
    {
        $servers = Server::allTenants()->pluck('name', 'id');

        return $this->data(SiteMigration::query()->latest()->get()->map(fn (SiteMigration $m) => [
            'id' => $m->id, 'source' => $m->source, 'status' => $m->status, 'server' => $servers[$m->server_id] ?? null,
            'account' => $m->inventory['user'] ?? null, 'sites' => collect($m->sites ?? [])->where('enabled', true)->count(),
            'migrated' => collect($m->sites ?? [])->where('status', 'migrated')->count(), 'created_at' => $m->created_at?->toIso8601ZuluString(),
        ]));
    }

    public function show(string $id)
    {
        $m = SiteMigration::findOrFail($id);

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

        return $this->data($this->present($this->migrations->map(SiteMigration::findOrFail($id), $data['sites'])));
    }

    public function start(Request $request, string $id)
    {
        $request->attributes->set('audit_skip', true);

        return $this->data($this->present($this->migrations->start(SiteMigration::findOrFail($id), $request->user()->id)), 202);
    }

    /** Re-run the copy of one site (MIG-004: it resumes where it stopped). */
    public function retry(Request $request, string $id, int $site)
    {
        $m = SiteMigration::findOrFail($id);
        abort_unless(isset($m->sites[$site]['app_id']) && in_array($m->sites[$site]['status'], ['failed', 'migrated'], true), 409, 'Only a deployed site that failed or finished can be copied again.');

        return $this->accepted($this->migrations->copySite($m, $site, $request->user()->id));
    }

    private function present(SiteMigration $m): array
    {
        return [
            'id' => $m->id, 'source' => $m->source, 'status' => $m->status, 'server_id' => $m->server_id, 'error' => $m->error,
            'inventory' => $m->inventory, 'sites' => $m->sites ?? [], 'preflight' => $m->status === 'ready' ? $this->migrations->preflight($m) : [],
            'timeline' => $m->timeline ?? [], 'created_at' => $m->created_at?->toIso8601ZuluString(),
        ];
    }
}
