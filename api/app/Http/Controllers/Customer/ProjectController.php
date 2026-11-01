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
            'cost_cents' => $this->cost($apps),
            'environments' => $apps->isEmpty() ? [] : \App\Models\Environment::whereIn('id', Application::whereIn('id', $apps->pluck('id'))->whereNotNull('environment_id')->pluck('environment_id'))->pluck('kind')->unique()->values()->all(),
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

    /** Monthly price of the apps' sizes. */
    private function cost($apps): int
    {
        if ($apps->isEmpty()) {
            return 0;
        }
        $sizes = Application::whereIn('id', $apps->pluck('id'))->pluck('size');
        $prices = \App\Models\Plan::whereIn('code', $sizes->unique())->pluck('price_cents', 'code');

        return (int) $sizes->sum(fn ($s) => (int) ($prices[$s] ?? 0));
    }

    /**
     * UPRJ canvas: the project's apps as nodes, and a link wherever one app's variables
     * name another app as a host (e.g. API_URL=http://api:8080). Values never leave the server.
     */
    public function canvas(string $id, \App\Services\HostingService $hosting)
    {
        $p = Project::findOrFail($id);
        $apps = Application::where('kind', 'customer')->where('project_id', $p->id)->orderBy('name')->get();
        $names = $apps->pluck('id', 'name');
        $links = [];
        foreach ($apps as $a) {
            foreach (\App\Models\EnvVar::where('application_id', $a->id)->get() as $var) {
                foreach ($names as $name => $targetId) {
                    if ($targetId === $a->id) {
                        continue;
                    }
                    $re = '/(^|[\/@:=\s,])'.preg_quote($name, '/').'(?=$|[:\/\s,.])/i';
                    if (preg_match($re, (string) $var->value)) {
                        $links[$a->id.'>'.$targetId] = ['from' => $a->id, 'to' => $targetId, 'via' => $var->key];
                    }
                }
            }
        }
        $prices = \App\Models\Plan::whereIn('code', $apps->pluck('size')->unique())->pluck('price_cents', 'code');

        return $this->data([
            'project' => ['id' => $p->id, 'name' => $p->name, 'color' => $p->color ?? 'blue'],
            'nodes' => $apps->map(function ($a) use ($hosting, $prices) {
                $x = $hosting->toApi($a);

                return [
                    'id' => $a->id, 'name' => $a->name, 'state' => $x['state'], 'source' => $x['source'], 'domain' => $x['primary_domain'] ?? $x['address'],
                    'size' => $a->size, 'ram_limit_mb' => $x['ram_limit_mb'], 'cpu_pct' => $x['cpu_pct'], 'ram_mb' => $x['ram_mb'], 'volume_path' => $a->volume_path,
                    'region' => $a->region, 'cost_cents' => (int) ($prices[$a->size] ?? 0),
                    'deployments' => \App\Models\Deployment::where('application_id', $a->id)->latest()->limit(4)->get()
                        ->map(fn ($d) => ['id' => $d->id, 'status' => $d->status, 'source' => $d->source, 'created_at' => $d->created_at?->toIso8601ZuluString()])->values(),
                ];
            })->values(),
            'links' => array_values($links),
        ]);
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
