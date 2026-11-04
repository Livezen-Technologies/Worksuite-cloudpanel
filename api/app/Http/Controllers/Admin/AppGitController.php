<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\SourceValidator;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\GitConnection;
use App\Models\Project;
use App\Models\SshKey;
use App\Models\WebhookDelivery;
use App\Services\AuditLogger;
use App\Support\SshKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * GIT-TAB: everything an app needs to deploy from Git on its own, in one place:
 * the repository and branch, a deploy key (generated here), the webhook to add
 * to the repository, a connection test and the "deploy on push" switch.
 */
class AppGitController extends Controller
{
    public const REPO = '#^(https?://|ssh://[A-Za-z0-9._-]+@|[A-Za-z0-9._-]+@[A-Za-z0-9][A-Za-z0-9.-]*:)[^\s]+$#';

    public function __construct(private AuditLogger $audit) {}

    private function project(Application $app): ?Project
    {
        $id = $app->environmentModel?->project_id ?? $app->project_id;

        return $id ? Project::find($id) : null;
    }

    private function state(Application $app): array
    {
        $src = (array) $app->source;
        $key = ! empty($src['git_ssh_key_id']) ? SshKey::find($src['git_ssh_key_id']) : null;
        $project = $this->project($app);
        $repo = (string) ($src['repo'] ?? '');

        return [
            'source_type' => $app->source_type,
            'repo' => $repo ?: null,
            'branch' => $src['branch'] ?? null,
            'uses_ssh' => $repo !== '' && ! str_starts_with($repo, 'http'),
            'deploy_on_push' => $app->kind === 'customer' ? (bool) $app->auto_deploy : (($src['deploy_on_push'] ?? true) !== false),
            'deploy_key' => $key ? ['id' => $key->id, 'name' => $key->name, 'public_key' => $key->public_key, 'fingerprint' => $key->fingerprint] : null,
            'git_connection_id' => $src['git_connection_id'] ?? null,
            'keys' => SshKey::orderBy('name')->get(['id', 'name', 'fingerprint'])->map->only(['id', 'name', 'fingerprint'])->values(),
            'connections' => GitConnection::orderBy('provider')->get(['id', 'provider', 'account', 'status'])->map->only(['id', 'provider', 'account', 'status'])->values(),
            'project' => $project ? ['id' => $project->id, 'name' => $project->name] : null,
            'environment' => $app->environmentModel ? ['name' => $app->environmentModel->name, 'branch' => $app->environmentModel->branch] : null,
            'webhook' => $project ? ['url' => url("/api/v1/hooks/git/{$project->id}"), 'has_secret' => (bool) $project->webhook_secret, 'content_type' => 'application/json', 'events' => ['push', 'pull_request']] : null,
            'deliveries' => $project ? WebhookDelivery::where('project_id', $project->id)->latest('created_at')->limit(10)->get(['id', 'event', 'repo', 'ref', 'outcome', 'detail', 'created_at'])
                ->map(fn ($d) => $d->only(['id', 'event', 'repo', 'ref', 'outcome', 'detail']) + ['created_at' => $d->created_at?->toIso8601ZuluString()])->values() : [],
            'last_deployment' => Deployment::where('application_id', $app->id)->latest()->first(['id', 'status', 'source', 'created_at']),
        ];
    }

    private function app(Request $request, string $id, string $perm): Application
    {
        $app = Application::findOrFail($id);
        $this->authorizeFor($request, $perm, $app);

        return $app;
    }

    public function show(Request $request, string $id)
    {
        return $this->data($this->state($this->app($request, $id, 'resources.view')));
    }

    public function update(Request $request, string $id)
    {
        $app = $this->app($request, $id, 'deploy');
        $v = $request->validate([
            'repo' => ['required', 'string', 'max:512', 'regex:'.self::REPO],
            'branch' => ['required', 'string', 'max:120', 'regex:#^[A-Za-z0-9][A-Za-z0-9._/-]*$#'],
            'git_ssh_key_id' => 'nullable|uuid',
            'git_connection_id' => 'nullable|uuid',
            'deploy_on_push' => 'required|boolean',
        ]);
        if (! empty($v['git_ssh_key_id']) && ! SshKey::whereKey($v['git_ssh_key_id'])->exists()) {
            throw ApiException::validation(['git_ssh_key_id' => ['Unknown deploy key.']]);
        }
        if (! empty($v['git_connection_id']) && ! GitConnection::whereKey($v['git_connection_id'])->exists()) {
            throw ApiException::validation(['git_connection_id' => ['Unknown Git connection.']]);
        }
        $src = array_merge((array) $app->source, [
            'repo' => $v['repo'], 'branch' => $v['branch'],
            'git_ssh_key_id' => $v['git_ssh_key_id'] ?? null, 'git_connection_id' => $v['git_connection_id'] ?? null,
            'deploy_on_push' => (bool) $v['deploy_on_push'],
        ]);
        unset($src['ref']); // follow the branch from now on
        $data = ['source' => $src];
        // An app that ran a fixed image now builds from the repository.
        if (! in_array($app->source_type, ['git', 'dockerfile', 'compose'], true)) {
            $data['source_type'] = 'git';
        }
        if ($app->kind === 'customer') {
            $data['auto_deploy'] = (bool) $v['deploy_on_push'];
        }
        $app->update($data);
        $this->audit->log('app.git.update', 'application', $app->id, ['repo' => $v['repo'], 'branch' => $v['branch'], 'deploy_on_push' => (bool) $v['deploy_on_push']]);

        return $this->data($this->state($app->fresh()));
    }

    /** A new deploy key for this app, attached to it; the public half goes into the repository. */
    public function generateKey(Request $request, string $id)
    {
        $app = $this->app($request, $id, 'deploy');
        $base = Str::limit(Str::slug($app->name), 60, '').'-deploy';
        $name = $base;
        for ($n = 2; SshKey::where('name', $name)->exists(); $n++) {
            $name = "{$base}-{$n}";
        }
        $k = SshKeys::generate($name);
        $key = SshKey::create(['name' => $name, 'public_key' => $k['public'], 'private_key' => $k['private'], 'fingerprint' => $k['fingerprint']]);
        $app->update(['source' => array_merge((array) $app->source, ['git_ssh_key_id' => $key->id])]);
        $this->audit->log('git.key.create', 'ssh_key', $key->id, ['name' => $key->name, 'fingerprint' => $key->fingerprint, 'application_id' => $app->id]);

        return $this->data($this->state($app->fresh()), 201);
    }

    /** Reads the branch the way the build will: with the deploy key for SSH addresses. */
    public function test(Request $request, string $id, SourceValidator $sources)
    {
        $app = $this->app($request, $id, 'deploy');
        $src = (array) $app->source;
        $v = $request->validate([
            'repo' => ['nullable', 'string', 'max:512', 'regex:'.self::REPO],
            'branch' => ['nullable', 'string', 'max:120', 'regex:#^[A-Za-z0-9][A-Za-z0-9._/-]*$#'],
            'git_ssh_key_id' => 'nullable|uuid',
        ]);
        $repo = $v['repo'] ?? ($src['repo'] ?? null);
        $branch = $v['branch'] ?? ($src['branch'] ?? 'main');
        if (! $repo) {
            throw ApiException::validation(['repo' => ['Enter the repository address first.']]);
        }
        $keyId = array_key_exists('git_ssh_key_id', $v) ? $v['git_ssh_key_id'] : ($src['git_ssh_key_id'] ?? null);
        $private = null;
        if (! str_starts_with($repo, 'http')) {
            $key = $keyId ? SshKey::find($keyId) : null;
            if (! $key) {
                return $this->data(['ok' => false, 'message' => 'An SSH address needs a deploy key. Generate one, add its public key to the repository, then test again.']);
            }
            $private = $key->private_key;
            $key->forceFill(['last_used_at' => now()])->save();
        }

        return $this->data($sources->git($repo, $branch, $private));
    }

    /** A new webhook secret for the app's project, shown once; the repository's webhook must be updated. */
    public function rotateSecret(Request $request, string $id)
    {
        $app = $this->app($request, $id, 'deploy');
        $project = $this->project($app) ?? throw ApiException::conflict('Put this app in a project first: the webhook belongs to the project.');
        $project->update(['webhook_secret' => Str::random(40)]);
        $this->audit->log('project.webhook_secret.rotate', 'project', $project->id, ['application_id' => $app->id]);

        return $this->data(['webhook_secret' => $project->webhook_secret, 'url' => url("/api/v1/hooks/git/{$project->id}")]);
    }
}
