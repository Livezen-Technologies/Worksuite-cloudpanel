<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ComposeProject;
use App\Models\ComposeRevision;
use App\Services\ComposeService;
use App\Support\Lists;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ComposeProjectController extends Controller
{
    public function __construct(private ComposeService $compose) {}

    public function index(Request $request)
    {
        return response()->json(Lists::paginate(ComposeProject::with('services'), $request, ['name'], ['name', 'created_at', 'status'], ['server_id', 'environment', 'status'], fn ($p) => $this->present($p)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9][a-z0-9_-]*$/', Rule::unique('compose_projects', 'name')->where('organization_id', \App\Support\Tenant::current()->id)],
            'server_id' => 'nullable|uuid|exists:servers,id',
            'environment' => 'sometimes|in:production,staging,development',
            'source' => 'sometimes|in:git,upload,editor',
            'yaml' => 'required|string|max:200000',
            'env' => 'nullable|array',
        ]);
        $this->compose->assertValid($data['yaml']);
        $project = ComposeProject::create(collect($data)->only(['name', 'server_id', 'environment', 'source'])->all());
        $this->compose->addRevision($project, $data['yaml'], $data['env'] ?? null);

        return $this->data($this->present($project->fresh()), 201);
    }

    public function show(string $id)
    {
        return $this->data($this->present(ComposeProject::findOrFail($id), true));
    }

    public function update(Request $request, string $id)
    {
        $project = ComposeProject::findOrFail($id);
        $data = $request->validate([
            'server_id' => 'nullable|uuid|exists:servers,id',
            'environment' => 'sometimes|in:production,staging,development',
            'yaml' => 'sometimes|string|max:200000',
            'env' => 'nullable|array',
        ]);
        $project->update(collect($data)->only(['server_id', 'environment'])->all());
        if (isset($data['yaml'])) {
            $this->compose->addRevision($project, $data['yaml'], $data['env'] ?? null);
        }

        return $this->data($this->present($project->fresh(), true));
    }

    public function destroy(string $id)
    {
        $project = ComposeProject::findOrFail($id);
        if ($project->server && $project->status !== 'draft' && $project->status !== 'removed') {
            $this->compose->action($project, 'remove');
        }
        $project->delete();

        return response()->noContent();
    }

    public function validateYaml(Request $request, string $id)
    {
        $project = ComposeProject::findOrFail($id);

        return $this->data($this->compose->validate((string) $request->input('yaml', $project->currentRevision?->yaml ?? '')));
    }

    public function diff(Request $request, string $id)
    {
        return $this->data($this->compose->diff(ComposeProject::findOrFail($id), $request->input('yaml')));
    }

    public function deploy(Request $request, string $id)
    {
        $project = ComposeProject::findOrFail($id);
        $request->validate(['yaml' => 'nullable|string|max:200000', 'env' => 'nullable|array']);
        if ($request->filled('yaml')) {
            $this->compose->addRevision($project, $request->input('yaml'), $request->input('env'));
            $project->refresh();
        }

        return $this->accepted($this->compose->deploy($project), ['revision_id' => $project->current_revision_id]);
    }

    public function action(Request $request, string $id)
    {
        $data = $request->validate(['action' => 'required|in:stop,restart,remove']);

        return $this->accepted($this->compose->action(ComposeProject::findOrFail($id), $data['action']));
    }

    public function revisions(Request $request, string $id)
    {
        $project = ComposeProject::findOrFail($id);

        return response()->json(Lists::paginate(ComposeRevision::where('compose_project_id', $project->id), $request, [], ['number', 'created_at'], [],
            fn ($r) => $r->toArray() + ['running' => $r->id === $project->running_revision_id, 'current' => $r->id === $project->current_revision_id]));
    }

    public function rollback(Request $request, string $id)
    {
        $project = ComposeProject::findOrFail($id);
        $data = $request->validate(['revision_id' => 'required|uuid']);
        $revision = ComposeRevision::where('compose_project_id', $project->id)->findOrFail($data['revision_id']);

        return $this->accepted($this->compose->rollback($project, $revision), ['revision_id' => $project->fresh()->current_revision_id]);
    }

    private function present(ComposeProject $project, bool $detail = false): array
    {
        $data = array_merge($project->toArray(), [
            'revision' => $project->currentRevision?->number,
            'running_revision' => $project->runningRevision?->number,
            'services' => $project->services->map->only(['name', 'image', 'depends_on', 'health']),
        ]);
        if ($detail) {
            $data['yaml'] = $project->currentRevision?->yaml;
        }

        return $data;
    }
}
