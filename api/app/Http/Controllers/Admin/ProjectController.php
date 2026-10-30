<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\Project;
use App\Services\EnvironmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** ENV-1..7: projects, their environments, duplication, promotion and sleep. */
class ProjectController extends Controller
{
    public function __construct(private EnvironmentService $envs) {}

    private function present(Project $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'description' => $p->description,
            'webhook_url' => url("/api/v1/hooks/git/{$p->id}"),
            'environments' => Environment::where('project_id', $p->id)->orderByRaw("case kind when 'production' then 0 when 'staging' then 1 else 2 end")->orderBy('name')->get()
                ->map(fn ($e) => $this->envs->present($e))->values(),
        ];
    }

    private function envRules(bool $create): array
    {
        return [
            'name' => [$create ? 'required' : 'sometimes', 'string', 'max:40'],
            'kind' => ['sometimes', Rule::in(EnvironmentService::KINDS)],
            'branch' => ['nullable', 'string', 'max:200', 'regex:#^[A-Za-z0-9][A-Za-z0-9._/-]*$#'],
            'requires_approval' => 'sometimes|boolean',
            'sleep_after_minutes' => 'nullable|integer|between:5,10080',
            'variables' => 'sometimes|array', 'variables.*' => 'nullable|string|max:8192',
        ];
    }

    public function index()
    {
        return $this->data(Project::orderBy('name')->get()->map(fn ($p) => $this->present($p))->values());
    }

    public function store(Request $request)
    {
        $v = $request->validate(['name' => 'required|string|max:80', 'description' => 'nullable|string|max:255', 'environments' => 'sometimes|array', 'environments.*' => Rule::in(EnvironmentService::KINDS)]);
        $project = Project::create(['name' => $v['name'], 'description' => $v['description'] ?? null, 'webhook_secret' => Str::random(40)]);
        foreach ($v['environments'] ?? ['production', 'staging'] as $kind) {
            $this->envs->createEnvironment($project, ['name' => $kind, 'kind' => $kind, 'branch' => $kind === 'production' ? 'main' : null]);
        }

        return $this->data($this->present($project) + ['webhook_secret' => $project->webhook_secret], 201);
    }

    public function show(string $id)
    {
        return $this->data($this->present(Project::findOrFail($id)));
    }

    public function destroy(string $id)
    {
        $project = Project::findOrFail($id);
        abort_if(\App\Models\Application::where('project_id', $project->id)->exists(), 409, 'Move or delete the project\'s applications first.');
        $project->delete();

        return response()->noContent();
    }

    public function storeEnvironment(Request $request, string $id)
    {
        $env = $this->envs->createEnvironment(Project::findOrFail($id), $request->validate($this->envRules(true)));

        return $this->data($this->envs->present($env, true), 201);
    }

    public function showEnvironment(string $id)
    {
        return $this->data($this->envs->present(Environment::findOrFail($id), true));
    }

    public function updateEnvironment(Request $request, string $id)
    {
        $env = Environment::findOrFail($id);
        $env->update($request->validate($this->envRules(false)));

        return $this->data($this->envs->present($env->fresh(), true));
    }

    public function destroyEnvironment(string $id)
    {
        $env = Environment::findOrFail($id);
        abort_if(\App\Models\Application::where('environment_id', $env->id)->exists(), 409, 'Move or delete the environment\'s applications first.');
        $env->delete();

        return response()->noContent();
    }

    public function duplicate(Request $request, string $id)
    {
        $v = $request->validate($this->envRules(true));

        return $this->data($this->envs->present($this->envs->duplicate(Environment::findOrFail($id), $v)), 201);
    }

    public function promote(Request $request, string $id)
    {
        $v = $request->validate(['target_environment_id' => 'required|uuid']);
        $result = $this->envs->promote(Environment::findOrFail($id), Environment::findOrFail($v['target_environment_id']), $request->user());

        return $this->data($result, 202);
    }

    public function wake(string $id)
    {
        return $this->data(['woken' => $this->envs->wake(Environment::findOrFail($id))]);
    }

    public function rotateSecret(string $id)
    {
        $project = Project::findOrFail($id);
        $project->update(['webhook_secret' => Str::random(40)]);

        return $this->data(['webhook_secret' => $project->webhook_secret]);
    }
}
