<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Application;
use App\Models\Container;
use App\Models\Domain;
use App\Models\EnvVar;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Release;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Projects and environments (ENV-1..7): duplication, `${{…}}` variable
 * references, Git branch mapping, promotion between environments and sleep.
 *
 * References in an app's variables are resolved when a release is snapshotted:
 *   ${{shared.KEY}}     the environment's shared variable KEY
 *   ${{api.KEY}}        variable KEY of the app (service) "api" in the same environment
 *   ${{api.HOST}}       its internal host name; also PORT and URL (http://host:port)
 */
class EnvironmentService
{
    public const KINDS = ['production', 'staging', 'development', 'preview'];

    private const REF = '/\$\{\{\s*([a-z0-9][a-z0-9-]*)\.([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/';

    public function __construct(private CommandBus $bus) {}

    /** @return array{0: array<string,string>, 1: list<string>} resolved env and warnings */
    public function resolve(Application $app, array $env): array
    {
        $warnings = [];
        $environment = $app->environment_id ? Environment::allTenants()->find($app->environment_id) : null;
        $shared = (array) ($environment?->variables ?? []);
        // Shared variables are inherited; an app's own value wins.
        $env = array_merge(array_map('strval', $shared), $env);
        $siblings = $environment ? Application::allTenants()->where('environment_id', $environment->id)->get()->keyBy(fn ($a) => $a->service ?: $a->name) : collect();
        $resolveOne = function (string $value, int $depth) use (&$resolveOne, $shared, $siblings, $app, &$warnings): string {
            return preg_replace_callback(self::REF, function ($m) use ($depth, &$resolveOne, $shared, $siblings, $app, &$warnings) {
                [$all, $scope, $key] = $m;
                if ($scope === 'shared') {
                    if (! array_key_exists($key, $shared)) {
                        $warnings[] = "{$all}: no shared variable {$key}";

                        return '';
                    }

                    return $depth < 3 ? $resolveOne((string) $shared[$key], $depth + 1) : (string) $shared[$key];
                }
                $other = $siblings->get($scope);
                if (! $other) {
                    $warnings[] = "{$all}: no service {$scope} in this environment";

                    return '';
                }
                $host = $other->containerName();
                $value = match ($key) {
                    'HOST' => $host,
                    'PORT' => (string) $other->port,
                    'URL' => 'http://'.$host.($other->port ? ':'.$other->port : ''),
                    default => $other->id === $app->id ? null : EnvVar::allTenants()->where('application_id', $other->id)->where('key', $key)->value('value'),
                };
                if ($value === null) {
                    $warnings[] = "{$all}: {$scope} has no variable {$key}";

                    return '';
                }

                return $depth < 3 ? $resolveOne((string) $value, $depth + 1) : (string) $value;
            }, $value);
        };
        foreach ($env as $k => $v) {
            $env[$k] = $resolveOne((string) $v, 0);
        }

        return [$env, array_values(array_unique($warnings))];
    }

    public function createEnvironment(Project $project, array $data): Environment
    {
        $slug = Str::slug($data['slug'] ?? $data['name']);
        if (Environment::where('project_id', $project->id)->where('slug', $slug)->exists()) {
            throw ApiException::validation(['name' => ['This project already has an environment with that name.']]);
        }

        return Environment::create([
            'project_id' => $project->id, 'name' => $data['name'], 'slug' => $slug,
            'kind' => $data['kind'] ?? 'development', 'branch' => $data['branch'] ?? null,
            'requires_approval' => (bool) ($data['requires_approval'] ?? ($data['kind'] ?? null) === 'production'),
            'variables' => $data['variables'] ?? [], 'sleep_after_minutes' => $data['sleep_after_minutes'] ?? null,
        ]);
    }

    /** ENV-2: copies the environment, its shared variables and every app with its variables (not deployed, no domains). */
    public function duplicate(Environment $source, array $data): Environment
    {
        return DB::transaction(function () use ($source, $data) {
            $copy = $this->createEnvironment($source->project, [
                'name' => $data['name'], 'kind' => $data['kind'] ?? ($source->kind === 'production' ? 'staging' : $source->kind),
                'branch' => $data['branch'] ?? $source->branch, 'variables' => $source->variables ?? [],
                'sleep_after_minutes' => $data['sleep_after_minutes'] ?? $source->sleep_after_minutes,
            ]);
            $copy->update(['duplicated_from_id' => $source->id]);
            foreach (Application::where('environment_id', $source->id)->get() as $app) {
                $service = $app->service ?: $app->name;
                $name = substr($service.'-'.$copy->slug, 0, 63);
                if (Application::allTenants()->where('name', $name)->exists()) {
                    throw ApiException::conflict("An application named {$name} already exists.");
                }
                $clone = $app->replicate(['current_release_id', 'last_deployed_at', 'status', 'asleep_at', 'woken_at', 'replicas', 'image']);
                $source_ = (array) $clone->source;
                if ($copy->branch && isset($source_['repo'])) {
                    $source_['branch'] = $copy->branch;
                    unset($source_['ref']);
                }
                $clone->fill([
                    'name' => $name, 'service' => $service, 'environment_id' => $copy->id, 'status' => 'created',
                    'environment' => $copy->kind, 'requires_approval' => $copy->requires_approval, 'source' => $source_,
                    'image' => in_array($app->source_type, ['git', 'dockerfile'], true) ? null : $app->image,
                ])->save();
                foreach (EnvVar::where('application_id', $app->id)->get() as $var) {
                    EnvVar::create(['application_id' => $clone->id, 'key' => $var->key, 'value' => $var->value, 'secret' => $var->secret, 'secret_id' => $var->secret_id]);
                }
            }

            return $copy;
        });
    }

    /**
     * ENV-5: deploy each source app's live image to the same service in the
     * target environment, with the target's own variables. Nothing is rebuilt.
     *
     * @return list<array{service:string, deployment_id:?string, skipped:?string}>
     */
    public function promote(Environment $source, Environment $target, ?User $user): array
    {
        if ($source->project_id !== $target->project_id || $source->id === $target->id) {
            throw ApiException::validation(['target_environment_id' => ['Promote to another environment of the same project.']]);
        }
        $deployments = app(DeploymentService::class);
        $targets = Application::where('environment_id', $target->id)->get()->keyBy(fn ($a) => $a->service ?: $a->name);
        $out = [];
        foreach (Application::where('environment_id', $source->id)->get() as $app) {
            $service = $app->service ?: $app->name;
            $live = $app->current_release_id ? Release::find($app->current_release_id) : null;
            $dest = $targets->get($service);
            if (! $live || ! $dest) {
                $out[] = ['service' => $service, 'deployment_id' => null, 'skipped' => ! $live ? 'not deployed in '.$source->name : 'no such service in '.$target->name];

                continue;
            }
            $config = $deployments->snapshot($dest);
            $config['image'] = $live->image;
            $config['registry_auth'] = $live->config['registry_auth'] ?? $config['registry_auth'];
            $config['promoted_from'] = ['environment' => $source->name, 'application_id' => $app->id, 'version' => $live->version];
            $candidate = Release::create([
                'application_id' => $dest->id, 'version' => (int) Release::where('application_id', $dest->id)->max('version') + 1,
                'image' => $live->image, 'digest' => $live->digest, 'config' => $config, 'status' => 'superseded', 'created_at' => now(),
            ]);
            $deployment = $deployments->start($dest, $user, 'promotion', $candidate);
            $out[] = ['service' => $service, 'deployment_id' => $deployment->id, 'skipped' => null];
        }

        return $out;
    }

    /** ENV-4: apps whose environment tracks `$branch` and whose repository is `$repo`. */
    public function onPush(Project $project, string $repo, string $branch): array
    {
        $norm = fn (?string $u) => strtolower(preg_replace(['#\.git$#', '#/+$#', '#^[a-z]+://([^@/]+@)?#i'], '', trim((string) $u)));
        $envIds = Environment::where('project_id', $project->id)->where('branch', $branch)->pluck('id');
        $started = [];
        foreach (Application::whereIn('environment_id', $envIds)->get() as $app) {
            if (($app->source['repo'] ?? null) && $norm($app->source['repo']) === $norm($repo)) {
                $started[] = app(DeploymentService::class)->start($app, null, 'webhook')->id;
            }
        }

        return $started;
    }

    /**
     * ENV: a pull request opened (or pushed to) gets `PR #<n>`, a copy of the project's
     * preview source environment on the PR's branch, with a platform address per app.
     *
     * @return array{environment: Environment, deployments: list<string>, urls: list<string>}
     */
    public function openPreview(Project $project, int $number, string $branch): array
    {
        $env = Environment::where('project_id', $project->id)->where('preview_number', $number)->first();
        $created = false;
        if (! $env) {
            $source = $project->preview_environment_id ? Environment::find($project->preview_environment_id) : null;
            $source ??= Environment::where('project_id', $project->id)->where('kind', 'staging')->first();
            if (! $source) {
                throw ApiException::conflict('Choose a preview source environment for this project first.');
            }
            $env = $this->duplicate($source, ['name' => "PR #{$number}", 'kind' => 'preview', 'branch' => $branch]);
            $env->update(['preview_number' => $number, 'requires_approval' => false]);
            $created = true;
        } elseif ($env->branch !== $branch) {
            $env->update(['branch' => $branch]);
        }
        $urls = [];
        $deployments = [];
        $domains = app(DomainService::class);
        foreach (Application::where('environment_id', $env->id)->get() as $app) {
            if ($created || ! Domain::where('application_id', $app->id)->exists()) {
                $host = substr($app->name, 0, 50).'.'.config('platform.domain');
                if (! Domain::allTenants()->where('name', $host)->exists() && $app->port) {
                    $domains->create(['name' => $host, 'application_id' => $app->id, 'target_port' => $app->port, 'platform_subdomain' => true], true);
                }
            }
            if (isset($app->source['repo'])) {
                $app->update(['source' => array_merge($app->source, ['branch' => $branch])]);
            }
            $deployments[] = app(DeploymentService::class)->start($app->fresh(), null, 'preview')->id;
            if ($d = Domain::where('application_id', $app->id)->value('name')) {
                $urls[] = 'https://'.$d;
            }
        }

        return ['environment' => $env, 'deployments' => $deployments, 'urls' => $urls];
    }

    /** ENV: the pull request was closed or merged; its environment and apps go. */
    public function closePreview(Project $project, int $number): bool
    {
        $env = Environment::where('project_id', $project->id)->where('preview_number', $number)->first();
        if (! $env) {
            return false;
        }
        foreach (Application::where('environment_id', $env->id)->get() as $app) {
            Domain::where('application_id', $app->id)->delete();
            $app->retire();
        }
        Application::withTrashed()->where('environment_id', $env->id)->update(['environment_id' => null]);
        $env->delete();
        app(TraefikConfigService::class)->push();

        return true;
    }

    /** ENV-7: a request reached a sleeping app; start it again. */
    public function wakeApp(Application $app): bool
    {
        if (! $app->asleep_at) {
            return false;
        }
        $this->containers($app, 'container.start');
        $app->update(['asleep_at' => null, 'woken_at' => now(), 'status' => 'running']);
        app(TraefikConfigService::class)->push();

        return true;
    }

    /** ENV-7: stop the apps of sleeping environments after their idle window. */
    public function sleepIdle(): int
    {
        $n = 0;
        foreach (Environment::allTenants()->whereNotNull('sleep_after_minutes')->where('kind', '!=', 'production')->get() as $env) {
            $cutoff = now()->subMinutes($env->sleep_after_minutes);
            $apps = Application::allTenants()->where('environment_id', $env->id)->whereNull('asleep_at')->where('status', 'running')->get()
                ->filter(fn ($a) => max($a->last_deployed_at?->timestamp ?? 0, $a->woken_at?->timestamp ?? 0) < $cutoff->timestamp);
            foreach ($apps as $app) {
                $this->containers($app, 'container.stop');
                $app->update(['asleep_at' => now(), 'status' => 'sleeping']);
                $n++;
            }
        }
        // Sleeping apps' domains show the waking page, which starts them on the next request.
        $n && app(TraefikConfigService::class)->push();

        return $n;
    }

    public function wake(Environment $env): int
    {
        $apps = Application::where('environment_id', $env->id)->whereNotNull('asleep_at')->get();
        foreach ($apps as $app) {
            $this->containers($app, 'container.start');
            $app->update(['asleep_at' => null, 'woken_at' => now(), 'status' => 'running']);
        }
        $apps->isNotEmpty() && app(TraefikConfigService::class)->push();

        return $apps->count();
    }

    private function containers(Application $app, string $type): void
    {
        foreach (Container::allTenants()->where('application_id', $app->id)->whereNotIn('state', ['removing', 'removed'])->with('server')->get() as $c) {
            if ($c->server?->isOnline()) {
                $this->bus->dispatch($c->server, $type, ['container_id' => $c->name] + ($type === 'container.stop' ? ['timeout' => 30] : []));
            }
        }
    }

    public function present(Environment $env, bool $full = false): array
    {
        $apps = Application::where('environment_id', $env->id)->orderBy('name')->get();

        return [
            'id' => $env->id, 'project_id' => $env->project_id, 'name' => $env->name, 'slug' => $env->slug, 'kind' => $env->kind,
            'branch' => $env->branch, 'requires_approval' => $env->requires_approval, 'sleep_after_minutes' => $env->sleep_after_minutes,
            'duplicated_from_id' => $env->duplicated_from_id,
            'variables' => $full ? (object) ($env->variables ?? []) : array_keys((array) ($env->variables ?? [])),
            'apps' => $apps->map(fn ($a) => [
                'id' => $a->id, 'name' => $a->name, 'service' => $a->service ?: $a->name, 'status' => $a->status, 'asleep' => (bool) $a->asleep_at,
                'image' => $a->image, 'last_deployed_at' => $a->last_deployed_at,
                'url' => ($d = Domain::where('application_id', $a->id)->value('name')) ? 'https://'.$d : null,
            ])->values(),
        ];
    }
}
