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
            'source.repo' => ['nullable', 'string', 'max:512', 'regex:#^(https?://|file:///|/|ssh://[A-Za-z0-9._-]+@|[A-Za-z0-9._-]+@[A-Za-z0-9][A-Za-z0-9.-]*:)[^\s]+$#'], 'source.branch' => 'nullable|string|max:120',
            'source.ref' => ['nullable', 'string', 'max:200', 'regex:#^[A-Za-z0-9][A-Za-z0-9._/-]*$#'],
            'source.dockerfile_path' => ['nullable', 'string', 'max:255', 'not_regex:#(^/|\.\.)#'],
            'source.context' => ['nullable', 'string', 'max:255', 'not_regex:#(^/|\.\.)#'],
            'source.builder' => 'nullable|in:dockerfile,auto', 'source.no_cache' => 'nullable|boolean',
            'source.build_args' => 'nullable|array', 'source.build_args.*' => 'nullable|string|max:4096',
            'source.git_token' => 'nullable|string|max:4096', // write-only: stored as a secret, sent to the builder by reference
            'source.git_username' => 'nullable|string|max:120',
            'source.git_secret_id' => 'nullable|uuid|exists:secrets,id',
            'source.git_ssh_key_id' => 'nullable|uuid', // GIT: a deploy key for SSH repositories
            'source.git_connection_id' => 'nullable|uuid', // GIT: a provider connection's token
            'source.push_registry_id' => 'nullable|uuid|exists:registries,id',
            'health_path' => ['nullable', 'string', 'max:255', 'regex:#^/[^\s]*$#'],
            'health_timeout' => 'nullable|integer|between:5,900',
            'image' => 'nullable|string|max:255',
            'strategy' => ['sometimes', Rule::in(['recreate', 'rolling', 'blue_green'])],
            'environment' => ['sometimes', Rule::in(['production', 'staging', 'development', 'preview'])],
            'server_id' => 'nullable|uuid|exists:servers,id',
            'project_id' => 'nullable|uuid',
            'environment_id' => 'nullable|uuid',
            'service' => ['nullable', 'string', 'max:63', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/'],
            'region' => 'nullable|string|max:60',
            'size' => 'nullable|in:small,standard,pro',
            'port' => 'nullable|integer|between:1,65535',
            'volume_path' => 'nullable|string|max:255',
            'requires_approval' => 'sometimes|boolean',
            'auto_deploy' => 'sometimes|boolean',
            'auto_restart' => 'sometimes|boolean',
            'mesh_network' => 'sometimes|boolean',
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

    /** Wizard extras on create: the public address and whether to deploy straight away. */
    private function createRules(): array
    {
        return [
            'domain_mode' => 'nullable|in:auto,subdomain,custom,none',
            'domain' => ['nullable', 'string', 'max:253', 'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
            'subdomain' => 'nullable|string|max:63',
            'deploy' => 'sometimes|boolean',
        ];
    }

    /** New application wizard: check access, capacity and the address before anything is created. */
    public function preflight(Request $request, \App\Services\ApplicationPreflight $preflight)
    {
        $loose = $this->rules(true);
        $loose['name'] = 'nullable|string|max:200';
        $loose['source.repo'] = 'nullable|string|max:512';
        $loose['domain'] = 'nullable|string|max:253';
        $loose['env.*.key'] = 'nullable|string|max:120';

        return $this->data($preflight->run($request->validate($loose + collect($this->createRules())->except('domain')->all())));
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules(true) + $this->createRules());
        $this->checkGitRefs($data);
        $extras = collect($data)->only(['domain_mode', 'domain', 'subdomain', 'deploy'])->all();
        // A Git source without a branch builds the repository's default branch, not a guessed "main".
        if (in_array($data['source_type'], ['git', 'dockerfile', 'compose'], true) && ! empty($data['source']['repo']) && empty($data['source']['branch'])) {
            $repo = $data['source']['repo'];
            $token = $data['source']['git_token'] ?? null;
            $data['source']['branch'] = app(\App\Contracts\SourceValidator::class)->defaultBranch(
                $token && str_starts_with($repo, 'https://') ? 'https://x-access-token:'.rawurlencode($token).'@'.substr($repo, 8) : $repo) ?? 'main';
        }
        $data = collect($data)->except(['domain_mode', 'domain', 'subdomain', 'deploy'])->all();
        $templates = app(\App\Services\TemplateService::class);
        [$host, $platformHost] = $templates->resolveHost($extras, $data['name']);
        if ($host && empty($data['port'])) {
            throw ApiException::validation(['port' => ['A public address needs the port the app listens on.']]);
        }
        if (Application::withTrashed()->where('name', $data['name'])->exists()) {
            throw ApiException::validation(['name' => ['An application with this name already exists.']]);
        }
        // App, variables and domain are created together or not at all.
        $app = \Illuminate\Support\Facades\DB::transaction(function () use ($data, $host, $platformHost) {
            $data = $this->storeGitToken($data, $data['name']);
            $data = $this->withEnvironment($data);
            $app = Application::create(collect($data)->except(['env', 'member_ids'])->all() + ['kind' => 'admin']);
            $this->syncEnv($app, $data['env'] ?? null);
            $this->syncMembers($app, $data['member_ids'] ?? null);
            if ($host) {
                app(\App\Services\DomainService::class)->create(['name' => $host, 'application_id' => $app->id, 'target_port' => $app->port, 'platform_subdomain' => $platformHost], $platformHost);
            }

            return $app;
        });
        $out = $this->present($app->fresh());
        $out['url'] = $host ? 'https://'.$host : null;
        // The first deployment runs as a background job; the wizard follows it instead of waiting here.
        if (! empty($extras['deploy'])) {
            $deployment = app(\App\Services\DeploymentService::class)->start($app->fresh(), $request->user(), 'manual');
            $out['deployment_id'] = $deployment->id;
            $out['job_id'] = $deployment->job_id;
            $out['deployment_status'] = $deployment->status;
        }

        return $this->data($out, 201);
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
        $validated = $request->validate($this->rules(false));
        $this->checkGitRefs($validated);
        $data = $this->storeGitToken($validated, $app->name, $app->source['git_secret_id'] ?? null);
        $data = $this->withEnvironment($data);
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
        if (! empty($release->config['image_deleted_at'])) {
            throw \App\Exceptions\ApiException::conflict("Release v{$release->version}'s image was removed from the registry by the clean-up; redeploy from source instead.");
        }
        $deployment = $this->deployments->start($app, $request->user(), 'rollback', $release);

        return $this->accepted($deployment->job, ['deployment_id' => $deployment->id]);
    }

    /** GIT: a deploy key or connection must belong to this organisation (the tenant scope hides others). */
    private function checkGitRefs(array $data): void
    {
        $src = $data['source'] ?? [];
        if (! empty($src['git_ssh_key_id']) && ! \App\Models\SshKey::whereKey($src['git_ssh_key_id'])->exists()) {
            throw \App\Exceptions\ApiException::validation(['source.git_ssh_key_id' => ['Unknown deploy key.']]);
        }
        if (! empty($src['git_connection_id']) && ! \App\Models\GitConnection::whereKey($src['git_connection_id'])->exists()) {
            throw \App\Exceptions\ApiException::validation(['source.git_connection_id' => ['Unknown Git connection.']]);
        }
    }

    /** A Git token is never stored on the application: it becomes an encrypted secret referenced by id. */
    private function storeGitToken(array $data, string $name, ?string $existingSecretId = null): array
    {
        if (! isset($data['source']) || ! array_key_exists('git_token', $data['source'])) {
            if (isset($data['source']) && $existingSecretId && ! array_key_exists('git_secret_id', $data['source'])) {
                $data['source']['git_secret_id'] = $existingSecretId; // keep the stored credential on source edits
            }

            return $data;
        }
        $token = $data['source']['git_token'];
        unset($data['source']['git_token']);
        if ($token) {
            $secret = \App\Models\Secret::create(['name' => "app-{$name}-git-token", 'value' => $token, 'owner_type' => 'application']);
            $data['source']['git_secret_id'] = $secret->id;
        }

        return $data;
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

    /** ENV-1: an app placed in an environment takes its project and kind. */
    private function withEnvironment(array $data): array
    {
        if (! empty($data['environment_id'])) {
            $env = \App\Models\Environment::findOrFail($data['environment_id']);
            $data['project_id'] = $env->project_id;
            $data['environment'] = $env->kind;
        }

        return $data;
    }
}
