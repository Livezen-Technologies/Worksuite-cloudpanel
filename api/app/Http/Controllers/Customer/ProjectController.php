<?php

namespace App\Http\Controllers\Customer;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Domain;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** UPRJ: the customer groups their own apps into projects. Tenant-scoped by the global scope. */
class ProjectController extends Controller
{
    public const COLORS = ['blue', 'violet', 'green', 'amber', 'rose', 'teal', 'slate'];

    private function present(Project $p): array
    {
        $apps = Application::where('kind', 'customer')->where('project_id', $p->id)->orderBy('name')->get(['id', 'name', 'status', 'region']);

        return [
            'id' => $p->id, 'name' => $p->name, 'description' => $p->description, 'color' => $p->color ?? 'blue',
            'apps' => $apps->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'status' => $a->status])->values(),
            'counts' => [
                'apps' => $apps->count(),
                'domains' => $apps->isEmpty() ? 0 : Domain::whereIn('application_id', $apps->pluck('id'))->count(),
            ],
            'regions' => $apps->pluck('region')->filter()->unique()->values(),
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    private function rules(bool $create): array
    {
        return [
            'name' => [$create ? 'required' : 'sometimes', 'string', 'min:2', 'max:80'],
            'description' => 'nullable|string|max:255',
            'color' => ['sometimes', Rule::in(self::COLORS)],
        ];
    }

    public function index()
    {
        $projects = Project::orderBy('name')->get()->map(fn ($p) => $this->present($p))->values();
        $unassigned = Application::where('kind', 'customer')->whereNull('project_id')->orderBy('name')->get(['id', 'name', 'status'])
            ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'status' => $a->status])->values();

        return $this->data(['projects' => $projects, 'unassigned' => $unassigned, 'colors' => self::COLORS]);
    }

    public function store(Request $request)
    {
        $v = $request->validate($this->rules(true));
        $this->unique($v['name']);
        $p = Project::create($v + ['webhook_secret' => Str::random(40)]);

        return $this->data($this->present($p), 201);
    }

    public function update(Request $request, string $id)
    {
        $p = Project::findOrFail($id);
        $v = $request->validate($this->rules(false));
        if (isset($v['name'])) {
            $this->unique($v['name'], $p->id);
        }
        $p->update($v);

        return $this->data($this->present($p->fresh()));
    }

    /** Apps go back to "no project"; they are never deleted with it. */
    public function destroy(string $id)
    {
        $p = Project::findOrFail($id);
        Application::where('project_id', $p->id)->update(['project_id' => null]);
        $p->delete();

        return response()->noContent();
    }

    /** Move one of the customer's apps into this project (or out, with project_id null on the app route). */
    public function assign(Request $request, string $id)
    {
        $p = Project::findOrFail($id);
        $v = $request->validate(['app_id' => 'required|uuid']);
        $app = Application::where('kind', 'customer')->findOrFail($v['app_id']);
        $app->update(['project_id' => $p->id]);

        return $this->data($this->present($p->fresh()));
    }

    public function unassign(string $id, string $appId)
    {
        $p = Project::findOrFail($id);
        Application::where('kind', 'customer')->where('project_id', $p->id)->findOrFail($appId)->update(['project_id' => null]);

        return $this->data($this->present($p->fresh()));
    }

    private function unique(string $name, ?string $except = null): void
    {
        $q = Project::whereRaw('lower(name) = ?', [mb_strtolower(trim($name))]);
        if ($except) {
            $q->where('id', '!=', $except);
        }
        if ($q->exists()) {
            throw ApiException::conflict('You already have a project with that name.');
        }
    }
}
