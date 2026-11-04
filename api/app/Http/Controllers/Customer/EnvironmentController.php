<?php

namespace App\Http\Controllers\Customer;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Services\EnvironmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** UPRJ-ENV: customers run Production, Staging and Development side by side in a project. */
class EnvironmentController extends Controller
{
    public const MAX_PER_PROJECT = 5;

    public function __construct(private EnvironmentService $envs) {}

    private function show(Environment $e): array
    {
        $x = $this->envs->present($e);

        return [
            'id' => $x['id'], 'name' => $x['name'], 'kind' => $x['kind'], 'branch' => $x['branch'],
            'duplicated_from_id' => $x['duplicated_from_id'],
            'apps' => collect($x['apps'])->map(fn ($a) => collect($a)->only(['id', 'name', 'service', 'status', 'last_deployed_at', 'url'])->all())->values(),
        ];
    }

    public function index(string $id)
    {
        $p = Project::findOrFail($id);

        return $this->data(Environment::where('project_id', $p->id)->orderBy('created_at')->get()->map(fn ($e) => $this->show($e))->values());
    }

    /** Empty, or a copy of another environment's apps and variables (not deployed, no domains). */
    public function store(Request $request, string $id)
    {
        $p = Project::findOrFail($id);
        $v = $request->validate([
            'name' => 'required|string|min:2|max:40',
            'kind' => ['required', Rule::in(['development', 'staging', 'production'])],
            'branch' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'copy_from' => 'nullable|uuid',
        ]);
        if (Environment::where('project_id', $p->id)->count() >= self::MAX_PER_PROJECT) {
            throw ApiException::conflict('A project can have up to '.self::MAX_PER_PROJECT.' environments.');
        }
        if (! empty($v['copy_from'])) {
            $source = Environment::where('project_id', $p->id)->findOrFail($v['copy_from']);
            $env = $this->envs->duplicate($source, $v);
        } else {
            $env = $this->envs->createEnvironment($p, $v);
        }

        return $this->data($this->show($env->fresh()), 201);
    }

    /** Deploy what runs in this environment to the same apps in another one, without rebuilding. */
    public function promote(Request $request, string $id)
    {
        $source = Environment::findOrFail($id);
        $v = $request->validate(['target_environment_id' => 'required|uuid']);
        $target = Environment::where('project_id', $source->project_id)->findOrFail($v['target_environment_id']);

        return $this->data(['results' => $this->envs->promote($source, $target, $request->user())], 202);
    }

    public function destroy(string $id)
    {
        $env = Environment::findOrFail($id);
        if (Application::where('environment_id', $env->id)->exists()) {
            throw ApiException::conflict('Delete or move this environment’s apps first.');
        }
        if (Environment::where('project_id', $env->project_id)->count() <= 1) {
            throw ApiException::conflict('A project needs at least one environment.');
        }
        $env->delete();

        return response()->noContent();
    }
}
