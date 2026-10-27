<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\EnvVar;
use App\Models\Release;
use App\Services\ApprovalService;
use App\Services\Authorizer;
use App\Services\DeploymentService;
use App\Support\Lists;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApplicationController extends Controller
{
    public function __construct(private DeploymentService $deployments, private Authorizer $authorizer) {}

    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:63', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/'],
            'source_type' => [$req, Rule::in(['git', 'image', 'dockerfile', 'compose'])],
            'source' => 'nullable|array',
            'source.repo' => 'nullable|string|max:255', 'source.branch' => 'nullable|string|max:120',
            'image' => 'nullable|string|max:255',
            'strategy' => ['sometimes', Rule::in(['recreate', 'rolling', 'blue_green'])],
            'environment' => ['sometimes', Rule::in(['production', 'staging', 'development', 'preview'])],
            'server_id' => 'nullable|uuid|exists:servers,id',
            'project_id' => 'nullable|uuid',
            'region' => 'nullable|string|max:60',
            'size' => 'nullable|in:small,standard,pro',
            'port' => 'nullable|integer|between:1,65535',
            'volume_path' => 'nullable|string|max:255',
            'requires_approval' => 'sometimes|boolean',
            'auto_deploy' => 'sometimes|boolean',
            'auto_restart' => 'sometimes|boolean',
            'env' => 'nullable|array', 'env.*.key' => 'required|string|max:120|regex:/^[A-Za-z_][A-Za-z0-9_]*$/', 'env.*.value' => 'nullable|string', 'env.*.secret' => 'nullable|boolean',
            'member_ids' => 'nullable|array', 'member_ids.*' => 'uuid',
        ];
    }

    public function index(Request $request)
    {
        $query = Application::query();
        $this->authorizer->scopeAssigned($request->user(), 'resources.view', $query, 'id');

        return response()->json(Lists::paginate($query, $request, ['name', 'image'], ['name', 'created_at', 'last_deployed_at', 'status'], ['kind', 'environment', 'status', 'source_type', 'server_id']));
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules(true));
        if (Application::withTrashed()->where('name', $data['name'])->exists()) {
            throw ApiException::validation(['name' => ['An application with this name already exists.']]);
        }
        $app = Application::create(collect($data)->except(['env', 'member_ids'])->all() + ['kind' => 'admin']);
        $this->syncEnv($app, $data['env'] ?? null);
        $this->syncMembers($app, $data['member_ids'] ?? null);

        return $this->data($this->present($app), 201);
    }

    public function show(Request $request, string $id)
    {
        $app = Application::findOrFail($id);
        $this->authorizeFor($request, 'resources.view', $app);

        return $this->data($this->present($app));
    }

    public function update(Request $request, string $id)
    {
        $app = Application::findOrFail($id);
        $this->authorizeFor($request, 'deploy', $app);
        $data = $request->validate($this->rules(false));
        $app->update(collect($data)->except(['env', 'member_ids'])->all());
        $this->syncEnv($app, $data['env'] ?? null);
        $this->syncMembers($app, $data['member_ids'] ?? null);

        return $this->data($this->present($app->fresh()));
    }

    public function destroy(Request $request, string $id, ApprovalService $approvals)
    {
        $app = Application::findOrFail($id);
        $app->environment === 'production'
            ? $approvals->gate($request->user(), 'production.delete', 'application.delete', $app, [], $request->input('approval_id'))
            : $this->authorizeFor($request, 'deploy', $app);
        $app->retire();

        return response()->noContent();
    }

    public function releases(Request $request, string $id)
    {
        $app = Application::findOrFail($id);
        $this->authorizeFor($request, 'resources.view', $app);

        return response()->json(Lists::paginate(Release::where('application_id', $app->id), $request, [], ['version', 'created_at'], ['status'],
            fn (Release $r) => collect($r->toArray())->except('config')->merge(['config' => collect($r->config)->except(['env_encrypted', 'registry_auth'])])->all()));
    }

    public function rollback(Request $request, string $id)
    {
        $app = Application::findOrFail($id);
        $this->authorizeFor($request, 'deploy', $app);
        $data = $request->validate(['release_id' => 'required|uuid']);
        $release = Release::where('application_id', $app->id)->findOrFail($data['release_id']);
        $deployment = $this->deployments->start($app, $request->user(), 'rollback', $release);

        return $this->accepted($deployment->job, ['deployment_id' => $deployment->id]);
    }

    private function syncEnv(Application $app, ?array $env): void
    {
        if ($env === null) {
            return;
        }
        $keys = collect($env)->pluck('key');
        EnvVar::where('application_id', $app->id)->whereNotIn('key', $keys)->delete();
        foreach ($env as $var) {
            $existing = EnvVar::where('application_id', $app->id)->where('key', $var['key'])->first();
            // A secret sent back without a value keeps its stored value.
            if ($existing && ($var['secret'] ?? false) && ! array_key_exists('value', $var)) {
                continue;
            }
            EnvVar::updateOrCreate(['application_id' => $app->id, 'key' => $var['key']], ['value' => (string) ($var['value'] ?? ''), 'secret' => (bool) ($var['secret'] ?? false)]);
        }
    }

    private function syncMembers(Application $app, ?array $ids): void
    {
        if ($ids !== null) {
            $app->members()->sync(\App\Models\Membership::whereIn('user_id', $ids)->pluck('user_id'));
        }
    }

    private function present(Application $app): array
    {
        return array_merge($app->toArray(), [
            'env' => EnvVar::where('application_id', $app->id)->orderBy('key')->get()->map(fn ($v) => ['key' => $v->key, 'value' => $v->secret ? null : $v->value, 'secret' => $v->secret]),
            'domains' => $app->domains()->pluck('name'),
            'member_ids' => $app->members()->pluck('users.id'),
            'last_deployment' => Deployment::where('application_id', $app->id)->latest()->first(['id', 'status', 'job_id', 'created_at']),
        ]);
    }
}
